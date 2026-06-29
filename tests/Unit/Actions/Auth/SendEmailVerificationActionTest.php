<?php

declare(strict_types=1);

use App\Actions\Auth\SendEmailVerificationAction;
use App\Models\User;
use App\Notifications\EmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('sends a verification code to the user email', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    (new SendEmailVerificationAction)->execute($user);

    Notification::assertSentTo($user, EmailVerification::class);
});

it('stores a hashed 6-digit code in password_reset_tokens', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    (new SendEmailVerificationAction)->execute($user);

    $row = DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    expect($row)->not->toBeNull();

    Notification::assertSentTo(
        $user,
        EmailVerification::class,
        fn (EmailVerification $n): bool => strlen($n->code) === 6
            && ctype_digit($n->code)
            && Hash::check($n->code, $row->token),
    );
});

it('generates a code between 100000 and 999999 — never starts with zero', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    (new SendEmailVerificationAction)->execute($user);

    Notification::assertSentTo(
        $user,
        EmailVerification::class,
        fn (EmailVerification $n): bool => (int) $n->code >= 100_000,
    );
});

it('does nothing when the email is already verified — idempotent', function (): void {

    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => now()]);

    (new SendEmailVerificationAction)->execute($user);

    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});

it('replaces an existing code via upsert — always one row per email', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    (new SendEmailVerificationAction)->execute($user);
    (new SendEmailVerificationAction)->execute($user);

    expect(
        DB::table('password_reset_tokens')->where('email', $user->email)->count()
    )->toBe(1);
});

it('renews created_at on every resend — prevents instant expiry bug', function (): void {

    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('111111'),
        'created_at' => now()->subMinutes(10),
    ]);

    $before = DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->value('created_at');

    (new SendEmailVerificationAction)->execute($user);

    $after = DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->value('created_at');

    expect($after)->toBeGreaterThan($before);
});
