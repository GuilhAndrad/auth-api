<?php

declare(strict_types=1);

use App\Actions\Auth\RegisterUserAction;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

it('creates a user with the correct attributes', function (): void {
    $dto = new RegisterDTO(
        name: 'Jane Doe',
        email: 'jane@example.com',
        password: 'secret-password',
        deviceName: 'iphone-15',
    );

    (new RegisterUserAction)->execute($dto);

    $this->assertDatabaseHas('users', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);
});

it('returns a token for the created user', function (): void {
    $dto = new RegisterDTO(
        name: 'Jane Doe',
        email: 'jane@example.com',
        password: 'secret-password',
        deviceName: 'iphone-15',
    );

    $token = (new RegisterUserAction)->execute($dto);

    expect($token->plainTextToken)
        ->toBeString()
        ->not->toBeEmpty();
});

it('names the token after the device_name', function (): void {
    $dto = new RegisterDTO(
        name: 'Jane Doe',
        email: 'jane@example.com',
        password: 'secret-password',
        deviceName: 'my-device',
    );

    $token = (new RegisterUserAction)->execute($dto);

    expect($token->accessToken->name)->toBe('my-device');
});

it('stores the password hashed, never as plain text', function (): void {
    $dto = new RegisterDTO(
        name: 'Jane Doe',
        email: 'jane@example.com',
        password: 'secret-password',
        deviceName: 'api',
    );

    (new RegisterUserAction)->execute($dto);

    $stored = User::firstWhere('email', 'jane@example.com')?->password;

    expect($stored)
        ->not->toBe('secret-password')
        ->and(Hash::check('secret-password', $stored))->toBeTrue();
});

it('dispatches the Registered event after both user and token are persisted', function (): void {

    Event::fake();

    $dto = new RegisterDTO(
        name: 'Jane Doe',
        email: 'jane@example.com',
        password: 'secret-password',
        deviceName: 'api',
    );

    (new RegisterUserAction)->execute($dto);

    Event::assertDispatched(Registered::class, function (Registered $event): bool {

        return $event->user instanceof User
            && $event->user->email === 'jane@example.com'
            && $event->user->tokens()->count() === 1;
    });
});

it('rolls back user creation if token creation fails', function (): void {

    $this->mock(User::class)
        ->shouldReceive('create')
        ->andReturnSelf()
        ->shouldReceive('createToken')
        ->andThrow(new RuntimeException('Token creation failed'));

    $dto = new RegisterDTO(
        name: 'Jane Doe',
        email: 'jane@example.com',
        password: 'secret-password',
        deviceName: 'api',
    );

    expect(fn () => (new RegisterUserAction)->execute($dto))
        ->not->toThrow(InvalidArgumentException::class);
});
