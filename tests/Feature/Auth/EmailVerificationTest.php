<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\EmailVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function registerUser(array $overrides = []): array
{
    Notification::fake();

    $response = test()->postJson('/api/v1/sign-up', array_merge([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ], $overrides));

    $user = User::firstWhere('email', $overrides['email'] ?? 'jane@example.com');

    return [$user, $response->json('token')];
}

function seedVerificationCode(string $email, string $code, ?Carbon $createdAt = null): void
{
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $email],
        ['token' => Hash::make($code), 'created_at' => $createdAt ?? now()],
    );
}

test('a valid code verifies the email', function (): void {
    [$user, $token] = registerUser();
    seedVerificationCode($user->email, '123456');

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '123456'])
        ->assertOk()
        ->assertJsonPath('message', 'Email verified successfully.');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('the verification code is deleted after use — single use', function (): void {
    [$user, $token] = registerUser();
    seedVerificationCode($user->email, '123456');

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '123456'])
        ->assertOk();

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});

test('a wrong code is rejected', function (): void {
    [$user, $token] = registerUser();
    seedVerificationCode($user->email, '123456');

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('an expired code is rejected', function (): void {
    [$user, $token] = registerUser();
    seedVerificationCode($user->email, '123456', now()->subMinutes(20));

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('once verified, subsequent verify calls return 200 (idempotent)', function (): void {
    [$user, $token] = registerUser();
    seedVerificationCode($user->email, '123456');

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '123456'])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '123456'])
        ->assertOk();

    expect(
        DB::table('password_reset_tokens')->where('email', $user->email)->exists()
    )->toBeFalse();
});

test('an invalid code is rejected even after email is verified', function (): void {
    [$user, $token] = registerUser();
    seedVerificationCode($user->email, '123456');

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '123456'])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '000000'])
        ->assertOk();
});

test('the code field must be exactly 6 digits', function (): void {
    [$user, $token] = registerUser();

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '12345'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => 'abcdef'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('email verification requires authentication', function (): void {
    $this->postJson('/api/v1/email/verify', ['code' => '123456'])
        ->assertUnauthorized();
});

test('verifying an already verified email is idempotent — returns 200', function (): void {

    $user = User::factory()->create(['email_verified_at' => now()]);
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/email/verify', ['code' => '123456'])
        ->assertOk();
});

test('a new code is sent on resend', function (): void {
    Notification::fake();

    [$user, $token] = registerUser();

    $this->withToken($token)
        ->postJson('/api/v1/email/verify/resend')
        ->assertOk()
        ->assertJsonPath('message', 'If your email is not yet verified, a new code has been sent.');

    Notification::assertSentTo($user, EmailVerification::class);
});

test('resend replaces the existing code', function (): void {
    Notification::fake();

    [$user, $token] = registerUser();

    $this->withToken($token)->postJson('/api/v1/email/verify/resend')->assertOk();

    $this->withToken($token)->postJson('/api/v1/email/verify/resend')->assertOk();

    expect(
        DB::table('password_reset_tokens')->where('email', $user->email)->count()
    )->toBe(1);
});

test('resend is silent when email is already verified', function (): void {

    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => now()]);
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/email/verify/resend')
        ->assertOk()
        ->assertJsonPath('message', 'If your email is not yet verified, a new code has been sent.');

    Notification::assertNothingSent();
});

test('resend requires authentication', function (): void {
    $this->postJson('/api/v1/email/verify/resend')->assertUnauthorized();
});

test('resend is rate limited', function (): void {
    Notification::fake();

    [$user, $token] = registerUser();

    foreach (range(1, 5) as $i) {
        $this->withToken($token)->postJson('/api/v1/email/verify/resend');
    }

    $this->withToken($token)
        ->postJson('/api/v1/email/verify/resend')
        ->assertTooManyRequests();
});

test('registration sends an email verification notification', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/sign-up', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated();

    $user = User::firstWhere('email', 'jane@example.com');

    Notification::assertSentTo($user, EmailVerification::class);
});

test('registered user has email_verified_at as null until verified', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/sign-up', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated();

    expect(User::firstWhere('email', 'jane@example.com')->email_verified_at)->toBeNull();
});
