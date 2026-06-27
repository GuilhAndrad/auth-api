<?php

declare(strict_types=1);

use App\Actions\Auth\RequestEmailChangeAction;
use App\DTOs\Auth\RequestEmailChangeDTO;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use App\Notifications\EmailChangeVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('saves the pending_email on the user', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);

    expect($user->fresh()->pending_email)->toBe('novo@exemplo.com');
});

it('stores a hashed code in password_reset_tokens keyed by the new email', function (): void {
    // A chave na tabela é o NOVO e-mail, não o atual.
    // Isso permite que o mesmo e-mail atual solicite múltiplas trocas
    // para destinos diferentes sem conflito de chave.
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);

    $row = DB::table('password_reset_tokens')
        ->where('email', 'novo@exemplo.com') // chave = novo e-mail
        ->first();

    expect($row)->not->toBeNull();
});

it('sends the verification notification to the new email address', function (): void {
    // O código é enviado para o NOVO e-mail — prova que o usuário controla
    // o endereço de destino antes de efetuar a troca.
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'atual@exemplo.com',
        'password' => bcrypt('secret'),
    ]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);

    // Verifica que a notificação foi enviada para o NOVO e-mail.
    // forceFill(['email' => newEmail]) é usado na Action para rotear a notificação.
    Notification::assertSentTo(
        (clone $user)->forceFill(['email' => 'novo@exemplo.com']),
        EmailChangeVerification::class,
    );
});

it('throws InvalidCredentialsException when current password is wrong', function (): void {
    // Exige confirmação de senha antes de iniciar a troca.
    // Sem isso, um session hijacker poderia trocar o e-mail
    // e assumir controle permanente da conta.
    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'senha-errada',
    );

    expect(fn () => (new RequestEmailChangeAction)->execute($user, $dto))
        ->toThrow(InvalidCredentialsException::class);
});

it('does not save pending_email when password is wrong', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'senha-errada',
    );

    try {
        (new RequestEmailChangeAction)->execute($user, $dto);
    } catch (InvalidCredentialsException) {
        // esperado
    }

    expect($user->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

it('does not send notification when password is wrong', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'senha-errada',
    );

    try {
        (new RequestEmailChangeAction)->execute($user, $dto);
    } catch (InvalidCredentialsException) {
        // esperado
    }

    Notification::assertNothingSent();
});

it('replaces an existing pending code when a new request is made', function (): void {
    // Usuário pode solicitar uma nova troca antes de confirmar a anterior.
    // O upsert garante que só existe um código por e-mail de destino.
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);
    (new RequestEmailChangeAction)->execute($user, $dto);

    expect(
        DB::table('password_reset_tokens')->where('email', 'novo@exemplo.com')->count()
    )->toBe(1);
});

it('stores the code hashed, never as plain text', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);

    $row = DB::table('password_reset_tokens')
        ->where('email', 'novo@exemplo.com')
        ->first();

    Notification::assertSentTo(
        (clone $user)->forceFill(['email' => 'novo@exemplo.com']),
        EmailChangeVerification::class,
        fn (EmailChangeVerification $n): bool => Hash::check($n->code, $row->token),
    );
});
