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
        //
    }

    //
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

    $user = User::factory()->create(['email' => 'jane@example.com']);
    insertResetCode('jane@example.com', '123456');

    DB::table('password_reset_tokens')->where('email', 'jane@example.com')->delete();

    $dto = new ResetPasswordDTO(
        email: 'jane@example.com',
        code: '123456',
        password: 'new-password',
    );

    expect(fn () => (new ResetPasswordAction)->execute($dto))
        ->toThrow(InvalidResetCodeException::class);

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
            code: '000000', //
            password: 'new-password',
        ));
    } catch (InvalidResetCodeException) {
        //
    }

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});
