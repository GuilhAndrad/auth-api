<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\RequestEmailChangeDTO;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use App\Notifications\EmailChangeVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class RequestEmailChangeAction
{
    public function execute(User $user, RequestEmailChangeDTO $dto): void
    {
        if (! Hash::check($dto->currentPassword, $user->password)) {
            throw new InvalidCredentialsException;
        }

        $code = (string) random_int(100_000, 999_999);

        DB::table('password_reset_tokens')->upsert(
            [[
                'email' => $dto->newEmail, // chave é o NOVO e-mail
                'token' => Hash::make($code),
                'created_at' => now(),
            ]],
            uniqueBy: ['email'],
            update: ['token', 'created_at'],
        );

        $user->update(['pending_email' => $dto->newEmail]);

        $user->forceFill(['email' => $dto->newEmail])
            ->notify(new EmailChangeVerification($code));
    }
}
