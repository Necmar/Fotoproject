<?php

namespace App\Services\Processing;

use App\Models\Batch;
use App\Notifications\BatchCompleted;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the "je afbeeldingen zijn verwerkt" e-mail exactly once per batch.
 * notified_at is set atomically first, so two workers finishing at the same
 * moment can never send it twice. A mail problem never breaks processing.
 */
class BatchCompletionNotifier
{
    public function notifyOnce(Batch $batch): void
    {
        $claimed = Batch::query()->whereKey($batch->getKey())->whereNull('notified_at')->update(['notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            $batch = $batch->fresh(['user', 'company.owner']);
            $recipient = $batch->user ?? $batch->company?->owner;
            $recipient?->notify(new BatchCompleted($batch));
        } catch (Throwable $e) {
            Log::warning('Batch completed e-mail failed', ['batch' => $batch->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
