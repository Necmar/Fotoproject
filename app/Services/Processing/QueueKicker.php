<?php

namespace App\Services\Processing;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts processing right away from a web request, instead of waiting up to a
 * minute for the next cron run: after the response has been sent to the
 * browser (PHP-FPM), this request runs a short worker. The browser does not
 * wait for it and may be closed; the cron keeps going in the background.
 *
 * Also a safety net: when the cron seems to have stopped, a page that shows
 * progress keeps the queue moving.
 */
class QueueKicker
{
    private bool $scheduled = false;

    /** Seconds without a worker run before the cron is considered stalled. */
    private const STALE_AFTER = 75;

    public function kick(): void
    {
        if ($this->scheduled || ! $this->enabled() || ! $this->hasWaitingJobs()) {
            return;
        }

        $this->scheduled = true;

        app()->terminating(function () {
            ignore_user_abort(true);
            @set_time_limit(0);

            try {
                Artisan::call('bora:work', ['--slot' => 'any', '--max-time' => (int) config('bora.queue.web_max_time')]);
            } catch (Throwable $e) {
                Log::warning('Web-started worker failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /** Only when the cron has not run a worker for a while (safety net for progress polling). */
    public function kickIfStalled(): void
    {
        $last = Cache::get(QueueHealth::HEARTBEAT_KEY);

        if (! $last || now()->diffInSeconds($last, true) > self::STALE_AFTER) {
            $this->kick();
        }
    }

    private function enabled(): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        return match ((string) config('bora.queue.web_kick')) {
            'always' => true,
            'never' => false,
            // The browser must get its answer first; without PHP-FPM it would wait for the worker.
            default => function_exists('fastcgi_finish_request') && ! app()->runningInConsole(),
        };
    }

    private function hasWaitingJobs(): bool
    {
        $connection = config('queue.default');
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return false;
        }

        return DB::table(config("queue.connections.{$connection}.table", 'jobs'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->exists();
    }
}
