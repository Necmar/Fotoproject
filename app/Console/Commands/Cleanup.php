<?php

namespace App\Console\Commands;

use App\Enums\BatchStatus;
use App\Models\ActivityLog;
use App\Models\Batch;
use App\Services\Storage\BatchDeletionService;
use App\Services\Storage\LocalFiles;
use App\Services\SystemSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily cleanup (scheduler). Removes, after the retention period (default
 * 7 days, set by the Super Admin): originals, working copies, AI results,
 * optimised photos, thumbnails and ZIPs, plus the batch and image rows.
 * Processing records stay (anonymised by the foreign keys) for statistics.
 */
class Cleanup extends Command
{
    protected $signature = 'bora:cleanup {--dry-run : Alleen tonen wat verwijderd zou worden}';

    protected $description = 'Verwijder verlopen batches, lege concepten en tijdelijke bestanden';

    /** Activity logs are kept this long. */
    private const ACTIVITY_LOG_DAYS = 365;

    public function handle(BatchDeletionService $deleter, SystemSettings $settings, LocalFiles $files): int
    {
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($settings->retentionDays());

        $expired = Batch::query()
            // expires_at = start (or creation) + retention; also set again when the Super Admin changes the period.
            ->where(fn ($q) => $q->where('expires_at', '<', now())->orWhere(fn ($q) => $q->whereNull('expires_at')->where('created_at', '<', $cutoff)))
            // A batch still processing is left alone until the next run (max one day extra).
            ->where(fn ($q) => $q->whereNotIn('status', BatchStatus::activeCases())->orWhere('expires_at', '<', now()->subDay()));

        $emptyDrafts = Batch::query()
            ->where('status', BatchStatus::Draft)
            ->where('images_count', 0)
            ->where('created_at', '<', now()->subDay());

        $counts = ['expired' => (clone $expired)->count(), 'empty_drafts' => (clone $emptyDrafts)->count()];

        if (! $dry) {
            foreach ([$expired, $emptyDrafts] as $query) {
                $query->each(function (Batch $batch) use ($deleter) {
                    try {
                        $deleter->delete($batch, reason: 'retention');
                    } catch (Throwable $e) {
                        Log::error('Cleanup could not delete batch', ['batch' => $batch->id, 'error' => $e->getMessage()]);
                    }
                });
            }

            ActivityLog::query()->where('created_at', '<', now()->subDays(self::ACTIVITY_LOG_DAYS))->delete();
        }

        $counts['temp_files'] = $this->cleanTemp($files->tempDirectory(), $dry);

        $this->info(($dry ? '[dry-run] ' : '').sprintf(
            'Verlopen batches: %d, lege concepten: %d, tijdelijke bestanden: %d',
            $counts['expired'], $counts['empty_drafts'], $counts['temp_files'],
        ));

        return self::SUCCESS;
    }

    /** Temp files older than a day (left behind by crashed runs). */
    private function cleanTemp(string $dir, bool $dry): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isFile() && $file->getMTime() < now()->subDay()->getTimestamp()) {
                $count++;
                $dry || @unlink($file->getPathname());
            }
        }

        return $count;
    }
}
