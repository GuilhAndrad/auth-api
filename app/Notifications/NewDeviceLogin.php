<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewDeviceLogin extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public readonly string $deviceName,
        public readonly string $ipAddress,
        public readonly string $loginAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in to your account')
            ->line('We detected a new sign-in to your account.')
            ->line("**Device:** {$this->deviceName}")
            ->line("**IP Address:** {$this->ipAddress}")
            ->line("**Time:** {$this->loginAt}")
            ->line('If this was you, no action is needed.')
            ->line('If you did not sign in, please change your password immediately and revoke all active sessions.')
            ->action('Revoke All Sessions', url('/api/v1/tokens'));
    }
}
