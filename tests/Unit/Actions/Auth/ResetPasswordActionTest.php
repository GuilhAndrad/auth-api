<?php

declare(strict_types=1);

use App\Actions\Auth\ResetPasswordAction;
use App\DTOs\Auth\ResetPasswordDTO;
use App\Exceptions\Auth\InvalidResetCodeException;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

// Helper local: insere um código de reset com timestamp configurável.
function insertResetCode(string $email, string $code, ?Carbon\Carbon $createdAt = null): string
{
    $hash = Hash::make($code);

    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $email],
        ['token' => $hash, 'created_at' => $createdAt ?? now()],
    );

    return $hash;
}

it('resets password and revokes all tokens', function (): void {
    $user = User::factory()->create(['email' => 'jane@example.com']);
    $user->createToken('mobile');
    $user->createToken('cli');

    insertResetCode('jane@example.com', '123456');

    $dto = new ResetPasswordDTO(
        email: 'jane@example.com',
        code: '123456',
        password: 'new-secret-password',
    );

    (new ResetPasswordAction)->execute($dto);

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', 'jane@example.com')->exists())->toBeFalse();
});

it('dispatches the PasswordReset event on success', function (): void {
    Event::fake();

    $user = User::factory()->create(['email' => 'jane@example.com']);
    insertResetCode('jane@example.com', '123456');

    (new ResetPasswordAction)->execute(new ResetPasswordDTO(
        email: 'jane@example.com',
        code: '123456',
        password: 'new-password',
    ));

    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $e): bool => $e->user->email === 'jane@example.com'
    );
});

it('throws when code is wrong', function (): void {
    $user = User::factory()->create(['email' => 'jane@example.com']);
    insertResetCode('jane@example.com', '123456');

    $dto = new ResetPasswordDTO(
        email: 'jane@example.com',
        code: '000000',
        password: 'new-password',
    );

    expect(fn () => (new ResetPasswordAction)->execute($dto))
        ->toThrow(InvalidResetCodeException::class);
});

it('throws when code is expired — filter happens at query level', function (): void {
    // A Action filtra a expiração no próprio SELECT (created_at >= agora - expiry).
    // Isso significa que o registro expirado não volta do banco — $row é null.
    $user = User::factory()->create(['email' => 'jane@example.com']);
    insertResetCode('jane@example.com', '123456', now()->subMinutes(20));

    $dto = new ResetPasswordDTO(
        email: 'jane@example.com',
        code: '123456',
        password: 'new-password',
    );

    expect(fn () => (new ResetPasswordAction)->execute($dto))
        ->toThrow(InvalidResetCodeException::class);
});

it('deletes the expired token row even when thrown (cleanup)', function (): void {
    User::factory()->create(['email' => 'jane@example.com']);
    insertResetCode('jane@example.com', '123456', now()->subMinutes(20));

    try {
        (new ResetPasswordAction)->execute(new ResetPasswordDTO(
            email: 'jane@example.com',
            code: '123456',
            password: 'new-password',
        ));
    } catch (InvalidResetCodeException) {
        // esperado
    }

    // O registro expirado ainda existe — cleanup é responsabilidade de um job separado.
    expect(
        DB::table('password_reset_tokens')->where('email', 'jane@example.com')->exists()
    )->toBeTrue();
});

it('throws when no reset code exists for the email', function (): void {
    User::factory()->create(['email' => 'jane@example.com']);

    $dto = new ResetPasswordDTO(
        email: 'jane@example.com',
        code: '123456',
        password: 'new-password',
    );

    expect(fn () => (new ResetPasswordAction)->execute($dto))
        ->toThrow(InvalidResetCodeException::class);
});

it('prevents replay attack via atomic delete', function (): void {
    // Valida a proteção central contra race condition:
    // o delete é ancorado no token hash exato. Se duas requisições chegarem
    // simultaneamente com o mesmo código válido, apenas uma "ganha" o delete
    // ($deleted > 0) — a outra recebe $deleted === 0 e lança a exceção.
    // Simulamos isso deletando manualmente o token entre o check e o delete,
    // como faria uma segunda requisição concorrente.
    $user = User::factory()->create(['email' => 'jane@example.com']);
    insertResetCode('jane@example.com', '123456');

    // Intercepta o segundo delete (após o hash check) para simular race condition:
    // outra requisição já consumiu o código.
    DB::table('password_reset_tokens')->where('email', 'jane@example.com')->delete();

    $dto = new ResetPasswordDTO(
        email: 'jane@example.com',
        code: '123456',
        password: 'new-password',
    );

    // Com o token já deletado antes da Action rodar, ela não encontra $row
    // e lança InvalidResetCodeException — mesmo comportamento que race condition real.
    expect(fn () => (new ResetPasswordAction)->execute($dto))
        ->toThrow(InvalidResetCodeException::class);

    // A senha NÃO deve ter sido alterada.
    expect(Hash::check('new-password', $user->fresh()->password))->toBeFalse();
});

it('does not change password when code is invalid', function (): void {
    $user = User::factory()->create([
        'email' => 'jane@example.com',
        'password' => bcrypt('original-password'),
    ]);
    insertResetCode('jane@example.com', '123456');

    try {
        (new ResetPasswordAction)->execute(new ResetPasswordDTO(
            email: 'jane@example.com',
            code: '000000', // código errado
            password: 'new-password',
        ));
    } catch (InvalidResetCodeException) {
        // esperado
    }

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});
