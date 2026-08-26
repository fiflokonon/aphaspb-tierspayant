<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => useJoomlaTestKeys());

/**
 * Stub the Joomla profile endpoint. Registered per test rather than in a
 * beforeEach: Http::fake() keeps the first matching stub, so a global success
 * stub would shadow any failure a single test wants to exercise.
 */
function fakeJoomlaProfile(): void
{
    Http::fake([
        'joomla.test/api/me*' => Http::response([
            'id' => 5150,
            'name' => 'Pharmacie Le Bon Secours',
            'email' => 'titulaire@officine.bj',
            'verified' => true,
            'token_version' => 0,
        ]),
    ]);
}

test('a valid ticket creates the shadow user and opens a session', function () {
    fakeJoomlaProfile();

    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])])
        ->assertRedirect();

    $user = User::query()->firstWhere('joomla_user_id', 5150);

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Pharmacie Le Bon Secours')
        ->and($user->email)->toBe('titulaire@officine.bj')
        ->and($user->joomla_groups)->toBe([2]);

    $this->assertAuthenticatedAs($user);
});

test('the profile is fetched from Joomla, never taken from the request', function () {
    fakeJoomlaProfile();

    $this->post(route('auth.callback'), [
        'token' => joomlaToken(['sub' => '5150']),
        'name' => 'Attaquant',
        'email' => 'attaquant@example.test',
    ])->assertRedirect();

    expect(User::query()->firstWhere('joomla_user_id', 5150)->email)
        ->toBe('titulaire@officine.bj');
});

test('an existing user is reused and their groups refreshed', function () {
    fakeJoomlaProfile();

    $user = User::factory()->create([
        'joomla_user_id' => 5150,
        'joomla_groups' => [2],
    ]);

    $this->post(route('auth.callback'), [
        'token' => joomlaToken(['sub' => '5150', 'groups' => [2, 6]]),
    ])->assertRedirect();

    expect(User::query()->where('joomla_user_id', 5150)->count())->toBe(1)
        ->and($user->fresh()->joomla_groups)->toBe([2, 6]);
});

test('the session id is regenerated to defeat fixation', function () {
    fakeJoomlaProfile();

    $this->get('/');
    $before = session()->getId();

    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])]);

    expect(session()->getId())->not->toBe($before);
});

test('the callback records when the token version was last checked', function () {
    fakeJoomlaProfile();

    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])]);

    expect(session('joomla.token_version_checked_at'))->toBeInt();
});

test('a replayed ticket is refused', function () {
    fakeJoomlaProfile();

    $token = joomlaToken(['sub' => '5150']);

    $this->post(route('auth.callback'), ['token' => $token])->assertRedirect();
    $this->post(route('auth.logout'));

    $this->post(route('auth.callback'), ['token' => $token])->assertStatus(401);
});

test('a token for another audience is refused with a bare 401', function () {
    fakeJoomlaProfile();

    $response = $this->post(route('auth.callback'), [
        'token' => joomlaToken(['sub' => '5150', 'aud' => 'someone-else']),
    ]);

    $response->assertStatus(401);
    expect($response->getContent())->not->toContain('audience');
    $this->assertGuest();
});

test('an expired token is refused', function () {
    fakeJoomlaProfile();

    $this->post(route('auth.callback'), [
        'token' => joomlaToken(['sub' => '5150', 'iat' => time() - 3600, 'exp' => time() - 60]),
    ])->assertStatus(401);

    $this->assertGuest();
});

test('a missing token is refused', function () {
    fakeJoomlaProfile();

    $this->post(route('auth.callback'))->assertStatus(401);

    $this->assertGuest();
});

test('a callback is refused when Joomla will not hand over the profile', function () {
    Http::fake(['joomla.test/api/me*' => Http::response(status: 403)]);

    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])])
        ->assertStatus(401);

    expect(User::query()->where('joomla_user_id', 5150)->exists())->toBeFalse();
    $this->assertGuest();
});
