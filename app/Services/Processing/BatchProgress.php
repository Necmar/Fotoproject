<?php

namespace App\Services\Processing;

use App\Enums\BatchStatus;
use App\Enums\ImageStatus;
use App\Models\Batch;
use Illuminate\Support\Facades\DB;

/**
 * Derives the batch status from its images. Safe to call after every image
 * (from jobs running one after another via cron).
 */
class BatchProgress
{
    public function __construct(private readonly BatchCompletionNotifier $notifier) {}

    /** @return bool true when the batch just became finished */
    public function refresh(Batch $batch): bool
    {
        $justFinished = DB::transaction(function () use ($batch) {
            /** @var Batch $locked */
            $locked = Batch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === BatchStatus::Draft) {
                return false;
            }

            $counts = $locked->images()->reorder()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $total = (int) $counts->sum();
            $completed = (int) ($counts[ImageStatus::Completed->value] ?? 0);
            $failed = (int) ($counts[ImageStatus::Failed->value] ?? 0);
            $wasFinished = $locked->status->isFinished();

            $status = match (true) {
                $total === 0 => BatchStatus::Failed,
                $completed + $failed < $total => ($completed + $failed > 0 || $this->anyBusy($counts))
                    ? BatchStatus::Processing
                    : BatchStatus::Queued,
                $failed === 0 => BatchStatus::Completed,
                $completed === 0 => BatchStatus::Failed,
                default => BatchStatus::CompletedWithErrors,
            };

            $locked->forceFill([
                'status' => $status,
                'completed_count' => $completed,
                'failed_count' => $failed,
                'completed_at' => $status->isFinished() ? ($locked->completed_at ?? now()) : null,
            ])->save();

            $batch->setRawAttributes($locked->getAttributes(), true);

            return $status->isFinished() && ! $wasFinished;
        });

        if ($justFinished) {
            $this->notifier->notifyOnce($batch);
        }

        return $justFinished;
    }

    private function anyBusy($counts): bool
    {
        foreach ([ImageStatus::Analyzing, ImageStatus::Processing, ImageStatus::Finalizing] as $s) {
            if (($counts[$s->value] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
