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
    // Este caso validava um bug anterior onde a Action lançava Error genérico
    // ao tentar acessar ->password em null. Com os dois if separados e o
    // Hash::check dummy, agora lança corretamente InvalidCredentialsException.
    $dto = new LoginDTO(
        email: 'ghost@example.com',
        password: 'any-password',
        deviceName: 'phpunit',
    );

    expect(fn () => (new LoginUserAction)->execute($dto))
        ->toThrow(InvalidCredentialsException::class);
});

it('executes Hash::check even when user does not exist (timing attack mitigation)', function (): void {
    // Valida que a proteção contra timing attack está ativa:
    // o Hash::check com o DUMMY_HASH deve ser chamado quando o user não existe,
    // equiparando o tempo de resposta ao cenário de senha errada.
    // Sem isso, a diferença de latência entre os dois branches entregaria via
    // side-channel quais e-mails estão cadastrados (OWASP A7 — user enumeration).
    Hash::spy();

    $dto = new LoginDTO(
        email: 'ghost@example.com',
        password: 'any-password',
        deviceName: 'phpunit',
    );

    try {
        (new LoginUserAction)->execute($dto);
    } catch (InvalidCredentialsException) {
        // esperado
    }

    // Garante que Hash::check foi chamado mesmo sem user — é o dummy hash sendo verificado.
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
        // esperado
    }

    expect($user->tokens()->count())->toBe(0);
});
