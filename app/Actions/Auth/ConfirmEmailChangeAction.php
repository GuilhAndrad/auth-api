<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\Auth\InvalidEmailVerificationCodeException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ConfirmEmailChangeAction
{
    public function execute(User $user, string $code): void
    {
        if (! $user->pending_email) {
            throw new InvalidEmailVerificationCodeException;
        }

        $row = DB::table('password_reset_tokens')
            ->where('email', $user->pending_email)
            ->where('created_at', '>=', now()->subMinutes(config('auth.passwords.users.expire')))
            ->first();

        $dummyHash = '$2y$04$CwIFGCPQFSZVeKIsBQJNaOxnoGXGYmIBvqnrORxN4wFnGABnFVMmK';
        $hashToCheck = $row->token ?? $dummyHash;

        if (! $row || ! Hash::check($code, $hashToCheck)) {
            throw new InvalidEmailVerificationCodeException;
        }

        $deleted = DB::table('password_reset_tokens')
            ->where('email', $user->pending_email)
            ->where('token', $row->token)
            ->delete();

        if ($deleted === 0) {
            throw new InvalidEmailVerificationCodeException;
        }

        DB::transaction(function () use ($user): void {
            $user->update([
                'email' => $user->pending_email,
                'pending_email' => null,
                'email_verified_at' => now(),
            ]);

            $user->tokens()->delete();
        });
    }
}
