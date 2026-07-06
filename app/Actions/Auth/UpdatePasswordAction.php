<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\UpdatePasswordDTO;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class UpdatePasswordAction
{
    public function execute(User $user, UpdatePasswordDTO $dto, PersonalAccessToken $currentToken): void
    {
        DB::transaction(function () use ($user, $dto, $currentToken): void {
            $user->update(['password' => $dto->newPassword]);

            $user->notify(new PasswordChangedNotification);

            $user->tokens()
                ->where('id', '!=', $currentToken->id)
                ->delete();
        });
    }
}
