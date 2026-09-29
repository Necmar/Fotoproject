<?php

namespace App\Console\Commands;

use App\Services\Processing\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Shared-hosting worker: started every minute by the scheduler (Plesk cron),
 * processes queued jobs for at most ~50 seconds and exits. A cache lock makes
 * sure only one worker runs at a time, even if cron runs overlap.
 */
class Work extends Command
{
    protected $signature = 'bora:work {--max-time= : Seconden (standaard uit config)}';

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
        $lock = Cache::lock('bora:worker', $maxTime + $timeout + 30);

        if (! $lock->get()) {
            $this->line('Er draait al een worker.');

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
}
