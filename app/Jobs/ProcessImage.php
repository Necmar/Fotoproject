<?php

namespace App\Jobs;

use App\Models\Image;
use App\Services\Processing\ImagePipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One job per image. Small by design so it fits in a cron-started worker on
 * shared hosting. Unique per image, so a photo is never processed twice in
 * parallel even if it is queued again by the watchdog.
 */
class ProcessImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    /** Unique lock lifetime; longer than all retries together. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly string $imageId)
    {
        $this->onQueue(config('bora.queue.images'));
        $this->tries = (int) config('bora.queue.tries');
        $this->timeout = (int) config('bora.queue.job_timeout');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('bora.queue.backoff');
    }

    public function uniqueId(): string
    {
        return $this->imageId;
    }

    public function handle(ImagePipeline $pipeline): void
    {
        $image = Image::query()->find($this->imageId);

        // Batch deleted meanwhile, or already done (e.g. job handed out twice).
        if (! $image || $image->status->isFinished()) {
            return;
        }

        $pipeline->process($image, finalAttempt: $this->attempts() >= $this->tries);
    }

    public function failed(?Throwable $exception): void
    {
        $image = Image::query()->find($this->imageId);

        if ($image) {
            app(ImagePipeline::class)->failPermanently($image, $exception);
        }
    }
}
