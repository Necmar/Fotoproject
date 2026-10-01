<?php

namespace App\Services\Processing;

use App\Enums\ActivityAction;
use App\Enums\AiStatus;
use App\Enums\ImageStatus;
use App\Enums\ProcessingType;
use App\Exceptions\DomainRuleException;
use App\Models\Company;
use App\Models\Image;
use App\Models\ImageProcessingRecord;
use App\Services\ActivityLogger;
use App\Services\Images\ImageEditor;
use App\Services\Images\ImagePreparer;
use App\Services\Images\LocalEnhancer;
use App\Services\Images\OutputRenderer;
use App\Services\OpenAI\AiImageProcessor;
use App\Services\OpenAI\OpenAIException;
use App\Services\Storage\LocalFiles;
use App\Support\BatchSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes ONE image from start to finish. Called by the queue job (phase 4)
 * or synchronously by `php artisan bora:process-local`.
 *
 * Steps: prepare (if needed) -> analyse -> optimise -> finalise.
 * With AI: OpenAI analysis, then a generative edit only when needed (verified
 * for product integrity), otherwise AI-guided local corrections. Without AI,
 * or when OpenAI is unavailable after all retries: local corrections.
 * A failure never touches other images of the batch.
 */
class ImagePipeline
{
    public function __construct(
        private readonly ImagePreparer $preparer,
        private readonly LocalEnhancer $enhancer,
        private readonly OutputRenderer $renderer,
        private readonly LocalFiles $files,
        private readonly BatchProgress $progress,
        private readonly ActivityLogger $activity,
        private readonly AiImageProcessor $ai,
    ) {}

    /**
     * @param  bool  $finalAttempt  false while the queue may still retry: unexpected
     *                              (possibly temporary) errors are then re-thrown so the job is retried
     *                              with backoff. Rule violations (DomainRuleException) never retry.
     */
    public function process(Image $image, bool $finalAttempt = true): Image
    {
        $started = microtime(true);
        $image->loadMissing('batch');
        $settings = BatchSettings::fromArray($image->effectiveSettings());

        try {
            $this->status($image, ImageStatus::Analyzing);

            if (! $image->working_path || ! $this->files->disk()->exists($image->working_path)) {
                $this->preparer->prepare($image);
            }

            $plan = null;
            $aiStatus = AiStatus::Skipped;

            if ($this->ai->isActive()) {
                try {
                    $analysis = $this->ai->analyze($image, $settings);
                    // The AI's judgement replaces the simple local check (no double or false warnings).
                    $this->setLocalWarnings($image, []);
                    $this->status($image, ImageStatus::Processing);
                    $plan = $this->ai->optimize($image, $analysis, $settings, $image->settings_override ? ProcessingType::Reoptimize : ProcessingType::Edit);
                    $aiStatus = $plan['status'];
                } catch (OpenAIException $e) {
                    // Temporary problem and attempts left: let the queue retry later.
                    if ($e->retryable && ! $finalAttempt) {
                        throw $e;
                    }
                    $aiError = $e;

                    // An edit that was already paid for is still used (marked as not checked).
                    $plan = isset($analysis) ? $this->ai->salvage($image, $analysis, $settings) : null;
                    $aiStatus = $plan['status'] ?? AiStatus::Fallback;
                }

                if ($plan === null && $aiStatus === AiStatus::Fallback) {
                    // Otherwise the photo still gets local corrections (never stuck on AI).
                    // Analysis done but the edit failed: the photo still gets the AI's own corrections.
                    $code = isset($analysis) ? 'ai_edit_unavailable' : 'ai_unavailable';
                    $image->forceFill(['warnings' => $this->preparer->mergeWarnings($image->warnings ?? [], 'ai_edit', [['code' => $code, 'params' => ['reason' => $aiError->reason]]])])->save();
                    if (! isset($analysis)) {
                        $this->setLocalWarnings($image, $this->enhancer->warnings($image->analysis['local'] ?? $this->analyzeWorking($image)));
                    }
                }
            } else {
                // No AI: the local check is all there is.
                $this->setLocalWarnings($image, $this->enhancer->warnings($image->analysis['local'] ?? $this->analyzeWorking($image)));
            }

            if ($plan === null) {
                $this->status($image, ImageStatus::Processing);
                $plan = [
                    'source' => null,
                    'adjustments' => isset($analysis)
                        ? $this->ai->adjustmentsFrom($analysis, $settings->strength)
                        : $this->enhancer->adjustments($image->analysis['local'] ?? $this->analyzeWorking($image), $settings->strength),
                    'focus' => $analysis['product_box'] ?? null,
                ];
            }

            $this->status($image, ImageStatus::Finalizing);
            $this->renderer->render($image, $settings, $plan['source'], $plan['adjustments'], $plan['focus'],
                array_key_exists('finish', $plan) ? $plan['finish'] : $settings->strength);

            $image->forceFill([
                'status' => ImageStatus::Completed,
                'ai_status' => $aiStatus->value,
                'error_code' => null,
                'error_message' => null,
                'processed_at' => now(),
            ])->save();

            $this->record($image, ProcessingType::Local, ImageProcessingRecord::STATUS_SUCCEEDED, $started, $settings);
            Company::query()->whereKey($image->company_id)->increment('images_processed_total');
            $this->activity->log(ActivityAction::ImageProcessed, $image, ['position' => $image->position], company: $image->company_id);
        } catch (Throwable $e) {
            if (! $finalAttempt && ! $e instanceof DomainRuleException) {
                $this->scheduleRetry($image, $e, $started, $settings);

                throw $e;
            }

            $this->fail($image, $e, $started, $settings);
        } finally {
            $this->progress->refresh($image->batch);
        }

        return $image;
    }

