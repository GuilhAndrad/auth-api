<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class RegisterDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $deviceName,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromRequest(array $validated): self
    {
        return new self(
            name: $validated['name'],
            email: $validated['email'],
            password: $validated['password'],
            deviceName: $validated['device_name'] ?? 'api',
        );
    }
}
