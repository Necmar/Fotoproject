<?php

namespace App\Console\Commands;

use App\Enums\BatchStatus;
use App\Enums\ImageStatus;
use App\Models\Batch;
use App\Services\Processing\ImagePipeline;
use Illuminate\Console\Command;

/**
 * Processes queued batches synchronously with local (non-AI) processing.
 * Handy for testing phase 3 output and as an emergency fallback; normal
 * processing runs via the queue (phase 4).
 */
class ProcessBatchLocally extends Command
{
    protected $signature = 'bora:process-local {batch? : Batch-ID; zonder ID alle batches in de wachtrij}';

    protected $description = 'Verwerk foto\'s van batches in de wachtrij direct, zonder AI';

    public function handle(ImagePipeline $pipeline): int
    {
        $batches = $this->argument('batch')
            ? Batch::query()->whereKey($this->argument('batch'))->get()
            : Batch::query()->whereIn('status', [BatchStatus::Queued, BatchStatus::Processing])->oldest()->get();

        if ($batches->isEmpty()) {
            $this->info('Geen batches om te verwerken.');

            return self::SUCCESS;
        }

        foreach ($batches as $batch) {
            $images = $batch->images()->whereIn('status', [ImageStatus::Queued, ImageStatus::Failed])->get();
            $this->line("Batch {$batch->id} ({$images->count()} foto's)");

            foreach ($images as $image) {
                $pipeline->process($image);
                $this->line(sprintf('  %2d. %-40s %s', $image->position, mb_strimwidth($image->original_filename, 0, 40, '…'), $image->status->value));
            }

            $this->info('  Status: '.$batch->fresh()->status->value);
        }

        return self::SUCCESS;
    }
}
