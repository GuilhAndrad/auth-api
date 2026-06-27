<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailVerification extends Notification implements ShouldQueue
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
            ->subject('Verify your email address')
            ->line('Thanks for signing up! Use the code below to verify your email:')
            ->line("**{$this->code}**")
            ->line('This code expires in 15 minutes.')
            ->line('If you did not create an account, ignore this email.');
    }
}
