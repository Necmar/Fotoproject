<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
|
| One Plesk scheduled task runs `php artisan schedule:run` every minute
| (or the /cron/{token} URL when Plesk cannot run PHP scripts).
| Everything below then runs from that single cronjob.
|
*/

// Process queued photos for ~50 seconds, then stop. No permanent worker.
Schedule::command('bora:work')->everyMinute()->withoutOverlapping(5);

// Re-queue photos that made no progress (lost jobs, crashed runs).
Schedule::command('bora:recover-stuck')->everyTenMinutes()->withoutOverlapping();

// Keep the failed_jobs table small.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 14])->daily();
