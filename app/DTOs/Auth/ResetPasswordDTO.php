<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class ResetPasswordDTO
{
    public function __construct(
        public string $email,
        public string $code,
        public string $password,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromRequest(array $validated): self
    {
        return new self(
            email: $validated['email'],
            code: $validated['code'],
            password: $validated['password'],
        );
    }
}
