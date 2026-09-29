<?php

namespace App\Services\Processing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells the Super Admin whether the cron-driven queue is actually running.
 * The most common setup mistake on shared hosting is a missing cronjob.
 */
class QueueHealth
{
    public const HEARTBEAT_KEY = 'bora:worker:last_run';

    /** Without a worker run for this long while jobs wait, something is wrong. */
    private const STALE_AFTER_MINUTES = 3;

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $connection = config('queue.default');
        $lastRun = Cache::get(self::HEARTBEAT_KEY);
        $lastRunAt = $lastRun ? Carbon::parse($lastRun) : null;

        $pending = $oldest = $failed24h = null;

        if (config("queue.connections.{$connection}.driver") === 'database') {
            try {
                $table = config("queue.connections.{$connection}.table", 'jobs');
                $pending = DB::table($table)->count();
                $oldestTs = DB::table($table)->min('created_at');
                $oldest = $oldestTs ? Carbon::createFromTimestamp($oldestTs) : null;
                $failed24h = DB::table(config('queue.failed.table', 'failed_jobs'))->where('failed_at', '>=', now()->subDay())->count();
            } catch (Throwable) {
                // Tables missing (not migrated yet): report unknown.
            }
        }

        $stale = $pending > 0 && (! $lastRunAt || $lastRunAt->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)));

        return [
            'connection' => $connection,
            'status' => $stale ? 'stale' : ($pending > 0 ? 'working' : 'idle'),
            'pending_jobs' => $pending,
            'oldest_pending_at' => $oldest?->toIso8601String(),
            'failed_jobs_24h' => $failed24h,
            'last_worker_run_at' => $lastRunAt?->toIso8601String(),
        ];
    }
}
