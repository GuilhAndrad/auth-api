<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\EmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SendEmailVerificationAction
{
    public function execute(User $user): void
    {
        if ($user->email_verified_at !== null) {
            return;
        }

        $code = (string) random_int(100_000, 999_999);

        DB::table('password_reset_tokens')->upsert(
            [[
                'email' => $user->email,
                'token' => Hash::make($code),
                'created_at' => now(),
            ]],
            uniqueBy: ['email'],
            update: ['token', 'created_at'],
        );

        $user->notify(new EmailVerification($code));
    }
}
