<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use App\Notifications\NewDeviceLogin;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final class LoginUserAction
{
    private const DUMMY_HASH = '$2y$04$CwIFGCPQFSZVeKIsBQJNaOxnoGXGYmIBvqnrORxN4wFnGABnFVMmK';

    public function execute(LoginDTO $dto): NewAccessToken
    {
        $user = User::query()
            ->where('email', $dto->email)
            ->first();

        if (! $user) {
            Hash::check($dto->password, self::DUMMY_HASH);
            throw new InvalidCredentialsException;
        }

        if (! Hash::check($dto->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        $this->notifyIfNewDevice($user, $dto);

        return $user->createToken($dto->deviceName);
    }

    private function notifyIfNewDevice(User $user, LoginDTO $dto): void
    {

        if ($user->tokens()->count() === 0) {
            return;
        }

        $isKnownDevice = $user->tokens()
            ->where('name', $dto->deviceName)
            ->exists();

        if (! $isKnownDevice) {
            $user->notify(new NewDeviceLogin(
                deviceName: $dto->deviceName,
                ipAddress: $dto->ipAddress,
                loginAt: now()->toDateTimeString(),
            ));
        }
    }
}
