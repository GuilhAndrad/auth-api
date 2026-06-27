<?php

// app/Actions/Auth/VerifyEmailAction.php
declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\Auth\InvalidEmailVerificationCodeException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class VerifyEmailAction
{
    public function execute(User $user, string $code): void
    {
        // Idempotente: já verificado não precisa fazer nada.
        if ($user->email_verified_at !== null) {
            return;
        }

        $row = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->where('created_at', '>=', now()->subMinutes(config('auth.passwords.users.expire')))
            ->first();

        $dummyHash = '$2y$04$CwIFGCPQFSZVeKIsBQJNaOxnoGXGYmIBvqnrORxN4wFnGABnFVMmK';
        $hashToCheck = $row->token ?? $dummyHash;

        if (! $row || ! Hash::check($code, $hashToCheck)) {
            throw new InvalidEmailVerificationCodeException;
        }

        $deleted = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->where('token', $row->token)
            ->delete();

        if ($deleted === 0) {
            throw new InvalidEmailVerificationCodeException;
        }

        $user->update(['email_verified_at' => now()]);
    }
}
