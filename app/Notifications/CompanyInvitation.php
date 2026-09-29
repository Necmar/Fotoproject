<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when the Super Admin creates a company without setting a password. */
class CompanyInvitation extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = FrontendUrl::to('/welcome/'.$this->token, ['email' => $notifiable->email]);
        $expireDays = (int) ceil(config('auth.passwords.invites.expire') / 1440);

        return (new MailMessage)
            ->subject(__('mail.invitation.subject', ['app' => config('app.name')]))
            ->greeting(__('mail.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.invitation.intro', ['company' => $notifiable->company?->name, 'app' => config('app.name')]))
            ->action(__('mail.invitation.action'), $url)
            ->line(__('mail.invitation.expire', ['days' => $expireDays]))
            ->salutation(__('mail.salutation', ['app' => config('app.name')]));
    }
}