    /** Called by the queue when all attempts are used up (e.g. timeouts). */
    public function failPermanently(Image $image, ?Throwable $e = null): void
    {
        $image->loadMissing('batch');

        if ($image->status->isFinished()) {
            return;
        }

        $this->fail($image, $e ?? new \RuntimeException('Job failed'), microtime(true), BatchSettings::fromArray($image->effectiveSettings()));
        $this->progress->refresh($image->batch);
    }

    private function scheduleRetry(Image $image, Throwable $e, float $started, BatchSettings $settings): void
    {
        Log::warning('Image processing attempt failed, will retry', ['image' => $image->id, 'attempt' => $image->attempts + 1, 'error' => $e->getMessage()]);

        $this->record($image, ProcessingType::Local, ImageProcessingRecord::STATUS_FAILED, $started, $settings, 'retrying', $e->getMessage());

        $image->forceFill([
            'status' => ImageStatus::Queued,
            'attempts' => $image->attempts + 1,
        ])->save();
    }

    private function fail(Image $image, Throwable $e, float $started, BatchSettings $settings): void
    {
        $code = $e instanceof DomainRuleException ? $e->errorCode : 'processing_failed';
        $message = $e instanceof DomainRuleException ? $e->getMessage() : __('messages.processing.failed');

        if (! $e instanceof DomainRuleException) {
            Log::error('Image processing failed', ['image' => $image->id, 'error' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);
        }

        $image->forceFill([
            'status' => ImageStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'attempts' => $image->attempts + 1,
        ])->save();

        $this->record($image, ProcessingType::Local, ImageProcessingRecord::STATUS_FAILED, $started, $settings, $code, $e->getMessage());
        Company::query()->whereKey($image->company_id)->increment('images_failed_total');
        $this->activity->log(ActivityAction::ImageFailed, $image, ['position' => $image->position, 'code' => $code], company: $image->company_id);
    }

    /** @param list<array{code: string}> $warnings */
    private function setLocalWarnings(Image $image, array $warnings): void
    {
        $image->forceFill(['warnings' => $this->preparer->mergeWarnings($image->warnings ?? [], 'local', $warnings)])->save();
    }

    private function analyzeWorking(Image $image): array
    {
        $local = $this->files->localPath($image->working_path);

        try {
            return $this->enhancer->analyze(ImageEditor::open($local));
        } finally {
            $this->files->release($local);
        }
    }

    private function status(Image $image, ImageStatus $status): void
    {
        $image->forceFill([
            'status' => $status,
            'processing_started_at' => $image->processing_started_at ?? now(),
        ])->save();

        $this->progress->refresh($image->batch);
    }

    private function record(Image $image, ProcessingType $type, string $status, float $started, BatchSettings $settings, ?string $code = null, ?string $error = null): void
    {
        ImageProcessingRecord::query()->create([
            'company_id' => $image->company_id,
            'batch_id' => $image->batch_id,
            'image_id' => $image->id,
            'type' => $type,
            'status' => $status,
            'provider' => 'local',
            'settings' => $settings->toArray(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'attempt' => $image->attempts + 1,
            'error_code' => $code,
            'error_message' => $error ? mb_substr($error, 0, 1000) : null,
        ]);
    }
}
