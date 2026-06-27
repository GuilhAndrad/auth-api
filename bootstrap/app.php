<?php

declare(strict_types=1);

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\InvalidEmailVerificationCodeException;
use App\Exceptions\Auth\InvalidResetCodeException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (InvalidCredentialsException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return;
            }

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        });

        $exceptions->renderable(function (InvalidResetCodeException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return;
            }

            throw ValidationException::withMessages([
                'code' => [__('The reset code is invalid or has expired.')],
            ]);
        });

        $exceptions->renderable(function (InvalidEmailVerificationCodeException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return;
            }

            throw ValidationException::withMessages([
                'code' => [__('The email verification code is invalid or has expired.')],
            ]);
        });
    })->create();
