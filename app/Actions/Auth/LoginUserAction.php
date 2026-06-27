<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final class LoginUserAction
{
    private const DUMMY_HASH = '$2y$04$CwIFGCPQFSZVeKIsBQJNaOxnoGXGYmIBvqnrORxN4wFnGABnFVMmK';

    public function execute(LoginDTO $dto): NewAccessToken
    {
        $user = User::query()
            ->select(['id', 'name', 'email', 'password'])
            ->where('email', $dto->email)
            ->first();

        if (! $user) {
            Hash::check($dto->password, self::DUMMY_HASH);
            throw new InvalidCredentialsException;
        }

        if (! Hash::check($dto->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        return $user->createToken($dto->deviceName);
    }
}
