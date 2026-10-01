<?php

namespace App\Services\Storage;

use App\Enums\ImageStatus;
use App\Models\Batch;
use App\Models\Image;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps only the files the app still needs, so large photos don't fill the disk:
 *  - the uploaded original, a few hours (BORA_TRIM_GRACE_HOURS, 5) after the working copy exists (that copy is what the
 *    before/after view, the AI and the renderer use; the original is never shown);
 *  - the AI result, a few hours after the final photo is rendered from it (re-optimise makes a new one);
 *  - ZIP downloads after BORA_ZIP_HOURS (48) (rebuilt on request in seconds).
 */
class StorageTrimmer
{
    public function __construct(
        private readonly LocalFiles $files,
        private readonly StorageAccounting $storage,
    ) {}

    public function releaseOriginal(Image $image): void
    {
        if (config('bora.storage.keep_originals') || ! $image->original_path || ! $image->working_path) {
            return;
        }
        if (! $this->files->disk()->exists($image->working_path)) {
            return;
        }

        $this->delete($image, $image->original_path);
        $image->forceFill(['original_path' => null])->save();
    }

    /** After the final photo is rendered: the AI result was only its source. */
    public function releaseAiResult(Image $image): void
    {
        if (! $image->ai_path || $image->status !== ImageStatus::Completed || ! $image->optimized_path) {
            return;
        }

        $this->delete($image, $image->ai_path);
        $analysis = $image->analysis ?? [];
        unset($analysis['ai_edit']);
        $image->forceFill(['ai_path' => null, 'analysis' => $analysis])->save();
    }

    /** Old ZIPs: the photos inside are kept, the ZIP is rebuilt when asked for again. */
    public function trimZips(int $olderThanHours, bool $dry = false): int
    {
        $disk = $this->files->disk();
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();
        $count = 0;

        Batch::query()->whereNotNull('zip_generated_at')->where('zip_generated_at', '<', now()->subHours($olderThanHours))
            ->lazyById(100)->each(function (Batch $batch) use ($disk, $cutoff, $dry, &$count) {
                foreach ($disk->files($batch->storageDirectory().'/zip') as $zip) {
                    if ($disk->lastModified($zip) >= $cutoff) {
                        continue;
                    }
                    $count++;
                    if (! $dry) {
                        $this->storage->add($batch, -(int) $disk->size($zip));
                        $disk->delete($zip);
                    }
                }
                if (! $dry) {
                    $batch->forceFill(['zip_path' => null, 'zip_generated_at' => null])->save();
                }
            });

        return $count;
    }

    /**
     * Hourly. Only files older than the grace period: a job that is retried or run
     * twice in that time still finds them (and never pays OpenAI twice).
     */
    public function trimExisting(bool $dry = false): int
    {
        $count = 0;
        $before = now()->subHours(max(1, (int) config('bora.storage.trim_grace_hours', 5)));
        $originals = fn ($q) => $q->whereNotNull('original_path')->whereNotNull('working_path')->where('created_at', '<', $before);
        $aiResults = fn ($q) => $q->whereNotNull('ai_path')->where('status', ImageStatus::Completed)->where('processed_at', '<', $before);

        $query = Image::query()->with('batch')->where(fn ($q) => config('bora.storage.keep_originals')
            ? $q->where($aiResults)
            : $q->where($originals)->orWhere($aiResults));

        $query->lazyById(200)->each(function (Image $image) use ($dry, &$count) {
            $count++;
            if ($dry) {
                return;
            }
            try {
                $this->releaseOriginal($image);
                $this->releaseAiResult($image);
            } catch (Throwable $e) {
                Log::warning('Storage trim failed', ['image' => $image->id, 'error' => $e->getMessage()]);
            }
        });

        return $count;
    }

    private function delete(Image $image, string $path): void
    {
        $disk = $this->files->disk();
        if ($disk->exists($path)) {
            $this->storage->add($image->batch, -(int) $disk->size($path));
            $disk->delete($path);
        }
    }
}
