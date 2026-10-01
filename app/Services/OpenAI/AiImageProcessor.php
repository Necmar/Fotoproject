<?php

namespace App\Services\OpenAI;

use App\Enums\AiStatus;
use App\Enums\BackgroundOption;
use App\Enums\OptimizationStrength;
use App\Enums\ProcessingType;
use App\Models\Image;
use App\Models\ImageProcessingRecord;
use App\Services\Images\DetailRestorer;
use App\Services\Images\ImageEditor;
use App\Services\Images\ImagePreparer;
use App\Services\Images\ProductCompositor;
use App\Services\Storage\LocalFiles;
use App\Services\Storage\StorageAccounting;
use App\Services\SystemSettings;
use App\Support\BatchSettings;
use Illuminate\Support\Str;

/**
 * Orchestrates the AI part for one image:
 *   analyse (once, cached) -> decide -> edit (only when needed) -> verify -> repair.
 * An edit is never thrown away: where the check finds a changed product
 * detail, the original pixels are put back (DetailRestorer).
 * Returns what the renderer should use. Every OpenAI call is recorded with
 * tokens and estimated cost. Throws OpenAIException for the pipeline to
 * retry or fall back.
 */
class AiImageProcessor
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly ImageAnalyzer $analyzer,
        private readonly EditInstructionBuilder $instructions,
        private readonly ImageEditService $editor,
        private readonly EditVerifier $verifier,
        private readonly ImagePreparer $preparer,
        private readonly LocalFiles $files,
        private readonly StorageAccounting $storage,
        private readonly SystemSettings $system,
        private readonly ProductCompositor $compositor,
        private readonly DetailRestorer $restorer,
    ) {}

    public function isActive(): bool
    {
        return $this->client->isConfigured() && (bool) $this->system->get('ai_enabled', true);
    }

    /** Step 1: analysis, stored on the image and reused on retries/re-optimise. */
    public function analyze(Image $image, BatchSettings $settings): array
    {
        if (is_array($image->analysis['ai'] ?? null)) {
            return $image->analysis['ai'];
        }

        $started = microtime(true);

        try {
            ['result' => $result, 'usage' => $usage] = $this->analyzer->analyze($image->working_path, $settings);
        } catch (OpenAIException $e) {
            $this->record($image, ProcessingType::Analysis, $started, null, $e);
            throw $e;
        }

        $this->record($image, ProcessingType::Analysis, $started, $usage);

        $image->forceFill([
            'analysis' => array_replace($image->analysis ?? [], ['ai' => $result]),
            // The AI judgement replaces the rough local warnings.
            'warnings' => $this->preparer->mergeWarnings(
                $this->preparer->mergeWarnings($image->warnings ?? [], 'local', []),
                'ai',
                $this->analysisWarnings($result, $settings),
            ),
            'ai_status' => AiStatus::Analyzed->value,
        ])->save();

        return $result;
    }

    /**
     * Step 2: the product-preserving edit.
     *
     * The image model never delivers the product itself. It makes a cut-out on a
     * flat key colour (from which we take a mask) and, when the background needs
     * real work (tidying, removing distractions or people, a better-looking
     * setting), a version with the improved background. The final photo is the
     * ORIGINAL product, with global light/colour corrections from the analysis,
     * on the chosen background. Without background work: analysis corrections only.
     *
     * Never pays twice for the same edit: every paid result is stored right away
     * (analysis.ai_edit) and a retry of the job only does the missing steps.
     *
     * @return array{source: ?string, adjustments: ?array, focus: ?array, status: AiStatus}
     */
    public function optimize(Image $image, array $analysis, BatchSettings $settings, ProcessingType $type = ProcessingType::Edit): array
    {
        $adjustments = $this->adjustmentsFrom($analysis, $settings->strength);
        $local = ['source' => null, 'adjustments' => $adjustments, 'focus' => $analysis['product_box'] ?? null, 'status' => AiStatus::Analyzed];
        $plan = $this->editPlan($analysis, $settings);

        if (! $plan['retouch'] && ! $plan['mask']) {
            return $local;
        }

        // A previous attempt already paid for the result: reuse it; only a missing check is redone.
        if ($image->ai_path && $this->files->disk()->exists($image->ai_path)) {
            if (($image->analysis['ai_edit']['verified'] ?? true) === false) {
                $rejected = $this->verify($image, $image->ai_path, $settings, null, $local);
                if ($rejected !== null) {
                    return $rejected;
                }
            }
            $this->discardIntermediate($image);

            return $this->resultPlan($image->ai_path, $analysis, $settings);
        }

        $temps = [];
        $mask = null;

        try {
            // 1. The fixed retouch prompt, for every photo (stored at once: a retry reuses it).
            $retouch = $plan['retouch'] ? $this->retouch($image, $analysis, $settings, $plan, $type, $temps) : null;

            if (! $plan['mask']) {
                $diskPath = $retouch;
            } else {
                // 2. New background: the (retouched) product is cut out and placed on it.
                try {
                    $composite = $this->composite($image, $type, $analysis, $settings, $plan, $retouch, $adjustments, $temps);
                } catch (OpenAIException $e) {
                    // Temporary: the queue retries later (the retouch is kept). Otherwise keep the retouch.
                    if ($retouch === null || $e->retryable) {
                        throw $e;
                    }
                    $composite = null;
                }

                if ($composite === null) {
                    if ($retouch === null) {
                        // No usable cut-out (product not found or moved): safe corrections only.
                        $this->addWarnings($image, 'ai_edit', [['code' => 'ai_cutout_failed']]);

                        return ['status' => AiStatus::EditRejected] + $local;
                    }

                    // The paid retouch is never thrown away: the photo on its own background.
                    $this->editState($image, ['notes' => [['code' => 'ai_background_failed']]]);
                    $diskPath = $retouch;
                } else {
                    ['path' => $diskPath, 'mask' => $mask] = $composite;
                }
            }

            $this->storeResult($image, $diskPath);
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
        }

        // 3. Integrity check of the result (a mask could have cut off a part).
        $rejected = $this->verify($image, $diskPath, $settings, $mask, $local);
        if ($rejected !== null) {
            return $rejected;
        }

        $this->discardIntermediate($image);

        return $this->resultPlan($diskPath, $analysis, $settings);
    }

    /**
     * Last resort when OpenAI stays unavailable after all job attempts: a paid
     * edit (final result, or the retouch made before a failed background step)
     * is still used, marked as not checked. Null when nothing was paid for.
     */
    public function salvage(Image $image, array $analysis, BatchSettings $settings): ?array
    {
        $disk = $this->files->disk();
        $state = $image->analysis['ai_edit'] ?? [];
        $notes = $state['notes'] ?? [];

        if ($image->ai_path && $disk->exists($image->ai_path)) {
            $path = $image->ai_path;
        } elseif (($state['retouch'] ?? null) && $disk->exists($state['retouch'])) {
            $path = $state['retouch'];
            $notes[] = ['code' => 'ai_background_failed'];
            $this->storeResult($image, $path);
        } else {
            return null;
        }

        if (($image->analysis['ai_edit']['verified'] ?? true) === false) {
            $notes[] = ['code' => 'ai_edit_unverified'];
        }

        $this->editState($image, ['verified' => ($image->analysis['ai_edit']['verified'] ?? true) === false ? 'failed' : true, 'notes' => $notes]);
        $this->addWarnings($image, 'ai_edit', $notes);
        $this->discardIntermediate($image);

        return $this->resultPlan($path, $analysis, $settings);
    }

    /** The retouch on disk: reused from an earlier attempt, or made now and stored right away. */
    private function retouch(Image $image, array $analysis, BatchSettings $settings, array $plan, ProcessingType $type, array &$temps): string
    {
        $stored = $image->analysis['ai_edit']['retouch'] ?? null;
        if ($stored && $this->files->disk()->exists($stored)) {
            return $stored;
        }

        $tmp = $this->aiEdit($image, $type, $this->instructions->retouch($analysis, $settings, ! $plan['mask']), $temps, lossless: false, quality: (string) config('services.openai.retouch_quality', 'high'));

        // Never larger than the photo itself (no upscaling), exact photo proportions.
        $workingLocal = $this->files->localPath($image->working_path);
        try {
            [$pw, $ph] = getimagesize($workingLocal);
        } finally {
            $this->files->release($workingLocal);
        }
        [$rw, $rh] = getimagesize($tmp);
        $scale = min(1, max($rw, $rh) / max($pw, $ph));
        $tw = (int) round($pw * $scale);
        $th = (int) round($ph * $scale);
        // Already the expected size (the model answers at the requested size): no resample + re-encode.
        if (abs($rw - $tw) > 2 || abs($rh - $th) > 2) {
            ImageEditor::open($tmp)->resize($tw, $th)->saveJpeg($tmp, 95);
        }

        $diskPath = $image->batch->storageDirectory().'/ai/'.Str::ulid()->toBase32().'.jpg';
        $bytes = $this->commit($image, $tmp, $diskPath);
        $this->editState($image, ['retouch' => $diskPath, 'retouch_bytes' => $bytes]);

        return $diskPath;
    }

    /**
     * Cut-out -> mask -> product on the new background, stored on disk.
     * Null when the cut-out is unusable.
     *
     * @return array{path: string, mask: \GdImage}|null
     */
    private function composite(Image $image, ProcessingType $type, array $analysis, BatchSettings $settings, array $plan, ?string $retouch, array $adjustments, array &$temps): ?array
    {
        $source = $retouch ?? $image->working_path;
        $sourceLocal = $this->files->localPath($source);

        try {
            $original = ImageEditor::open($sourceLocal)->fitWithin((int) config('services.openai.composite_max_side', 2560));
        } finally {
            $this->files->release($sourceLocal);
        }
        $w = $original->width();
        $h = $original->height();

        // Cut-out on the key colour least present in this photo -> mask.
        [$keyName, $key] = $this->compositor->keyColourFor($original);
        $cutout = $this->aiEdit($image, $type, $this->instructions->cutout($analysis, $keyName, $key), $temps, source: $source);
        // A model that answers with a transparent PNG anyway: transparent = key colour.
        $cut = ImageEditor::open($cutout)->flatten($key);
        // The model rarely hits the exact key colour or keeps the exact frame: measure both.
        $key = $this->compositor->measuredKey($cut, $key);
        $placement = $this->compositor->register($original, $cut, $key);
        ['mask' => $mask, 'coverage' => $coverage] = $this->compositor->maskFromCutout($cut, $key, $w, $h, $placement);

        $image->forceFill(['analysis' => array_replace($image->analysis ?? [], ['cutout' => [
            'key' => $keyName, 'measured_key' => $key, 'coverage' => round($coverage, 4),
            'placement' => array_map(fn ($v) => round($v, 4), $placement),
            'cutout_size' => [$cut->width(), $cut->height()], 'photo_size' => [$w, $h],
        ]])])->save();

        if ($coverage < 0.01 || $coverage > 0.97 || $placement['error'] > (float) config('services.openai.cutout_max_error', 0.16)) {
            return null;
        }

        // Background layer for the chosen option.
        $product = $retouch ? $original->copy() : $original->copy()->adjust(array_diff_key($adjustments, ['rotate' => true]));
        $background = match (true) {
            $plan['ai_background'] => $this->aiBackground($image, $type, $analysis, $settings, $w, $h, $temps, $source),
            $settings->background === BackgroundOption::BlurLight => $this->compositor->blurred($product, $mask),
            $settings->background === BackgroundOption::Neutral => 'neutral',
            default => null, // remove: transparent (JPG output gets white when saved)
        };
        if ($plan['ai_background'] && $settings->background === BackgroundOption::BlurLight) {
            $background = $this->compositor->blurred(ImageEditor::fromGd($background), $mask);
        }

        // The product itself on the new background.
        $composite = $this->compositor->compose($product, $mask, $background);
        $transparent = $background === null;
        $extension = $transparent ? 'png' : 'jpg';
        $tmp = $this->files->tempPath($extension);
        $temps[] = $tmp;
        $transparent ? $composite->savePng($tmp) : $composite->saveJpeg($tmp, 95);

        $diskPath = $image->batch->storageDirectory().'/ai/'.Str::ulid()->toBase32().'.'.$extension;
        $this->commit($image, $tmp, $diskPath);

        return ['path' => $diskPath, 'mask' => $mask];
    }

    /**
     * Integrity check and repair of the stored result. Null when the result is
     * kept; the fallback plan when it was rejected (only with on_product_change=reject).
     * A temporary check failure is thrown (the edit stays stored, a retry only checks);
     * a permanent one keeps the edit with a note.
     */
    private function verify(Image $image, string $diskPath, BatchSettings $settings, ?\GdImage $mask, array $local): ?array
    {
        $warnings = $image->analysis['ai_edit']['notes'] ?? [];

        if (! config('services.openai.verify_edits')) {
            $this->finishVerification($image, true, $warnings);

            return null;
        }

        $started = microtime(true);

        try {
            $check = $this->verifier->verify($image->working_path, $diskPath, $settings->removePeople);
        } catch (OpenAIException $e) {
            $this->record($image, ProcessingType::Analysis, $started, null, $e);
            if ($e->retryable) {
                throw $e;
            }

            $warnings[] = ['code' => 'ai_edit_unverified'];
            $this->finishVerification($image, 'failed', $warnings);

            return null;
        }

        $this->record($image, ProcessingType::Analysis, $started, $check['usage']);
        $image->forceFill(['analysis' => array_replace($image->analysis ?? [], ['verification' => $check['result']])])->save();

        if (! $check['accepted']) {
            $reason = trim((string) ($check['result'][app()->getLocale() === 'en' ? 'reason_en' : 'reason_nl'] ?? ''));

            if (config('services.openai.on_product_change', 'repair') === 'reject') {
                $this->deleteStored($image, $diskPath);
                $image->forceFill(['ai_path' => null])->save();
                $this->discardIntermediate($image);
                $this->editState($image, null);
                $this->addWarnings($image, 'ai_edit', [$reason !== ''
                    ? ['code' => 'ai_edit_rejected_reason', 'params' => ['reason' => rtrim($reason, '.')]]
                    : ['code' => 'ai_edit_rejected']]);

                return ['status' => AiStatus::EditRejected] + $local;
            }

            // Keep the retouch; put the original details back where the product changed.
            if (! $this->repair($image, $diskPath, $check, $mask)) {
                $warnings[] = $reason !== ''
                    ? ['code' => 'ai_edit_differs_reason', 'params' => ['reason' => rtrim($reason, '.')]]
                    : ['code' => 'ai_edit_differs'];
            }
        }

        if ($check['result']['people_remaining'] ?? false) {
            $warnings[] = ['code' => 'people_not_removed'];
        }

        $this->finishVerification($image, true, $warnings);

        return null;
    }

    private function finishVerification(Image $image, bool|string $verified, array $warnings): void
    {
        $this->editState($image, ['verified' => $verified]);
        $this->addWarnings($image, 'ai_edit', $warnings);
    }

    /** The paid result becomes the image's AI result at once (still to be checked). */
    private function storeResult(Image $image, string $diskPath): void
    {
        $previous = $image->ai_path;
        $image->forceFill(['ai_path' => $diskPath])->save();
        if ($previous && $previous !== $diskPath) {
            $this->deleteStored($image, $previous);
        }

        $state = ['verified' => (bool) config('services.openai.verify_edits') ? false : true];
        // The retouch is the result itself: no longer an intermediate.
        if (($image->analysis['ai_edit']['retouch'] ?? null) === $diskPath) {
            $state['retouch'] = null;
            $state['retouch_bytes'] = null;
        }
        $this->editState($image, $state);
    }

    /** Remove the retouch that was only an input for the new background (once the image is done). */
    private function discardIntermediate(Image $image): void
    {
        $retouch = $image->analysis['ai_edit']['retouch'] ?? null;
        if ($retouch && $retouch !== $image->ai_path) {
            $this->deleteStored($image, $retouch);
        }
        if ($retouch) {
            $this->editState($image, ['retouch' => null, 'retouch_bytes' => null]);
        }
    }

    /** @param array<string, mixed>|null $changes null clears the state */
    private function editState(Image $image, ?array $changes): void
    {
        $analysis = $image->analysis ?? [];
        if ($changes === null) {
            unset($analysis['ai_edit']);
        } else {
            $analysis['ai_edit'] = array_filter(array_replace($analysis['ai_edit'] ?? [], $changes), fn ($v) => $v !== null);
        }
        $image->forceFill(['analysis' => $analysis])->save();
    }

    private function deleteStored(Image $image, string $path): void
    {
        $this->storage->add($image->batch, -$this->size($path));
        $this->files->disk()->delete($path);
    }

    /** What the renderer gets for a finished AI result. */
    private function resultPlan(string $path, array $analysis, BatchSettings $settings): array
    {
        $rotate = $this->adjustmentsFrom($analysis, $settings->strength)['rotate'] ?? 0;

        return ['source' => $path, 'adjustments' => $rotate != 0 ? ['rotate' => $rotate] : null, 'focus' => $analysis['product_box'] ?? null, 'status' => AiStatus::Edited]
            // A retouched photo is already finished; without retouch the local finish is applied.
            + ($this->editPlan($analysis, $settings)['retouch'] ? ['finish' => null] : []);
    }

    /**
     * Original pixels back in the changed regions of the edit.
     * Whether every reported change was covered: a colour or look change
     * without a region cannot be repaired locally.
     */
    private function repair(Image $image, string $diskPath, array $check, ?\GdImage $mask): bool
    {
        $regions = $check['regions'] ?? [];
        $result = $check['result'];
        $restored = 0;

        if ($regions !== []) {
            $editedLocal = $this->files->localPath($diskPath);
            $workingLocal = $this->files->localPath($image->working_path);

            try {
                $edited = ImageEditor::open($editedLocal);
                $restored = $this->restorer->restore($edited, ImageEditor::open($workingLocal), $regions, $mask);

                if ($restored > 0) {
                    $png = str_ends_with($diskPath, '.png');
                    $tmp = $this->files->tempPath($png ? 'png' : 'jpg');
                    $png ? $edited->savePng($tmp) : $edited->saveJpeg($tmp, 95);
                    $this->deleteStored($image, $diskPath);
                    $this->commit($image, $tmp, $diskPath);
                }
            } finally {
                $this->files->release($editedLocal);
                $this->files->release($workingLocal);
            }
        }

        $kinds = array_column($regions, 'kind');
        // Colour or look of the whole product: a region patch does not fix that.
        $uncovered = ($result['product_looks_fake'] ?? false)
            || (($result['product_colour_changed'] ?? false) && ! in_array('colour', $kinds, true));
        $repaired = $restored > 0 && ! $uncovered;

        $image->forceFill(['analysis' => array_replace($image->analysis ?? [], ['repair' => [
            'regions' => $regions, 'restored' => $restored, 'complete' => $repaired,
        ]])])->save();

        return $repaired;
    }

    /**
     * What the image model is needed for.
     *
     * @return array{mask: bool, ai_background: bool, retouch: bool}
     */
    public function editPlan(array $analysis, BatchSettings $settings): array
    {
        $policy = config('services.openai.edit_policy', 'auto');
        if ($policy === 'never') {
            return ['mask' => false, 'ai_background' => false, 'retouch' => false];
        }

        $option = $settings->background;
        $people = $settings->removePeople && ($analysis['people']['present'] ?? false);

        // Every photo gets the fixed retouch. Another background than the
        // original: the retouched product is cut out and placed on it.
        $aiBackground = in_array($option, [BackgroundOption::CleanSubtle, BackgroundOption::RemoveDistractions], true)
            || ($people && in_array($option, [BackgroundOption::BlurLight], true));

        return ['mask' => $option !== BackgroundOption::Keep, 'ai_background' => $aiBackground, 'retouch' => true];
    }

    /** Whether this photo gets any work from the image model. */
    public function needsEdit(array $analysis, BatchSettings $settings): bool
    {
        $plan = $this->editPlan($analysis, $settings);

        return $plan['retouch'] || $plan['mask'];
    }

    /** @param list<string> $temps */
    private function aiEdit(Image $image, ProcessingType $type, string $prompt, array &$temps, bool $lossless = true, ?string $quality = null, ?string $source = null): string
    {
        $started = microtime(true);

        try {
            $edit = $this->editor->edit($source ?? $image->working_path, $prompt, false, $this->files->tempPath('img'), $quality ?: null, lossless: $lossless);
        } catch (OpenAIException $e) {
            $this->record($image, $type, $started, null, $e);
            throw $e;
        }

        $this->record($image, $type, $started, $edit['usage']);
        $temps[] = $edit['path'];

        return $edit['path'];
    }

    /** The whole photo with an improved background from the image model, at the composite size. */
    private function aiBackground(Image $image, ProcessingType $type, array $analysis, BatchSettings $settings, int $w, int $h, array &$temps, ?string $source = null): \GdImage
    {
        $path = $this->aiEdit($image, $type, $this->instructions->build($analysis, $settings, false), $temps, lossless: false, source: $source);

        return ImageEditor::open($path)->resize($w, $h)->gd();
    }

    /** Local corrections derived from the AI analysis (used when no generative edit is made). */
    public function adjustmentsFrom(array $analysis, OptimizationStrength $strength): array
    {
        $k = match ($strength) {
            OptimizationStrength::Subtle => 0.5,
            OptimizationStrength::Normal => 1.0,
            OptimizationStrength::Strong => 1.3,
        };
        $c = $analysis['corrections'] ?? [];
        $clamp = fn (float $v, float $max) => (int) round(max(-$max, min($max, $v)));

        return [
            'gamma' => round(1 - 0.35 * ($c['exposure'] ?? 0) * $k, 3),
            'contrast' => $clamp(-18 * ($c['contrast'] ?? 0) * $k, 25),
            // White balance: small, symmetric shifts only (product colour first).
            'red' => $clamp(10 * ($c['warmth'] ?? 0) * $k, 12),
            'green' => $clamp(-8 * ($c['tint'] ?? 0) * $k, 10),
            'blue' => $clamp(-10 * ($c['warmth'] ?? 0) * $k, 12),
            'denoise' => round(($c['denoise'] ?? 0) * $k, 2),
            'sharpen' => round(min(0.5, (0.08 + 0.3 * ($c['sharpen'] ?? 0)) * $k), 3),
            'rotate' => round((float) ($c['rotate_degrees'] ?? 0), 2),
        ];
    }

    /** @return list<array{code: string, params?: array}> */
    private function analysisWarnings(array $a, BatchSettings $settings): array
    {
        $warnings = [];
        $issues = $a['issues'] ?? [];
        $limited = ! ($a['restorable'] ?? true) || ($a['severity'] ?? '') === 'major';

        if ($limited) {
            foreach (['motion_blur', 'blurry', 'too_dark', 'too_bright', 'noisy', 'low_quality'] as $code) {
                if ($issues[$code] ?? false) {
                    $warnings[] = ['code' => $code === 'blurry' ? 'possibly_blurry' : $code];
                    break; // the most important one is enough
                }
            }
        }

        if ($settings->removePeople && ($a['people']['overlaps_product'] ?? false)) {
            $warnings[] = ['code' => 'person_overlaps_product'];
        }

        return $warnings;
    }

    private function addWarnings(Image $image, string $source, array $warnings): void
    {
        $image->forceFill(['warnings' => $this->preparer->mergeWarnings($image->warnings ?? [], $source, $warnings)])->save();
    }

    /** Move the downloaded edit into the batch folder and count its size. */
    private function commit(Image $image, string $localTemp, string $diskPath): int
    {
        $target = $this->files->writablePath($diskPath);

        if ($target !== $localTemp && ! @rename($localTemp, $target)) {
            copy($localTemp, $target);
            @unlink($localTemp);
        }

        $bytes = $this->files->commit($target, $diskPath);
        $this->storage->add($image->batch, $bytes);

        return $bytes;
    }

    private function size(string $path): int
    {
        return $this->files->disk()->exists($path) ? (int) $this->files->disk()->size($path) : 0;
    }

    private function record(Image $image, ProcessingType $type, float $started, ?Usage $usage, ?OpenAIException $error = null): void
    {
        ImageProcessingRecord::query()->create([
            'company_id' => $image->company_id,
            'batch_id' => $image->batch_id,
            'image_id' => $image->id,
            'type' => $type,
            'status' => $error ? ImageProcessingRecord::STATUS_FAILED : ImageProcessingRecord::STATUS_SUCCEEDED,
            'provider' => 'openai',
            'model' => $usage?->model ?? ($type === ProcessingType::Analysis ? config('services.openai.analysis_model') : config('services.openai.image_model')),
            'input_tokens' => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
            'estimated_cost_usd' => $usage?->costUsd(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'attempt' => $image->attempts + 1,
            'error_code' => $error?->reason,
            'error_message' => $error ? mb_substr($error->getMessage(), 0, 1000) : null,
        ]);
    }
}
