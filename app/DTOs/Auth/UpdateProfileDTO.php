<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class UpdateProfileDTO
{
    public function __construct(
        public ?string $name,
        public ?string $email,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromRequest(array $validated): self
    {
        return new self(
            name: $validated['name'] ?? null,
            email: $validated['email'] ?? null,
        );
    }
}
