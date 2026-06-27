<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

final class InvalidEmailVerificationCodeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The email verification code is invalid or has expired.');
    }
}
