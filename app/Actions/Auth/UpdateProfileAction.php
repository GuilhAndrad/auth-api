<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\UpdateProfileDTO;
use App\Models\User;

final class UpdateProfileAction
{
    public function execute(User $user, UpdateProfileDTO $dto): User
    {
        $payload = array_filter(
            ['name' => $dto->name, 'email' => $dto->email],
            fn (mixed $v): bool => $v !== null,
        );

        if (! empty($payload)) {
            $user->update($payload);
        }

        return $user->fresh() ?? throw new \RuntimeException(
            "User [{$user->id}] not found after update — possible concurrent deletion."
        );
    }
}
