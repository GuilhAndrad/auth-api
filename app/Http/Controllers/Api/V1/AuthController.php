<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\LoginUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\SendEmailVerificationAction;
use App\Actions\Auth\SendPasswordResetCodeAction;
use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\DTOs\Auth\ResetPasswordDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController extends Controller
{
    /**
     * Sign Up
     *
     * Register a new user and return an access token.
     */
    public function signUp(RegisterRequest $request, RegisterUserAction $register, SendEmailVerificationAction $sendVerification): JsonResponse
    {
        $token = $register->execute(RegisterDTO::fromRequest($request->validated()));

        /** @var User $user */
        $user = $token->accessToken->tokenable;
        $sendVerification->execute($user);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($token->accessToken->tokenable),
        ], 201);
    }

    /**
     * Sign In
     *
     * Authenticate a user and return an access token.
     */
    public function signIn(LoginRequest $request, LoginUserAction $action): JsonResponse
    {
        $token = $action->execute(LoginDTO::fromRequest($request->validated()));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($token->accessToken->tokenable),
        ]);
    }

    /**
     * Logout
     *
     * Revoke the current access token.
     */
    public function logout(Request $request): Response
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();
        $token->delete();

        return response()->noContent();
    }

    /**
     * Forgot Password
     *
     * Send a password reset code to the user's email.
     */
    public function forgotPassword(ForgotPasswordRequest $request, SendPasswordResetCodeAction $action): JsonResponse
    {
        $action->execute($request->validated('email'));

        return response()->json([
            'message' => __('If the email exists, a reset code has been sent.'),
        ]);
    }

    /**
     * Reset Password
     *
     * Reset the user's password using the provided reset code.
     */
    public function resetPassword(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        $action->execute(ResetPasswordDTO::fromRequest($request->validated()));

        return response()->json([
            'message' => __('Password has been reset.'),
        ]);
    }
}
