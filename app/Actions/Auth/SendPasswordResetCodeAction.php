<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\PasswordResetCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SendPasswordResetCodeAction
{
    public function execute(string $email): void
    {
        $user = User::query()
            ->select(['id', 'name', 'email']) // evita trazer password e outros campos desnecessários
            ->where('email', $email)
            ->first();

        if (! $user) {
            return;
        }

        $code = (string) random_int(100_000, 999_999);

        DB::table('password_reset_tokens')->upsert(
            [['email' => $user->email, 'token' => Hash::make($code), 'created_at' => now()]],
            uniqueBy: ['email'],
            update: ['token', 'created_at'],
        );

        $user->notify(new PasswordResetCode($code));
    }
}
