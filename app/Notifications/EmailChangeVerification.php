<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChangeVerification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addMinutes(14);
    }

    public function __construct(
        public readonly string $code,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new email address')
            ->line('You requested an email address change. Use the code below to confirm:')
            ->line("**{$this->code}**")
            ->line('This code expires in 15 minutes and can only be used once.')
            ->line('If you did not request this change, your current email remains active — no action needed.');
    }
}
