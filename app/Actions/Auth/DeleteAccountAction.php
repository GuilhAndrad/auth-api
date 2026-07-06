<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\AccountDeletedNotification;
use Illuminate\Support\Facades\DB;

final class DeleteAccountAction
{
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $userName = $user->name;
            $userEmail = $user->email;
            $user->notify(new AccountDeletedNotification(userName: $userName));
            $user->tokens()->delete();
            $user->delete();
        });
    }
}
