<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class RequestEmailChangeDTO
{
    public function __construct(
        public string $newEmail,
        public string $currentPassword,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromRequest(array $validated): self
    {
        return new self(
            newEmail: $validated['email'],
            currentPassword: $validated['current_password'],
        );
    }
}
