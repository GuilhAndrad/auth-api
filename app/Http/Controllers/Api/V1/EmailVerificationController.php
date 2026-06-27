<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\SendEmailVerificationAction;
use App\Actions\Auth\VerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VerifyEmailRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function verify(VerifyEmailRequest $request, VerifyEmailAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, $request->validated('code'));

        return response()->json([
            'message' => __('Email verified successfully.'),
        ]);
    }

    public function resend(Request $request, SendEmailVerificationAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user);

        return response()->json([
            'message' => __('If your email is not yet verified, a new code has been sent.'),
        ]);
    }
}
