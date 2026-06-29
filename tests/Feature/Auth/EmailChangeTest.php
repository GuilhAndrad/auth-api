<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\EmailChangeVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

function seedEmailChangeCode(string $newEmail, string $code, ?Carbon $createdAt = null): void
{
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $newEmail],
        ['token' => Hash::make($code), 'created_at' => $createdAt ?? now()],
    );
}

test('a verified user can request an email change', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'atual@example.com',
        'email_verified_at' => now(),
        'password' => bcrypt('secret'),
    ]);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/email', [
        'email' => 'novo@example.com',
        'current_password' => 'secret',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'A verification code has been sent to your new email address.');

    expect($user->fresh()->pending_email)->toBe('novo@example.com')
        ->and($user->fresh()->email)->toBe('atual@example.com');
});

test('the verification code is sent to the NEW email address', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'atual@example.com',
        'email_verified_at' => now(),
        'password' => bcrypt('secret'),
    ]);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/email', [
        'email' => 'novo@example.com',
        'current_password' => 'secret',
    ])->assertOk();

    Notification::assertSentTo(
        (clone $user)->forceFill(['email' => 'novo@example.com']),
        EmailChangeVerification::class,
    );
});

test('wrong current password is rejected', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password' => bcrypt('secret'),
    ]);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/email', [
        'email' => 'novo@example.com',
        'current_password' => 'senha-errada',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    expect($user->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

test('the new email must be unique', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = User::factory()->create([
        'email' => 'atual@example.com',
        'email_verified_at' => now(),
        'password' => bcrypt('secret'),
    ]);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/email', [
        'email' => 'taken@example.com',
        'current_password' => 'secret',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('the new email cannot be the same as the current one', function (): void {
    $user = User::factory()->create([
        'email' => 'atual@example.com',
        'email_verified_at' => now(),
        'password' => bcrypt('secret'),
    ]);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/email', [
        'email' => 'atual@example.com',
        'current_password' => 'secret',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('the new email is normalized to lowercase', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password' => bcrypt('secret'),
    ]);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/email', [
        'email' => 'Novo@EXAMPLE.com',
        'current_password' => 'secret',
    ])->assertOk();

    expect($user->fresh()->pending_email)->toBe('novo@example.com');
});

test('requesting email change requires authentication', function (): void {
    $this->putJson('/api/v1/user/email', [
        'email' => 'novo@example.com',
        'current_password' => 'secret',
    ])->assertUnauthorized();
});

test('requesting email change requires verified email', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/email', [
        'email' => 'novo@example.com',
        'current_password' => 'password',
    ])->assertForbidden();
});

test('email change request is rate limited', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password' => bcrypt('secret'),
    ]);

    Sanctum::actingAs($user);

    foreach (range(1, 3) as $i) {
        $this->putJson('/api/v1/user/email', [
            'email' => "novo{$i}@example.com",
            'current_password' => 'secret',
        ]);
    }

    $this->putJson('/api/v1/user/email', [
        'email' => 'novo4@example.com',
        'current_password' => 'secret',
    ])->assertTooManyRequests();
});

test('a valid code confirms the email change', function (): void {
    $user = User::factory()->create([
        'email' => 'atual@example.com',
        'email_verified_at' => now(),
    ]);

    $user->update(['pending_email' => 'novo@example.com']);
    seedEmailChangeCode('novo@example.com', '123456');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/email/confirm', ['code' => '123456'])
        ->assertOk()
        ->assertJsonPath('message', 'Email address updated. Please sign in again.');

    $fresh = $user->fresh();
    expect($fresh->email)->toBe('novo@example.com')
        ->and($fresh->pending_email)->toBeNull()
        ->and($fresh->email_verified_at)->not->toBeNull();
});

test('all tokens are revoked after email confirmation', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->update(['pending_email' => 'novo@example.com']);
    seedEmailChangeCode('novo@example.com', '123456');

    $token = $user->createToken('mobile')->plainTextToken;
    $user->createToken('desktop');

    $this->withToken($token)
        ->postJson('/api/v1/user/email/confirm', ['code' => '123456'])
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/user')
        ->assertUnauthorized();
});

test('a wrong confirmation code is rejected', function (): void {
    $user = User::factory()->create([
        'email' => 'atual@example.com',
        'email_verified_at' => now(),
    ]);

    $user->update(['pending_email' => 'novo@example.com']);
    seedEmailChangeCode('novo@example.com', '123456');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/email/confirm', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect($user->fresh()->email)->toBe('atual@example.com');
});

test('an expired confirmation code is rejected', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->update(['pending_email' => 'novo@example.com']);
    seedEmailChangeCode('novo@example.com', '123456', now()->subMinutes(20));

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/email/confirm', ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('a confirmation code cannot be used twice', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->update(['pending_email' => 'novo@example.com']);
    seedEmailChangeCode('novo@example.com', '123456');

    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/user/email/confirm', ['code' => '123456'])
        ->assertOk();

    $newToken = $user->fresh()->createToken('api2')->plainTextToken;

    $this->withToken($newToken)
        ->postJson('/api/v1/user/email/confirm', ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('confirming without a pending email change is rejected', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'pending_email' => null,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/email/confirm', ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('email confirmation requires authentication', function (): void {
    $this->postJson('/api/v1/user/email/confirm', ['code' => '123456'])
        ->assertUnauthorized();
});

test('email confirmation requires verified email', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/email/confirm', ['code' => '123456'])
        ->assertForbidden();
});

test('the code field must be exactly 6 digits', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/user/email/confirm', ['code' => '12345'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->postJson('/api/v1/user/email/confirm', ['code' => 'abcdef'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});
