<?php

declare(strict_types=1);

use App\Actions\Auth\RequestEmailChangeAction;
use App\DTOs\Auth\RequestEmailChangeDTO;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use App\Notifications\EmailChangeVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('saves the pending_email on the user', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);

    expect($user->fresh()->pending_email)->toBe('novo@exemplo.com');
});

it('stores a hashed code in password_reset_tokens keyed by the new email', function (): void {

    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);

    $row = DB::table('password_reset_tokens')
        ->where('email', 'novo@exemplo.com') // chave = novo e-mail
        ->first();

    expect($row)->not->toBeNull();
});

it('sends the verification notification to the new email address', function (): void {

    Notification::fake();

    $user = User::factory()->create([
        'email' => 'atual@exemplo.com',
        'password' => bcrypt('secret'),
    ]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);

    Notification::assertSentTo(
        (clone $user)->forceFill(['email' => 'novo@exemplo.com']),
        EmailChangeVerification::class,
    );
});

it('throws InvalidCredentialsException when current password is wrong', function (): void {

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'senha-errada',
    );

    expect(fn () => (new RequestEmailChangeAction)->execute($user, $dto))
        ->toThrow(InvalidCredentialsException::class);
});

it('does not save pending_email when password is wrong', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'senha-errada',
    );

    try {
        (new RequestEmailChangeAction)->execute($user, $dto);
    } catch (InvalidCredentialsException) {
        //
    }

    expect($user->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

it('does not send notification when password is wrong', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'senha-errada',
    );

    try {
        (new RequestEmailChangeAction)->execute($user, $dto);
    } catch (InvalidCredentialsException) {
        //
    }

    Notification::assertNothingSent();
});

it('replaces an existing pending code when a new request is made', function (): void {

    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);
    (new RequestEmailChangeAction)->execute($user, $dto);

    expect(
        DB::table('password_reset_tokens')->where('email', 'novo@exemplo.com')->count()
    )->toBe(1);
});

it('stores the code hashed, never as plain text', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $dto = new RequestEmailChangeDTO(
        newEmail: 'novo@exemplo.com',
        currentPassword: 'secret',
    );

    (new RequestEmailChangeAction)->execute($user, $dto);

    $row = DB::table('password_reset_tokens')
        ->where('email', 'novo@exemplo.com')
        ->first();

    Notification::assertSentTo(
        (clone $user)->forceFill(['email' => 'novo@exemplo.com']),
        EmailChangeVerification::class,
        fn (EmailChangeVerification $n): bool => Hash::check($n->code, $row->token),
    );
});
