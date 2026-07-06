<?php

declare(strict_types=1);

use App\Actions\Auth\UpdatePasswordAction;
use App\DTOs\Auth\UpdatePasswordDTO;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('persists the new password hashed', function (): void {
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $currentToken = $user->createToken('current');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'old-password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue();
});

it('revokes every token except the current one', function (): void {
    $user = User::factory()->create();
    $currentToken = $user->createToken('current');
    $user->createToken('mobile');
    $user->createToken('cli');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->first()->name)->toBe('current');
});

it('keeps the current token active so the session is not interrupted', function (): void {

    $user = User::factory()->create();
    $currentToken = $user->createToken('session');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    expect($user->tokens()->first()?->id)->toBe($currentToken->accessToken->id);
});

it('does not delete tokens from other users', function (): void {

    $user = User::factory()->create();
    $other = User::factory()->create();
    $currentToken = $user->createToken('current');
    $other->createToken('other-device');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    expect($other->tokens()->count())->toBe(1);
});

it('executes password update and token revocation atomically', function (): void {

    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $currentToken = $user->createToken('current');
    $user->createToken('other');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'old-password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    $freshUser = $user->fresh();

    expect(Hash::check('new-secret-password', $freshUser->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);
});

it('sends PasswordChangedNotification after successful password update', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $currentToken = $user->createToken('current');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    Notification::assertSentTo($user, PasswordChangedNotification::class);
});

it('sends PasswordChangedNotification exactly once', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $currentToken = $user->createToken('current');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    Notification::assertSentToTimes($user, PasswordChangedNotification::class, 1);
});

it('does not send PasswordChangedNotification to other users', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $other = User::factory()->create();
    $currentToken = $user->createToken('current');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    Notification::assertNothingSentTo($other, PasswordChangedNotification::class);
});
