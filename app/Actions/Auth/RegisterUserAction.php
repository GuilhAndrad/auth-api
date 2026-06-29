<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\RegisterDTO;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

final class RegisterUserAction
{
    public function execute(RegisterDTO $dto): NewAccessToken
    {
        return DB::transaction(function () use ($dto): NewAccessToken {
            $user = User::create([
                'name' => $dto->name,
                'email' => $dto->email,
                'password' => $dto->password,
            ]);

            $token = $user->createToken($dto->deviceName);

            event(new Registered($user));

            return $token;
        });
    }
}
