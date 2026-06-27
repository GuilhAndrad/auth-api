<?php

declare(strict_types=1);

use App\Actions\Auth\VerifyEmailAction;
use App\Exceptions\Auth\InvalidEmailVerificationCodeException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Helper local: insere código de verificação com timestamp configurável.
function insertVerificationCode(string $email, string $code, ?Carbon $createdAt = null): void
{
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $email],
        ['token' => Hash::make($code), 'created_at' => $createdAt ?? now()],
    );
}

it('sets email_verified_at when code is valid', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);
    insertVerificationCode($user->email, '123456');

    (new VerifyEmailAction)->execute($user, '123456');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('deletes the code after successful verification — single use', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);
    insertVerificationCode($user->email, '123456');

    (new VerifyEmailAction)->execute($user, '123456');

    expect(
        DB::table('password_reset_tokens')->where('email', $user->email)->exists()
    )->toBeFalse();
});

it('throws when code is wrong', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);
    insertVerificationCode($user->email, '123456');

    expect(fn () => (new VerifyEmailAction)->execute($user, '000000'))
        ->toThrow(InvalidEmailVerificationCodeException::class);
});

it('throws when code is expired', function (): void {
    // A Action filtra a expiração no SELECT — código expirado não retorna do banco.
    $user = User::factory()->create(['email_verified_at' => null]);
    insertVerificationCode($user->email, '123456', now()->subMinutes(20));

    expect(fn () => (new VerifyEmailAction)->execute($user, '123456'))
        ->toThrow(InvalidEmailVerificationCodeException::class);
});

it('throws when no code exists for the user', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);

    expect(fn () => (new VerifyEmailAction)->execute($user, '123456'))
        ->toThrow(InvalidEmailVerificationCodeException::class);
});

it('does not change email_verified_at when code is invalid', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);
    insertVerificationCode($user->email, '123456');

    try {
        (new VerifyEmailAction)->execute($user, '000000');
    } catch (InvalidEmailVerificationCodeException) {
        // esperado
    }

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('is idempotent — does nothing when email is already verified', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()->subDay()]);

    expect(fn () => (new VerifyEmailAction)->execute($user, '123456'))
        ->not->toThrow(Throwable::class);

    // Verifica que email_verified_at continua preenchido e no passado —
    // a Action não zerou nem atualizou o campo.
    expect($user->fresh()->email_verified_at)
        ->not->toBeNull()
        ->and($user->fresh()->email_verified_at->isPast())->toBeTrue();
});

it('prevents replay attack via atomic delete', function (): void {
    // Valida a proteção contra uso duplo do mesmo código:
    // o delete atômico garante que apenas uma requisição "ganha".
    $user = User::factory()->create(['email_verified_at' => null]);
    insertVerificationCode($user->email, '123456');

    // Simula outra requisição consumindo o código antes desta terminar.
    DB::table('password_reset_tokens')->where('email', $user->email)->delete();

    expect(fn () => (new VerifyEmailAction)->execute($user, '123456'))
        ->toThrow(InvalidEmailVerificationCodeException::class);

    // email_verified_at não deve ter sido setado.
    expect($user->fresh()->email_verified_at)->toBeNull();
});
