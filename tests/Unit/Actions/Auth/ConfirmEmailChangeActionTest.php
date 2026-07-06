<?php

declare(strict_types=1);

use App\Actions\Auth\ConfirmEmailChangeAction;
use App\Exceptions\Auth\InvalidEmailVerificationCodeException;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

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

it('sends EmailChangedNotification to the OLD email address before updating', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'atual@exemplo.com']);
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    Notification::assertSentTo($user, EmailChangedNotification::class);
});

it('EmailChangedNotification carries the old email address', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'atual@exemplo.com']);
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    (new ConfirmEmailChangeAction)->execute($user, '123456');

    Notification::assertSentTo(
        $user,
        EmailChangedNotification::class,
        fn (EmailChangedNotification $n): bool => $n->oldEmail === 'atual@exemplo.com',
    );
});

it('does not send EmailChangedNotification when code is invalid', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'atual@exemplo.com']);
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    try {
        (new ConfirmEmailChangeAction)->execute($user, '000000');
    } catch (InvalidEmailVerificationCodeException) {
        //
    }

    Notification::assertNothingSentTo($user, EmailChangedNotification::class);
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
        //
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
        //
    }

    expect($user->tokens()->count())->toBe(1);
});

it('prevents replay attack — second use of same code fails', function (): void {

    $user = User::factory()->create(['email' => 'atual@exemplo.com']);
    setupEmailChange($user, 'novo@exemplo.com', '123456');

    DB::table('password_reset_tokens')->where('email', 'novo@exemplo.com')->delete();

    expect(fn () => (new ConfirmEmailChangeAction)->execute($user, '123456'))
        ->toThrow(InvalidEmailVerificationCodeException::class);

    expect($user->fresh()->email)->toBe('atual@exemplo.com');
});

it('performs email update and token revocation atomically', function (): void {

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
