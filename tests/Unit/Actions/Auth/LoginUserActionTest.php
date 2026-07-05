<?php

declare(strict_types=1);

use App\Actions\Auth\LoginUserAction;
use App\DTOs\Auth\LoginDTO;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use App\Notifications\NewDeviceLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function makeLoginAction(string $ip = '127.0.0.1'): LoginUserAction
{
    $dto = Request::create('/', 'POST', server: ['REMOTE_ADDR' => $ip]);

    return new LoginUserAction($dto);
}

it('returns a token for valid credentials', function (): void {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $dto = new LoginDTO(
        email: $user->email,
        password: 'secret123',
        deviceName: 'phpunit',
        ipAddress: '127.0.0.1',
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
        ipAddress: '127.0.0.1',
    );

    expect(fn () => (new LoginUserAction)->execute($dto))
        ->toThrow(InvalidCredentialsException::class);
});

it('throws InvalidCredentialsException for non-existent email', function (): void {

    $dto = new LoginDTO(
        email: 'ghost@example.com',
        password: 'any-password',
        deviceName: 'phpunit',
        ipAddress: '127.0.0.1',
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
        ipAddress: '127.0.0.1',
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
        ipAddress: '127.0.0.1',
    );

    try {
        (new LoginUserAction)->execute($dto);
    } catch (InvalidCredentialsException) {
        //
    }

    expect($user->tokens()->count())->toBe(0);
});
it('does not notify on the very first login — welcome email already covers it', function (): void {

    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new LoginDTO(
        email: $user->email,
        password: 'secret',
        deviceName: 'iphone-15',
        ipAddress: '127.0.0.1',
    );

    makeLoginAction()->execute($dto);

    Notification::assertNothingSentTo($user, NewDeviceLogin::class);
});

it('does not notify when logging in from a known device', function (): void {

    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $previousToken = $user->createToken('iphone-15');
    $previousToken->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();

    $dto = new LoginDTO(
        email: $user->email,
        password: 'secret',
        deviceName: 'iphone-15',
        ipAddress: '127.0.0.1',
    );

    makeLoginAction()->execute($dto);

    Notification::assertNothingSentTo($user, NewDeviceLogin::class);
});

it('notifies when logging in from a new device', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $existingToken = $user->createToken('iphone-15');
    $existingToken->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();

    $dto = new LoginDTO(
        email: $user->email,
        password: 'secret',
        deviceName: 'macbook-pro',
        ipAddress: '192.168.1.100',
    );

    makeLoginAction('192.168.1.100')->execute($dto);

    Notification::assertSentTo($user, NewDeviceLogin::class);
});

it('includes correct device name and ip in the notification', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $existingToken = $user->createToken('iphone-15');
    $existingToken->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();

    $dto = new LoginDTO(
        email: $user->email,
        password: 'secret',
        deviceName: 'macbook-pro',
        ipAddress: '10.0.0.1',
    );

    makeLoginAction('10.0.0.1')->execute($dto);

    Notification::assertSentTo(
        $user,
        NewDeviceLogin::class,
        function (NewDeviceLogin $notification): bool {
            return $notification->deviceName === 'macbook-pro'
                && $notification->ipAddress === '10.0.0.1';
        },
    );
});

it('does not notify other users when a new device is detected', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);
    $other = User::factory()->create();

    $existingToken = $user->createToken('iphone-15');
    $existingToken->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();

    makeLoginAction()->execute(new LoginDTO(
        email: $user->email,
        password: 'secret',
        deviceName: 'macbook-pro',
        ipAddress: '127.0.0.1',
    ));

    Notification::assertNothingSentTo($other, NewDeviceLogin::class);
});

it('does not notify when login fails — no token means no device check', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    try {
        makeLoginAction()->execute(new LoginDTO(
            email: $user->email,
            password: 'wrong',
            deviceName: 'macbook-pro',
            ipAddress: '127.0.0.1',
        ));
    } catch (InvalidCredentialsException) {
        //
    }

    Notification::assertNothingSentTo($user, NewDeviceLogin::class);
});
