<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    useJoomlaTestKeys();

    Route::middleware('auth:api')->get('/test-api-user', fn () => response()->json([
        'id' => auth('api')->id(),
    ]));
});

test('a valid bearer token resolves the matching user', function () {
    $user = User::factory()->create(['joomla_user_id' => 5150, 'token_version' => 2]);

    $this->withToken(joomlaToken(['sub' => '5150', 'tv' => 2]))
        ->getJson('/test-api-user')
        ->assertOk()
        ->assertJson(['id' => $user->id]);
});

test('a request without a bearer token is rejected', function () {
    $this->getJson('/test-api-user')->assertUnauthorized();
});

test('a token whose subject has no local user is rejected', function () {
    $this->withToken(joomlaToken(['sub' => '999999']))
        ->getJson('/test-api-user')
        ->assertUnauthorized();
});

test('a token whose version trails the stored one is rejected', function () {
    User::factory()->create(['joomla_user_id' => 5150, 'token_version' => 4]);

    $this->withToken(joomlaToken(['sub' => '5150', 'tv' => 3]))
        ->getJson('/test-api-user')
        ->assertUnauthorized();
});

test('the guard refreshes the stored groups when the claim diverges', function () {
    $user = User::factory()->create([
        'joomla_user_id' => 5150,
        'joomla_groups' => [2],
    ]);

    $this->withToken(joomlaToken(['sub' => '5150', 'groups' => [2, 6]]))
        ->getJson('/test-api-user')
        ->assertOk();

    expect($user->fresh()->joomla_groups)->toBe([2, 6]);
});

test('the guard ignores the session and reads only the bearer token', function () {
    $user = User::factory()->create(['joomla_user_id' => 5150]);

    $this->actingAs($user)
        ->getJson('/test-api-user')
        ->assertUnauthorized();
});
