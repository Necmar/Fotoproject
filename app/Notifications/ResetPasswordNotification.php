<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Password reset mail, sent via the queue: "forgot password" then answers
 * equally fast for existing and unknown e-mail addresses (no account probing).
 * The link itself is built by ResetPassword::createUrlUsing (AppServiceProvider).
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
