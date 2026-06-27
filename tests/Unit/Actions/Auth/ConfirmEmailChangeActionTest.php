<?php

declare(strict_types=1);

use App\Actions\Auth\ConfirmEmailChangeAction;
use App\Exceptions\Auth\InvalidEmailVerificationCodeException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Helper local: prepara user com pending_email e código na tabela.
function setupEmailChange(User $user, string $newEmail, string $code, ?Carbon $createdAt = null): void
{
    $user->update(['pending_email' => $newEmail]);

    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $newEmail],
        ['token' => Hash::make($code), 'created_at' => $createdAt ?? now()],
    );
}

it('updates email to the pending_email on success', function (): void {
    $user = User::factory()->create([
        'email' => 'atual@exemplo.com',
        'pending_email' => null,
    ]);

    setupEmailChange($user, 'novo@exemplo.com', '123456');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    expect($user->fresh()->email)->toBe('novo@exemplo.com');
});

it('clears pending_email after confirmation', function (): void {
    $user = User::factory()->create(['email' => 'atual@exemplo.com']);
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    expect($user->fresh()->pending_email)->toBeNull();
});

it('sets email_verified_at after confirmation', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('revokes all tokens after confirmation — re-login required on all devices', function (): void {
    // Trocar o e-mail é equivalente em impacto a trocar a senha:
    // e-mail é fator de autenticação e recuperação de conta.
    // Todos os dispositivos devem re-autenticar com o novo e-mail.
    $user = User::factory()->create();
    setupEmailChange($user, 'novo@exemplo.com', '123456');
    $user->createToken('mobile');
    $user->createToken('desktop');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    expect($user->tokens()->count())->toBe(0);
});

it('deletes the verification code after confirmation — single use', function (): void {
    $user = User::factory()->create();
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    expect(
        DB::table('password_reset_tokens')->where('email', 'novo@exemplo.com')->exists()
    )->toBeFalse();
});

it('throws when code is wrong', function (): void {
    $user = User::factory()->create();
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    expect(fn () => (new ConfirmEmailChangeAction)->execute($user, '000000'))
        ->toThrow(InvalidEmailVerificationCodeException::class);
});

it('throws when code is expired', function (): void {
    $user = User::factory()->create();
    setupEmailChange($user, 'novo@exemplo.com', '123456', now()->subMinutes(20));

    expect(fn () => (new ConfirmEmailChangeAction)->execute($user, '123456'))
        ->toThrow(InvalidEmailVerificationCodeException::class);
});

it('throws when there is no pending_email on the user', function (): void {
    // Usuário sem pending_email não pode confirmar uma troca — não há nada pendente.
    $user = User::factory()->create(['pending_email' => null]);

    expect(fn () => (new ConfirmEmailChangeAction)->execute($user, '123456'))
        ->toThrow(InvalidEmailVerificationCodeException::class);
});

it('does not change email when code is invalid', function (): void {
    $user = User::factory()->create(['email' => 'atual@exemplo.com']);
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    try {
        (new ConfirmEmailChangeAction)->execute($user, '000000');
    } catch (InvalidEmailVerificationCodeException) {
        // esperado
    }

    expect($user->fresh()->email)->toBe('atual@exemplo.com');
});

it('does not revoke tokens when code is invalid', function (): void {
    $user = User::factory()->create();
    setupEmailChange($user, 'novo@exemplo.com', '123456');
    $user->createToken('mobile');

    try {
        (new ConfirmEmailChangeAction)->execute($user, '000000');
    } catch (InvalidEmailVerificationCodeException) {
        // esperado
    }

    expect($user->tokens()->count())->toBe(1);
});

it('prevents replay attack — second use of same code fails', function (): void {
    // O delete atômico garante que o código só pode ser usado uma vez.
    // Simula outra requisição consumindo o código antes desta terminar.
    $user = User::factory()->create(['email' => 'atual@exemplo.com']);
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    DB::table('password_reset_tokens')->where('email', 'novo@exemplo.com')->delete();

    expect(fn () => (new ConfirmEmailChangeAction)->execute($user, '123456'))
        ->toThrow(InvalidEmailVerificationCodeException::class);

    // E-mail não deve ter sido alterado.
    expect($user->fresh()->email)->toBe('atual@exemplo.com');
});

it('performs email update and token revocation atomically', function (): void {
    // Valida atomicidade: após execução bem-sucedida, ambos os efeitos
    // devem ser visíveis simultaneamente — nunca apenas um deles.
    $user = User::factory()->create(['email' => 'atual@exemplo.com']);
    setupEmailChange($user, 'novo@exemplo.com', '123456');
    $user->createToken('mobile');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    $freshUser = $user->fresh();

    expect($freshUser->email)->toBe('novo@exemplo.com')
        ->and($freshUser->pending_email)->toBeNull()
        ->and($freshUser->email_verified_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
});

it('does not affect tokens from other users', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    setupEmailChange($user, 'novo@exemplo.com', '123456');
    $other->createToken('other-device');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    expect($other->tokens()->count())->toBe(1);
});
