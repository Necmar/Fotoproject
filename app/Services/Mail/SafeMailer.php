<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a mail send that happens inside a user action (invite, verification,
 * reset). A broken SMTP server must not turn the action into a 500 with the
 * server's raw error text: the failure is logged and reported as false.
 */
class SafeMailer
{
    /** @param callable(): mixed $send */
    public function send(callable $send, string $context): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $e) {
            Log::warning('Mail could not be sent', ['context' => $context, 'exception' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return false;
        }
    }
}
