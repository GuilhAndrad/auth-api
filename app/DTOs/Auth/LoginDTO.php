<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class LoginDTO
{
    public function __construct(
        public string $email,
        public string $password,
        public string $deviceName,
        public readonly string $ipAddress,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromRequest(array $validated): self
    {
        return new self(
            email: $validated['email'],
            password: $validated['password'],
            deviceName: $validated['device_name'] ?? 'api',
            ipAddress: $validated['ip_address'] ?? 'unknown',
        );
    }
}
