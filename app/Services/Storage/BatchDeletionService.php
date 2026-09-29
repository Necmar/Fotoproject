<?php

namespace App\Services\Storage;

use App\Enums\ActivityAction;
use App\Models\Batch;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a batch with all its files (originals, working copies, results,
 * thumbnails, ZIP). Processing records are kept (their batch/image ids are
 * nulled by the foreign keys) so usage statistics survive.
 */
class BatchDeletionService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function delete(Batch $batch, bool $logActivity = true, ?string $reason = null): void
    {
        $disk = Storage::disk(config('bora.disk'));
        $disk->deleteDirectory($batch->storageDirectory());

        DB::transaction(function () use ($batch) {
            $company = $batch->company()->lockForUpdate()->first();

            if ($company) {
                $company->storage_bytes = max(0, $company->storage_bytes - $batch->storage_bytes);
                $company->save();
            }

            $batch->delete();
        });

        if ($logActivity) {
            $this->activity->log(
                ActivityAction::BatchDeleted,
                $batch,
                array_filter(['name' => $batch->name, 'images' => $batch->images_count, 'reason' => $reason]),
                company: $batch->company_id,
            );
        }
    }
}
