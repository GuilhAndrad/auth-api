<?php

declare(strict_types=1);

use App\Actions\Auth\LoginUserAction;
use App\DTOs\Auth\LoginDTO;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('returns a token for valid credentials', function (): void {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $dto = new LoginDTO(
        email: $user->email,
        password: 'secret123',
        deviceName: 'phpunit',
    );

    $token = (new LoginUserAction)->execute($dto);

    expect($token->plainTextToken)
        ->toBeString()
        ->not->toBeEmpty();
});

it('throws InvalidCredentialsException for wrong password', function (): void {
    $user = User::factory()->create();

    $dto = new LoginDTO(
        email: $user->email,
        password: 'wrong-password',
        deviceName: 'phpunit',
    );

    expect(fn () => (new LoginUserAction)->execute($dto))
        ->toThrow(InvalidCredentialsException::class);
});

it('throws InvalidCredentialsException for non-existent email', function (): void {

    $dto = new LoginDTO(
        email: 'ghost@example.com',
        password: 'any-password',
        deviceName: 'phpunit',
    );

    expect(fn () => (new LoginUserAction)->execute($dto))
        ->toThrow(InvalidCredentialsException::class);
});

it('executes Hash::check even when user does not exist (timing attack mitigation)', function (): void {

    Hash::spy();

    $dto = new LoginDTO(
        email: 'ghost@example.com',
        password: 'any-password',
        deviceName: 'phpunit',
    );

    try {
        (new LoginUserAction)->execute($dto);
    } catch (InvalidCredentialsException) {
        //
    }

    Hash::shouldHaveReceived('check')->once();
});

it('does not create a token on failed login', function (): void {
    $user = User::factory()->create();

    $dto = new LoginDTO(
        email: $user->email,
        password: 'wrong-password',
        deviceName: 'phpunit',
    );

    try {
        (new LoginUserAction)->execute($dto);
    } catch (InvalidCredentialsException) {
        //
    }

    expect($user->tokens()->count())->toBe(0);
});
