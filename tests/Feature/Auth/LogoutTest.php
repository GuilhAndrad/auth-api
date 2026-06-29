<?php

use App\Models\User;

test('logout revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/logout')
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);

    auth()->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/user')
        ->assertUnauthorized();
});

test('logout requires authentication', function () {
    $this->postJson('/api/v1/logout')->assertUnauthorized();
});
