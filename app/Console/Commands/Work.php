<?php

namespace App\Console\Commands;

use App\Services\Processing\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Shared-hosting worker: started every minute by the scheduler (Plesk cron),
 * processes queued jobs for at most ~50 seconds and exits. Several run side by
 * side, one per slot (bora.queue.workers); a cache lock per slot makes sure a
 * slot never runs twice, even if cron runs overlap. --slot=any takes the first
 * free slot (used when processing is started from a web request).
 */
class Work extends Command
{
    protected $signature = 'bora:work {--max-time= : Seconden (standaard uit config)} {--slot=1 : Werkplek 1..N, of "any" voor de eerste vrije}';

    protected $description = 'Verwerk de wachtrij kort en stop (voor een cronjob, geen permanente worker nodig)';

    public function handle(): int
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            $this->warn('QUEUE_CONNECTION=sync: jobs worden al direct uitgevoerd.');

            return self::SUCCESS;
        }

        $maxTime = (int) ($this->option('max-time') ?: config('bora.queue.max_time'));
        $timeout = (int) config('bora.queue.job_timeout');
        $lock = $this->acquireSlot((string) $this->option('slot'), $maxTime + $timeout + 30);

        if (! $lock) {
            $this->line('Er draait al een worker op deze plek.');

            return self::SUCCESS;
        }

        try {
            Cache::put(QueueHealth::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());

            Artisan::call('queue:work', [
                'connection' => $connection,
                '--queue' => config('bora.queue.images').',default',
                '--stop-when-empty' => true,
                '--max-time' => $maxTime,
                '--tries' => (int) config('bora.queue.tries'),
                '--timeout' => $timeout,
                '--sleep' => 1,
                '--memory' => 768,
            ], $this->output);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function acquireSlot(string $slot, int $seconds): ?Lock
    {
        $workers = (int) config('bora.queue.workers');
        $slots = $slot === 'any' ? range(1, $workers) : [max(1, (int) $slot)];

        foreach ($slots as $n) {
            $lock = Cache::lock("bora:worker:{$n}", $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
