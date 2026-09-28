<?php

namespace App\Services\Processing;

use App\Enums\ActivityAction;
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
use App\Services\Storage\LocalFiles;
use App\Support\BatchSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes ONE image from start to finish. Called by the queue job (phase 4)
 * or synchronously by `php artisan bora:process-local`.
 *
 * Steps: prepare (if needed) -> analyse -> optimise -> finalise.
 * Phase 5 plugs the OpenAI analysis/edit into the analyse/optimise steps;
 * without AI the local enhancer is used.
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
    ) {}

    public function process(Image $image): Image
    {
        $started = microtime(true);
        $image->loadMissing('batch');
        $settings = BatchSettings::fromArray($image->effectiveSettings());

        try {
            $this->status($image, ImageStatus::Analyzing);

            if (! $image->working_path || ! $this->files->disk()->exists($image->working_path)) {
                $this->preparer->prepare($image);
            }

            $metrics = $image->analysis['local'] ?? $this->analyzeWorking($image);

            $this->status($image, ImageStatus::Processing);
            // Phase 5: OpenAI analysis + edit replace/extend these local corrections.
            $adjustments = $this->enhancer->adjustments($metrics, $settings->strength);

            $this->status($image, ImageStatus::Finalizing);
            $this->renderer->render($image, $settings, adjustments: $adjustments);

            $image->forceFill([
                'status' => ImageStatus::Completed,
                'error_code' => null,
                'error_message' => null,
                'processed_at' => now(),
            ])->save();

            $this->record($image, ProcessingType::Local, ImageProcessingRecord::STATUS_SUCCEEDED, $started, $settings);
            Company::query()->whereKey($image->company_id)->increment('images_processed_total');
            $this->activity->log(ActivityAction::ImageProcessed, $image, ['position' => $image->position], company: $image->company_id);
        } catch (Throwable $e) {
            $this->fail($image, $e, $started, $settings);
        } finally {
            $this->progress->refresh($image->batch);
        }

        return $image;
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
