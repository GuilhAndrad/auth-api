<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCode extends Notification implements ShouldQueue
{
    use Queueable;

    // Número máximo de tentativas antes de ir para failed_jobs
    public int $tries = 3;

    // Timeout de cada tentativa em segundos
    public int $timeout = 30;

    // Intervalo entre tentativas em segundos (backoff exponencial)
    // [10s, 30s, 60s] — dá tempo do servidor de e-mail se recuperar
    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    // Janela de tempo em que o job ainda faz sentido ser executado.
    // Código expira em 15 min — não adianta tentar após isso.
    public function retryUntil(): CarbonInterface
    {
        return now()->addMinutes(14);
    }

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $code,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your password reset code'))
            ->markdown('mail.password-reset-code', [
                'code' => $this->code,
                'expiresInMinutes' => config('auth.passwords.users.expire'),
            ]);
    }
}
