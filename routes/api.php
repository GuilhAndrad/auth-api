<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::middleware('throttle:api')->group(function () {
        Route::post('/sign-up', [AuthController::class, 'signUp']);
    });

    Route::middleware('throttle:login')->group(function () {
        Route::post('/sign-in', [AuthController::class, 'signIn']);
    });

    Route::middleware('throttle:password')->group(function () {
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    // Autenticado mas sem exigir e-mail verificado
    // (verificação e resend precisam de auth, mas não podem exigir o que ainda não existe)
    Route::middleware(['auth:sanctum', 'throttle:password'])->group(function () {
        Route::post('/email/verify', [EmailVerificationController::class, 'verify']);
        Route::post('/email/verify/resend', [EmailVerificationController::class, 'resend']);
    });

    // Autenticado E com e-mail verificado
    Route::middleware(['auth:sanctum', 'throttle:api', EnsureEmailIsVerified::class])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/user', [UserController::class, 'show']);
        Route::put('/user', [UserController::class, 'update']);
        Route::put('/user/password', [UserController::class, 'updatePassword']);

        Route::put('/user/email', [UserController::class, 'requestEmailChange'])
            ->middleware('throttle:email-change');
        Route::post('/user/email/confirm', [UserController::class, 'confirmEmailChange']);
        Route::delete('/user', [UserController::class, 'destroy']);

        Route::get('/tokens', [TokenController::class, 'index']);
        Route::delete('/tokens', [TokenController::class, 'destroyOthers']);
        Route::delete('/tokens/{id}', [TokenController::class, 'destroy']);
    });
});
