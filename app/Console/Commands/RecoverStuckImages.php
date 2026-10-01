<?php

namespace App\Console\Commands;

use App\Enums\BatchStatus;
use App\Enums\ImageStatus;
use App\Jobs\ProcessImage;
use App\Models\Image;
use App\Services\Processing\ImagePipeline;
use Illuminate\Console\Command;

/**
 * Watchdog: images of active batches that made no progress for a while get
 * a new job (the unique lock prevents duplicates while the original job still
 * exists). Images that used up all attempts are marked as failed so the batch
 * can finish.
 */
class RecoverStuckImages extends Command
{
    protected $signature = 'bora:recover-stuck';

    protected $description = 'Zet vastgelopen foto\'s opnieuw in de wachtrij';

    public function handle(ImagePipeline $pipeline): int
    {
        $cutoff = now()->subMinutes((int) config('bora.queue.stuck_after_minutes'));
        $tries = (int) config('bora.queue.tries');
        $requeued = $failed = 0;

        Image::query()
            ->whereHas('batch', fn ($q) => $q->whereIn('status', [BatchStatus::Queued, BatchStatus::Processing]))
            ->whereNotIn('status', [ImageStatus::Completed, ImageStatus::Failed])
            ->where('updated_at', '<', $cutoff)
            ->with('batch')
            // By id, not by offset: updated rows drop out of the query and would make offsets skip some.
            ->lazyById(100)
            ->each(function (Image $image) use ($pipeline, $tries, &$requeued, &$failed) {
                if ($image->attempts >= $tries) {
                    $pipeline->failPermanently($image, new \RuntimeException('No progress after all attempts'));
                    $failed++;

                    return;
                }

                $image->forceFill(['status' => ImageStatus::Queued])->save();
                ProcessImage::dispatch($image->id);
                $requeued++;
            });

        $this->line("Opnieuw in wachtrij: {$requeued}, als mislukt gemarkeerd: {$failed}");

        return self::SUCCESS;
    }
}
