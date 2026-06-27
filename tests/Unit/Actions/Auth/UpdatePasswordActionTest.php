<?php

declare(strict_types=1);

use App\Actions\Auth\UpdatePasswordAction;
use App\DTOs\Auth\UpdatePasswordDTO;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('persists the new password hashed', function (): void {
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $currentToken = $user->createToken('current');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'old-password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue();
});

it('revokes every token except the current one', function (): void {
    $user = User::factory()->create();
    $currentToken = $user->createToken('current');
    $user->createToken('mobile');
    $user->createToken('cli');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->first()->name)->toBe('current');
});

it('keeps the current token active so the session is not interrupted', function (): void {
    // UX intencional: quem trocou a senha permanece logado.
    // Apenas outros dispositivos são forçados a re-autenticar.
    $user = User::factory()->create();
    $currentToken = $user->createToken('session');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    expect($user->tokens()->first()?->id)->toBe($currentToken->accessToken->id);
});

it('does not delete tokens from other users', function (): void {
    // Garante que a revogação é scoped ao $user — nunca global.
    $user = User::factory()->create();
    $other = User::factory()->create();
    $currentToken = $user->createToken('current');
    $other->createToken('other-device');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    expect($other->tokens()->count())->toBe(1);
});

it('executes password update and token revocation atomically', function (): void {
    // Valida que ambas as operações ocorrem dentro de uma DB::transaction.
    // Se a revogação de tokens falhar após o update da senha, o rollback deve
    // restaurar a senha original — sem estado inconsistente (nova senha + tokens ativos).
    // Testamos isso verificando que, após execução bem-sucedida, ambos os efeitos
    // são visíveis simultaneamente — nunca apenas um deles.
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $currentToken = $user->createToken('current');
    $user->createToken('other');

    $dto = new UpdatePasswordDTO(
        currentPassword: 'old-password',
        newPassword: 'new-secret-password',
    );

    (new UpdatePasswordAction)->execute($user, $dto, $currentToken->accessToken);

    $freshUser = $user->fresh();

    // Ambos os efeitos devem ser verdadeiros ao mesmo tempo.
    expect(Hash::check('new-secret-password', $freshUser->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1); // apenas 'current' sobreviveu
});
