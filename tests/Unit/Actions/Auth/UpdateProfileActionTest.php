<?php

declare(strict_types=1);

use App\Actions\Auth\UpdateProfileAction;
use App\DTOs\Auth\UpdateProfileDTO;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('updates name and email when both are provided', function (): void {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    $dto = new UpdateProfileDTO(name: 'New Name', email: 'new@example.com');

    (new UpdateProfileAction)->execute($user, $dto);

    expect($user->fresh())
        ->name->toBe('New Name')
        ->email->toBe('new@example.com');
});

it('updates only the name when email is null', function (): void {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'unchanged@example.com',
    ]);

    $dto = new UpdateProfileDTO(name: 'New Name', email: null);

    (new UpdateProfileAction)->execute($user, $dto);

    expect($user->fresh())
        ->name->toBe('New Name')
        ->email->toBe('unchanged@example.com');
});

it('updates only the email when name is null', function (): void {
    $user = User::factory()->create([
        'name' => 'Unchanged Name',
        'email' => 'old@example.com',
    ]);

    $dto = new UpdateProfileDTO(name: null, email: 'new@example.com');

    (new UpdateProfileAction)->execute($user, $dto);

    expect($user->fresh())
        ->name->toBe('Unchanged Name')
        ->email->toBe('new@example.com');
});

it('returns a fresh user instance reflecting the persisted state', function (): void {
    // Garante que o retorno da Action é o estado real do banco via ->fresh(),
    // não a instância em memória (potencialmente stale).
    $user = User::factory()->create(['name' => 'Old Name']);

    $dto = new UpdateProfileDTO(name: 'New Name', email: null);

    $updated = (new UpdateProfileAction)->execute($user, $dto);

    expect($updated->name)->toBe('New Name');
});

it('does not issue any UPDATE when both dto values are null', function (): void {
    // O guard empty($payload) evita disparar UPDATE users SET updated_at = ?
    // desnecessário quando nenhum campo foi enviado.
    // Verificamos indiretamente: updated_at não deve mudar.
    $user = User::factory()->create([
        'name' => 'Stable Name',
        'email' => 'stable@example.com',
    ]);

    $originalUpdatedAt = $user->updated_at;

    $dto = new UpdateProfileDTO(name: null, email: null);

    (new UpdateProfileAction)->execute($user, $dto);

    expect($user->fresh()->updated_at->eq($originalUpdatedAt))->toBeTrue();
});

it('throws RuntimeException if user is deleted concurrently after update', function (): void {
    // Valida o guard defensivo: fresh() ?? throw RuntimeException.
    // Se o user for deletado entre o update e o fresh() (deleção concorrente),
    // a Action deve falhar explicitamente em vez de retornar null silencioso.
    $user = User::factory()->create(['name' => 'To Be Deleted']);

    $dto = new UpdateProfileDTO(name: 'New Name', email: null);

    // Força deleção concorrente após o update, antes do fresh()
    // Mockamos parcialmente: update ocorre, depois deletamos, depois fresh() retorna null.
    User::saving(function (User $u) use ($user): void {
        if ($u->id === $user->id) {
            // Deleta diretamente no banco sem disparar events do Eloquent
            DB::table('users')->where('id', $user->id)->delete();
        }
    });

    expect(fn () => (new UpdateProfileAction)->execute($user, $dto))
        ->toThrow(RuntimeException::class);
});
