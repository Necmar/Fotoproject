<?php

namespace App\Notifications;

use App\Models\Batch;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Je afbeeldingen zijn verwerkt" with counts and a button; never with attachments. */
class BatchCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Batch $batch) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->batch->name ?: __('mail.batch_completed.unnamed');

        return (new MailMessage)
            ->subject(__('mail.batch_completed.subject', ['name' => $name]))
            ->greeting(__('mail.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.batch_completed.intro', ['name' => $name]))
            ->line(__('mail.batch_completed.succeeded', ['count' => $this->batch->completed_count]))
            ->line(__('mail.batch_completed.failed', ['count' => $this->batch->failed_count]))
            ->action(__('mail.batch_completed.action'), FrontendUrl::to('/batches/'.$this->batch->id))
            ->line(__('mail.batch_completed.retention', ['date' => $this->batch->expires_at?->locale(app()->getLocale())->isoFormat('D MMMM YYYY')]))
            ->salutation(__('mail.salutation', ['app' => config('app.name')]));
    }
}
