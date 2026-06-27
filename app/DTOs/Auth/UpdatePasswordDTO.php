<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class UpdatePasswordDTO
{
    public function __construct(
        public string $currentPassword,
        public string $newPassword,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromRequest(array $validated): self
    {
        return new self(
            currentPassword: $validated['current_password'],
            newPassword: $validated['password'],
        );
    }
}
