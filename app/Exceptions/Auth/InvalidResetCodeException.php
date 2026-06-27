<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

final class InvalidResetCodeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The reset code is invalid or has expired.');
    }
}
