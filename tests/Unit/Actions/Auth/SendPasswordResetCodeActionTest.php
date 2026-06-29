<?php

declare(strict_types=1);

use App\Actions\Auth\SendPasswordResetCodeAction;
use App\Models\User;
use App\Notifications\PasswordResetCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('stores a hashed 6-digit code in password_reset_tokens', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'jane@example.com']);

    (new SendPasswordResetCodeAction)->execute($user->email);

    $row = DB::table('password_reset_tokens')
        ->where('email', 'jane@example.com')
        ->first();

    expect($row)->not->toBeNull();

    Notification::assertSentTo(
        $user,
        PasswordResetCode::class,
        fn (PasswordResetCode $n): bool => strlen($n->code) === 6 && Hash::check($n->code, $row->token),
    );
});

it('sends the notification to the correct user', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'jane@example.com']);

    (new SendPasswordResetCodeAction)->execute($user->email);

    Notification::assertSentTo($user, PasswordResetCode::class);
});

it('silently does nothing for an unknown email (OWASP A7 — user enumeration)', function (): void {

    Notification::fake();

    (new SendPasswordResetCodeAction)->execute('ghost@example.com');

    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('replaces an existing code via upsert — always one row per email', function (): void {

    Notification::fake();

    $user = User::factory()->create(['email' => 'jane@example.com']);

    (new SendPasswordResetCodeAction)->execute($user->email);
    (new SendPasswordResetCodeAction)->execute($user->email);

    expect(
        DB::table('password_reset_tokens')->where('email', $user->email)->count()
    )->toBe(1);
});

it('renews created_at on every new code request (upsert guarantee)', function (): void {

    Notification::fake();

    $user = User::factory()->create(['email' => 'jane@example.com']);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('111111'),
        'created_at' => now()->subMinutes(10),
    ]);

    $before = DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->value('created_at');

    (new SendPasswordResetCodeAction)->execute($user->email);

    $after = DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->value('created_at');

    expect($after)->toBeGreaterThan($before);
});

it('generates a code with exactly 6 digits (never less, never more)', function (): void {

    Notification::fake();

    $user = User::factory()->create();

    (new SendPasswordResetCodeAction)->execute($user->email);

    Notification::assertSentTo(
        $user,
        PasswordResetCode::class,
        fn (PasswordResetCode $n): bool => strlen($n->code) === 6
            && ctype_digit($n->code)
            && (int) $n->code >= 100_000,
    );
});
