<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\NewDeviceLogin;
use App\Notifications\WelcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

it('NewDeviceLogin is sent via mail channel', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $user->notify(new NewDeviceLogin(
        deviceName: 'macbook-pro',
        ipAddress: '10.0.0.1',
        loginAt: '2026-01-01 12:00:00',
    ));

    Notification::assertSentTo(
        $user,
        NewDeviceLogin::class,
        fn (NewDeviceLogin $n): bool => in_array('mail', $n->via($user)),
    );
});

it('NewDeviceLogin carries the correct payload', function (): void {
    $notification = new NewDeviceLogin(
        deviceName: 'macbook-pro',
        ipAddress: '192.168.1.1',
        loginAt: '2026-01-01 10:30:00',
    );

    expect($notification->deviceName)->toBe('macbook-pro')
        ->and($notification->ipAddress)->toBe('192.168.1.1')
        ->and($notification->loginAt)->toBe('2026-01-01 10:30:00');
});

it('NewDeviceLogin mail contains device name in body', function (): void {
    $user = User::factory()->make();

    $notification = new NewDeviceLogin(
        deviceName: 'macbook-pro',
        ipAddress: '10.0.0.1',
        loginAt: '2026-01-01 12:00:00',
    );

    $mail = $notification->toMail($user);

    $introLines = collect($mail->introLines);

    expect($introLines->contains(fn ($line): bool => str_contains($line, 'macbook-pro')))->toBeTrue();
});

it('NewDeviceLogin mail contains ip address in body', function (): void {
    $user = User::factory()->make();

    $notification = new NewDeviceLogin(
        deviceName: 'macbook-pro',
        ipAddress: '192.168.1.100',
        loginAt: '2026-01-01 12:00:00',
    );

    $mail = $notification->toMail($user);

    $introLines = collect($mail->introLines);

    expect($introLines->contains(fn ($line): bool => str_contains($line, '192.168.1.100')))->toBeTrue();
});

it('NewDeviceLogin is queueable and implements ShouldQueue', function (): void {
    $notification = new NewDeviceLogin(
        deviceName: 'test',
        ipAddress: '127.0.0.1',
        loginAt: '2026-01-01 00:00:00',
    );

    expect($notification)->toBeInstanceOf(ShouldQueue::class);
});

it('NewDeviceLogin has correct retry configuration', function (): void {
    $notification = new NewDeviceLogin(
        deviceName: 'test',
        ipAddress: '127.0.0.1',
        loginAt: '2026-01-01 00:00:00',
    );

    expect($notification->tries)->toBe(3)
        ->and($notification->timeout)->toBe(30)
        ->and($notification->backoff())->toBe([10, 30, 60]);
});

it('WelcomeNotification is sent via mail channel', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $user->notify(new WelcomeNotification);

    Notification::assertSentTo(
        $user,
        WelcomeNotification::class,
        fn (WelcomeNotification $n): bool => in_array('mail', $n->via($user)),
    );
});

it('WelcomeNotification mail greets the user by name', function (): void {
    $user = User::factory()->make(['name' => 'Jane Doe']);

    $notification = new WelcomeNotification;
    $mail = $notification->toMail($user);

    expect($mail->greeting)->toContain('Jane Doe');
});

it('WelcomeNotification mail subject contains app name', function (): void {
    $user = User::factory()->make();

    $notification = new WelcomeNotification;
    $mail = $notification->toMail($user);

    expect($mail->subject)->toContain(config('app.name'));
});

it('WelcomeNotification is queueable and implements ShouldQueue', function (): void {
    expect(new WelcomeNotification)
        ->toBeInstanceOf(ShouldQueue::class);
});

it('WelcomeNotification has correct retry configuration', function (): void {
    $notification = new WelcomeNotification;

    expect($notification->tries)->toBe(3)
        ->and($notification->timeout)->toBe(30)
        ->and($notification->backoff())->toBe([10, 30, 60]);
});

it('WelcomeNotification is sent on registration via controller', function (): void {
    Notification::fake();

    test()->postJson('/api/v1/sign-up', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated();

    $user = User::firstWhere('email', 'jane@example.com');

    Notification::assertSentTo($user, WelcomeNotification::class);
});

it('WelcomeNotification is sent exactly once per registration', function (): void {
    Notification::fake();

    test()->postJson('/api/v1/sign-up', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated();

    $user = User::firstWhere('email', 'jane@example.com');

    Notification::assertSentToTimes($user, WelcomeNotification::class, 1);
});
