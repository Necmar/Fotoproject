<?php

namespace App\Services\OpenAI;

use App\Enums\AiStatus;
use App\Enums\OptimizationStrength;
use App\Enums\ProcessingType;
use App\Models\Image;
use App\Models\ImageProcessingRecord;
use App\Services\Images\ImagePreparer;
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
     * Step 2: generative edit when needed, with integrity verification.
     *
     * @return array{source: ?string, adjustments: ?array, focus: ?array, status: AiStatus}
     */
    public function optimize(Image $image, array $analysis, BatchSettings $settings, ProcessingType $type = ProcessingType::Edit): array
    {
        $local = [
            'source' => null,
            'adjustments' => $this->adjustmentsFrom($analysis, $settings->strength),
            'focus' => $analysis['product_box'] ?? null,
            'status' => AiStatus::Analyzed,
        ];

        if (! $this->needsEdit($analysis, $settings)) {
            return $local;
        }

        // A previous attempt already paid for the edit: reuse it.
        if ($image->ai_path && $this->files->disk()->exists($image->ai_path)) {
            return ['source' => $image->ai_path, 'adjustments' => null, 'focus' => $analysis['product_box'] ?? null, 'status' => AiStatus::Edited];
        }

        $transparent = $settings->background->value === 'remove' && $settings->outputFormat->value === 'png';
        $attempts = config('services.openai.retry_rejected_edit') && config('services.openai.verify_edits') ? 2 : 1;
        $warnings = [];

        for ($attempt = 1; ; $attempt++) {
            // The second attempt (after a rejected edit) asks for a more conservative edit.
            $prompt = $this->instructions->build($analysis, $settings, $transparent, conservative: $attempt > 1);
            $started = microtime(true);

            try {
                $edit = $this->editor->edit($image->working_path, $prompt, $transparent, $this->files->tempPath('img'));
            } catch (OpenAIException $e) {
                $this->record($image, $type, $started, null, $e);
                throw $e;
            }

            $this->record($image, $type, $started, $edit['usage']);

            $diskPath = $image->batch->storageDirectory().'/ai/'.Str::ulid()->toBase32().'.'.$edit['extension'];
            $bytes = $this->commit($image, $edit['path'], $diskPath);

            if (! config('services.openai.verify_edits')) {
                break;
            }

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
            $image->forceFill(['analysis' => array_replace($image->analysis ?? [], ['verification' => $check['result'], 'edit_attempts' => $attempt])])->save();

            if ($check['accepted']) {
                if ($check['result']['people_remaining'] ?? false) {
                    $warnings[] = ['code' => 'people_not_removed'];
                }
                break;
            }

            $this->files->disk()->delete($diskPath);
            $this->storage->add($image->batch, -$bytes);

            if ($attempt >= $attempts) {
                $reason = trim((string) ($check['result'][app()->getLocale() === 'en' ? 'reason_en' : 'reason_nl'] ?? ''));
                $this->addWarnings($image, 'ai_edit', [$reason !== ''
                    ? ['code' => 'ai_edit_rejected_reason', 'params' => ['reason' => rtrim($reason, '.')]]
                    : ['code' => 'ai_edit_rejected']]);

                return ['status' => AiStatus::EditRejected] + $local;
            }
        }

        $previous = $image->ai_path;
        $image->forceFill(['ai_path' => $diskPath])->save();
        if ($previous && $previous !== $diskPath) {
            $this->storage->add($image->batch, -$this->size($previous));
            $this->files->disk()->delete($previous);
        }

        $this->addWarnings($image, 'ai_edit', $warnings);

        return ['source' => $diskPath, 'adjustments' => null, 'focus' => $analysis['product_box'] ?? null, 'status' => AiStatus::Edited];
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

    /** Edit policy: only pay for (and risk) a generative edit when it is really needed. */
    public function needsEdit(array $analysis, BatchSettings $settings): bool
    {
        $policy = config('services.openai.edit_policy', 'auto');
        if ($policy === 'never') {
            return false;
        }
        if ($policy === 'always') {
            return true;
        }

        $required = $settings->background->editsBackground()
            || ($settings->removePeople && ($analysis['people']['present'] ?? false));

        if ($required) {
            return true;
        }

        // Unrecoverable photos: a generative edit could invent details. Keep it local.
        if (! ($analysis['restorable'] ?? true)) {
            return false;
        }

        return match ($settings->strength) {
            OptimizationStrength::Subtle => false,
            OptimizationStrength::Normal => (bool) ($analysis['needs_generative_edit'] ?? false),
            OptimizationStrength::Strong => true,
        };
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
