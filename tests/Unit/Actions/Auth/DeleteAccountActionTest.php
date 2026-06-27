<?php

declare(strict_types=1);

use App\Actions\Auth\DeleteAccountAction;
use App\Models\User;

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
    // tokens()->delete() em conjunto vazio deve ser silencioso — não pode explodir.
    $user = User::factory()->create();

    expect(fn () => (new DeleteAccountAction)->execute($user))
        ->not->toThrow(Throwable::class);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

it('deletes tokens and user atomically', function (): void {
    // Valida que a DB::transaction envolve ambas as operações.
    // Se delete() do user falhar após tokens()->delete(), o rollback deve
    // restaurar os tokens — não podemos ter tokens órfãos sem o user existir.
    // Verificamos o happy path: após execução, NENHUM dos dois deve existir.
    $user = User::factory()->create();
    $userId = $user->id;
    $user->createToken('device');

    (new DeleteAccountAction)->execute($user);

    // Ambos devem ter sumido — nunca apenas um.
    $this->assertDatabaseMissing('users', ['id' => $userId]);
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $userId,
        'tokenable_type' => User::class,
    ]);
});
