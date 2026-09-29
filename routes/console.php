<?php

use App\Services\System\Installer;
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

// Nothing to do before the browser installer has run (no .env, no database yet).
if (! app(Installer::class)->isInstalled()) {
    return;
}

// Process queued photos for ~50 seconds, then stop. No permanent worker.
// After an update (upload or Plesk Git): migrations and caches, automatically (production only).
Schedule::command('bora:deploy')->everyMinute()->withoutOverlapping(10);

Schedule::command('bora:work')->everyMinute()->withoutOverlapping(5);

// Re-queue photos that made no progress (lost jobs, crashed runs).
Schedule::command('bora:recover-stuck')->everyTenMinutes()->withoutOverlapping();

// Keep the failed_jobs table small.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 14])->daily();

// Delete batches past the retention period (default 7 days), empty drafts and temp files.
Schedule::command('bora:cleanup')->dailyAt('03:15')->timezone('Europe/Amsterdam')->withoutOverlapping();

// Expired password reset / invitation tokens.
Schedule::command('auth:clear-resets')->daily();
