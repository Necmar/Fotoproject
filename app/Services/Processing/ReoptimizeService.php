<?php

namespace App\Services\Processing;

use App\Enums\ImageStatus;
use App\Exceptions\DomainRuleException;
use App\Jobs\ProcessImage;
use App\Models\Image;
use App\Services\Images\ImagePreparer;
use App\Services\Storage\LocalFiles;
use App\Services\Storage\StorageAccounting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Opnieuw optimaliseren" for one photo with new strength/background/people
 * choices. The stored AI analysis is reused (no second analysis call); a
 * previous AI edit is discarded because it was made with other settings.
 * The current result stays visible until the new one replaces it.
 */
class ReoptimizeService
{
    public function __construct(
        private readonly ImagePreparer $preparer,
        private readonly LocalFiles $files,
        private readonly StorageAccounting $storage,
        private readonly BatchProgress $progress,
    ) {}

    /** @param array{strength: string, background: string, remove_people: bool} $choices */
    public function reoptimize(Image $image, array $choices): Image
    {
        $image->loadMissing('batch');

        $daily = (int) app(\App\Services\SystemSettings::class)->get('reoptimize_per_company_per_day', 300);
        $dailyKey = 'reoptimize:company:'.$image->batch->company_id;
        if ($daily > 0 && RateLimiter::tooManyAttempts($dailyKey, $daily)) {
            throw new DomainRuleException('reoptimize_daily_limit', status: 429);
        }

        DB::transaction(function () use ($image, $choices) {
            /** @var Image $locked */
            $locked = Image::query()->whereKey($image->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isFinished()) {
                throw new DomainRuleException('image_busy');
            }

            $max = (int) app(\App\Services\SystemSettings::class)->get('reoptimize_per_image', 0);
            if ($max > 0 && $locked->reoptimize_count >= $max) {
                throw new DomainRuleException('reoptimize_limit', ['max' => $max]);
            }

            $oldAi = $locked->ai_path;

            $locked->forceFill([
                'settings_override' => array_replace($locked->settings_override ?? [], [
                    'strength' => $choices['strength'],
                    'background' => $choices['background'],
                    'remove_people' => (bool) $choices['remove_people'],
                ]),
                'status' => ImageStatus::Queued,
                'ai_path' => null,
                'attempts' => 0,
                'reoptimize_count' => $locked->reoptimize_count + 1,
                'error_code' => null,
                'error_message' => null,
                'processing_started_at' => null,
                // Edit-related warnings belong to the old result; analysis warnings stay.
                'warnings' => $this->preparer->mergeWarnings($locked->warnings ?? [], 'ai_edit', []),
            ])->save();

            if ($oldAi) {
                $bytes = $this->files->disk()->exists($oldAi) ? (int) $this->files->disk()->size($oldAi) : 0;
                $this->files->disk()->delete($oldAi);
                $this->storage->add($image->batch, -$bytes);
            }

            $image->setRawAttributes($locked->getAttributes(), true);
        });

        if ($daily > 0) {
            RateLimiter::hit($dailyKey, 86400);
        }

        $this->progress->refresh($image->batch);
        ProcessImage::dispatch($image->id);

        return $image;
    }
}
