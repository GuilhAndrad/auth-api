<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\ResetPasswordDTO;
use App\Exceptions\Auth\InvalidResetCodeException;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ResetPasswordAction
{
    public function execute(ResetPasswordDTO $dto): void
    {
        $row = DB::table('password_reset_tokens')
            ->where('email', $dto->email)
            ->where('created_at', '>=', now()->subMinutes(config('auth.passwords.users.expire')))
            ->first();

        $dummyHash = '$2y$12$dummyhashusedtomitigatetimingattackonresetcode00000000';
        $hashToCheck = $row->token ?? $dummyHash;

        if (! $row || ! Hash::check($dto->code, $hashToCheck)) {
            throw new InvalidResetCodeException;
        }
        $deleted = DB::table('password_reset_tokens')
            ->where('email', $dto->email)
            ->where('token', $row->token)
            ->delete();

        if ($deleted === 0) {
            throw new InvalidResetCodeException;
        }

        DB::transaction(function () use ($dto): void {
            $user = User::query()
                ->select(['id', 'email'])
                ->where('email', $dto->email)
                ->firstOrFail();

            $user->update(['password' => $dto->password]);

            $user->tokens()->delete();

            event(new PasswordReset($user));
        });
    }
}
