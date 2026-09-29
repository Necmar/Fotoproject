<?php

namespace App\Services\OpenAI;

use App\Enums\AiStatus;
use App\Enums\BackgroundOption;
use App\Enums\OptimizationStrength;
use App\Enums\ProcessingType;
use App\Models\Image;
use App\Models\ImageProcessingRecord;
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
 *   analyse (once, cached) -> decide -> edit (only when needed) -> verify.
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
     * @return array{source: ?string, adjustments: ?array, focus: ?array, status: AiStatus}
     */
    public function optimize(Image $image, array $analysis, BatchSettings $settings, ProcessingType $type = ProcessingType::Edit): array
    {
        $adjustments = $this->adjustmentsFrom($analysis, $settings->strength);
        $focus = $analysis['product_box'] ?? null;
        $local = ['source' => null, 'adjustments' => $adjustments, 'focus' => $focus, 'status' => AiStatus::Analyzed];
        $plan = $this->editPlan($analysis, $settings);

        if (! $plan['mask']) {
            return $local;
        }

        $rotateOnly = ($adjustments['rotate'] ?? 0) != 0 ? ['rotate' => $adjustments['rotate']] : null;

        // A previous attempt already paid for the composite: reuse it.
        if ($image->ai_path && $this->files->disk()->exists($image->ai_path)) {
            return ['source' => $image->ai_path, 'adjustments' => $rotateOnly, 'focus' => $focus, 'status' => AiStatus::Edited] + ($plan['retouch'] ? ['finish' => null] : []);
        }

        $workingLocal = $this->files->localPath($image->working_path);
        $temps = [];

        try {
            if ($plan['retouch']) {
                // Whole-photo retouch (the "clean advertisement" look), checked below.
                $tmp = $this->aiEdit($image, $type, $this->instructions->build($analysis, $settings, false), $temps, lossless: false);
                // Never larger than the photo itself (no upscaling), exact photo proportions.
                [$pw, $ph] = getimagesize($workingLocal);
                $retouched = ImageEditor::open($tmp);
                if ($retouched->width() !== $pw || $retouched->height() !== $ph) {
                    $scale = min(1, max($retouched->width(), $retouched->height()) / max($pw, $ph));
                    $retouched->resize((int) round($pw * $scale), (int) round($ph * $scale))->saveJpeg($tmp, 95);
                }
                $extension = 'jpg';
                $diskPath = $image->batch->storageDirectory().'/ai/'.Str::ulid()->toBase32().'.'.$extension;
                $bytes = $this->commit($image, $tmp, $diskPath);
            } else {
                $original = ImageEditor::open($workingLocal)->fitWithin((int) config('services.openai.composite_max_side', 2560));
                $w = $original->width();
                $h = $original->height();

                // 1. Cut-out on the key colour least present in this photo -> mask.
                [$keyName, $key] = $this->compositor->keyColourFor($original);
                $cutout = $this->aiEdit($image, $type, $this->instructions->cutout($analysis, $keyName, $key), $temps);
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
                    // No usable cut-out (product not found or moved): safe corrections only.
                    $this->addWarnings($image, 'ai_edit', [['code' => 'ai_cutout_failed']]);

                    return ['status' => AiStatus::EditRejected] + $local;
                }

                // 2. Background layer for the chosen option.
                $product = $original->copy()->adjust(array_diff_key($adjustments, ['rotate' => true]));
                $background = match (true) {
                    $plan['ai_background'] => $this->aiBackground($image, $type, $analysis, $settings, $w, $h, $temps),
                    $settings->background === BackgroundOption::BlurLight => $this->compositor->blurred($product, $mask),
                    $settings->background === BackgroundOption::Neutral => 'neutral',
                    default => null, // remove: transparent (JPG output gets white when saved)
                };
                if ($plan['ai_background'] && $settings->background === BackgroundOption::BlurLight) {
                    $background = $this->compositor->blurred(ImageEditor::fromGd($background), $mask);
                }

                // 3. Original product on the new background.
                $composite = $this->compositor->compose($product, $mask, $background);
                $transparent = $background === null;
                $extension = $transparent ? 'png' : 'jpg';
                $tmp = $this->files->tempPath($extension);
                $temps[] = $tmp;
                $transparent ? $composite->savePng($tmp) : $composite->saveJpeg($tmp, 95);

                $diskPath = $image->batch->storageDirectory().'/ai/'.Str::ulid()->toBase32().'.'.$extension;
                $bytes = $this->commit($image, $tmp, $diskPath);
            }
        } finally {
            $this->files->release($workingLocal);
            foreach ($temps as $t) {
                @unlink($t);
            }
        }

        // 4. Integrity check of the result (a mask could have cut off a part).
        $warnings = [];
        if (config('services.openai.verify_edits')) {
            $started = microtime(true);

            try {
                $check = $this->verifier->verify($image->working_path, $diskPath, $settings->removePeople);
            } catch (OpenAIException $e) {
                $this->record($image, ProcessingType::Analysis, $started, null, $e);
                $this->files->disk()->delete($diskPath);
                $this->storage->add($image->batch, -$bytes);
                throw $e;
            }

            $this->record($image, ProcessingType::Analysis, $started, $check['usage']);
            $image->forceFill(['analysis' => array_replace($image->analysis ?? [], ['verification' => $check['result']])])->save();

            if (! $check['accepted']) {
                $this->files->disk()->delete($diskPath);
                $this->storage->add($image->batch, -$bytes);
                $reason = trim((string) ($check['result'][app()->getLocale() === 'en' ? 'reason_en' : 'reason_nl'] ?? ''));
                $this->addWarnings($image, 'ai_edit', [$reason !== ''
                    ? ['code' => 'ai_edit_rejected_reason', 'params' => ['reason' => rtrim($reason, '.')]]
                    : ['code' => 'ai_edit_rejected']]);

                return ['status' => AiStatus::EditRejected] + $local;
            }

            if ($check['result']['people_remaining'] ?? false) {
                $warnings[] = ['code' => 'people_not_removed'];
            }
        }

        $previous = $image->ai_path;
        $image->forceFill(['ai_path' => $diskPath])->save();
        if ($previous && $previous !== $diskPath) {
            $this->storage->add($image->batch, -$this->size($previous));
            $this->files->disk()->delete($previous);
        }

        $this->addWarnings($image, 'ai_edit', $warnings);

        // An AI retouch is already finished; a composite still gets the local finish.
        return ['source' => $diskPath, 'adjustments' => $rotateOnly, 'focus' => $focus, 'status' => AiStatus::Edited] + ($plan['retouch'] ? ['finish' => null] : []);
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
        // Unrecoverable photos: no generative work on the setting either.
        $restorable = $analysis['restorable'] ?? true;
        $byStrength = $policy === 'always' || ($restorable && match ($settings->strength) {
            OptimizationStrength::Subtle => false,
            OptimizationStrength::Normal => (bool) ($analysis['needs_generative_edit'] ?? false),
            OptimizationStrength::Strong => true,
        });

        // Original background: one whole-photo retouch for the clean advertisement
        // look (Normal and Strong), verified afterwards. Subtle stays local.
        if ($option === BackgroundOption::Keep && $restorable && ($policy === 'always' || $settings->strength !== OptimizationStrength::Subtle)) {
            return ['mask' => true, 'ai_background' => false, 'retouch' => true];
        }

        $aiBackground = in_array($option, [BackgroundOption::CleanSubtle, BackgroundOption::RemoveDistractions], true)
            || ($people && $option !== BackgroundOption::Remove && $option !== BackgroundOption::Neutral)
            || ($option === BackgroundOption::Keep && $byStrength);

        return ['mask' => $option !== BackgroundOption::Keep || $aiBackground, 'ai_background' => $aiBackground, 'retouch' => false];
    }

    /** Whether this photo gets any work from the image model. */
    public function needsEdit(array $analysis, BatchSettings $settings): bool
    {
        return $this->editPlan($analysis, $settings)['mask'];
    }

    /** @param list<string> $temps */
    private function aiEdit(Image $image, ProcessingType $type, string $prompt, array &$temps, bool $lossless = true): string
    {
        $started = microtime(true);

        try {
            $edit = $this->editor->edit($image->working_path, $prompt, false, $this->files->tempPath('img'), lossless: $lossless);
        } catch (OpenAIException $e) {
            $this->record($image, $type, $started, null, $e);
            throw $e;
        }

        $this->record($image, $type, $started, $edit['usage']);
        $temps[] = $edit['path'];

        return $edit['path'];
    }

    /** The whole photo with an improved background from the image model, at the composite size. */
    private function aiBackground(Image $image, ProcessingType $type, array $analysis, BatchSettings $settings, int $w, int $h, array &$temps): \GdImage
    {
        $path = $this->aiEdit($image, $type, $this->instructions->build($analysis, $settings, false), $temps, lossless: false);

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
