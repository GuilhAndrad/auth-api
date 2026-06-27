<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\ConfirmEmailChangeAction;
use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Auth\RequestEmailChangeAction;
use App\Actions\Auth\UpdatePasswordAction;
use App\Actions\Auth\UpdateProfileAction;
use App\DTOs\Auth\RequestEmailChangeDTO;
use App\DTOs\Auth\UpdatePasswordDTO;
use App\DTOs\Auth\UpdateProfileDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfirmEmailChangeRequest;
use App\Http\Requests\Api\V1\DeleteAccountRequest;
use App\Http\Requests\Api\V1\RequestEmailChangeRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

final class UserController extends Controller
{
    /**
     * Show
     *
     * Returns the authenticated user's profile information.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Update Profile
     *
     * Update the authenticated user's profile information.
     */
    public function update(
        UpdateProfileRequest $request,
        UpdateProfileAction $action
    ): UserResource {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($action->execute($user, UpdateProfileDTO::fromRequest($request->validated())));
    }

    /**
     * Update Password
     *
     * Update the authenticated user's password. Revokes all other tokens.
     */
    public function updatePassword(UpdatePasswordRequest $request, UpdatePasswordAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $currentToken */
        $currentToken = $user->currentAccessToken();

        $action->execute($user, UpdatePasswordDTO::fromRequest($request->validated()), $currentToken);

        return response()->json(['message' => __('Password updated.')]);
    }

    /**
     * Request Email Change
     *
     * Request a change of the authenticated user's email address.
     */
    public function requestEmailChange(RequestEmailChangeRequest $request, RequestEmailChangeAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, RequestEmailChangeDTO::fromRequest($request->validated()));

        return response()->json([
            'message' => __('A verification code has been sent to your new email address.'),
        ]);
    }

    /**
     * Confirm Email Change
     *
     * Confirm the change of the authenticated user's email address using a verification code.
     */
    public function confirmEmailChange(ConfirmEmailChangeRequest $request, ConfirmEmailChangeAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, $request->validated('code'));

        return response()->json([
            'message' => __('Email address updated. Please sign in again.'),
        ]);
    }

    /**
     * Delete Account
     *
     * Delete the authenticated user's account.
     */
    public function destroy(DeleteAccountRequest $request, DeleteAccountAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user);

        return response()->noContent();
    }
}
