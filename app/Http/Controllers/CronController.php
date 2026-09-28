<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Alternative for hosts where a scheduled task cannot run a PHP script:
 * Plesk "Fetch a URL" calls https://domain/cron/{BORA_CRON_TOKEN} every minute.
 * Disabled (404) unless a token is configured.
 */
class CronController extends Controller
{
    public function __invoke(string $token): Response
    {
        $expected = (string) config('bora.queue.cron_token');

        abort_if($expected === '' || strlen($expected) < 24 || ! hash_equals($expected, $token), 404);

        // Keep working even if the cron client disconnects early.
        ignore_user_abort(true);
        @set_time_limit((int) config('bora.queue.max_time') + (int) config('bora.queue.job_timeout') + 30);

        Artisan::call('schedule:run');

        return response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }
}
