<?php

declare(strict_types=1);

use App\Actions\Auth\DeleteAccountAction;
use App\Models\User;
use App\Notifications\AccountDeletedNotification;
use Illuminate\Support\Facades\Notification;

it('removes the user row from the database', function (): void {
    $user = User::factory()->create();

    (new DeleteAccountAction)->execute($user);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

it('revokes all tokens before deleting the user', function (): void {
    $user = User::factory()->create();
    $user->createToken('mobile');
    $user->createToken('cli');

    (new DeleteAccountAction)->execute($user);

    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'tokenable_type' => User::class,
    ]);
});

it('does not affect tokens from other users', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $other->createToken('other-device');

    (new DeleteAccountAction)->execute($user);

    expect($other->tokens()->count())->toBe(1);
});

it('succeeds even when the user has no tokens', function (): void {

    $user = User::factory()->create();

    expect(fn () => (new DeleteAccountAction)->execute($user))
        ->not->toThrow(Throwable::class);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

it('deletes tokens and user atomically', function (): void {

    $user = User::factory()->create();
    $userId = $user->id;
    $user->createToken('device');

    (new DeleteAccountAction)->execute($user);

    $this->assertDatabaseMissing('users', ['id' => $userId]);
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $userId,
        'tokenable_type' => User::class,
    ]);
});

it('sends AccountDeletedNotification before deleting the user', function (): void {
    Notification::fake();

    $user = User::factory()->create(['name' => 'Jane Doe']);

    (new DeleteAccountAction)->execute($user);

    Notification::assertSentTo($user, AccountDeletedNotification::class);
});

it('AccountDeletedNotification carries the correct user name', function (): void {
    Notification::fake();

    $user = User::factory()->create(['name' => 'Jane Doe']);

    (new DeleteAccountAction)->execute($user);

    Notification::assertSentTo(
        $user,
        AccountDeletedNotification::class,
        fn (AccountDeletedNotification $n): bool => $n->userName === 'Jane Doe',
    );
});

it('sends AccountDeletedNotification exactly once', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    (new DeleteAccountAction)->execute($user);

    Notification::assertSentToTimes($user, AccountDeletedNotification::class, 1);
});

it('does not send AccountDeletedNotification to other users', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $other = User::factory()->create();

    (new DeleteAccountAction)->execute($user);

    Notification::assertNothingSentTo($other, AccountDeletedNotification::class);
});
