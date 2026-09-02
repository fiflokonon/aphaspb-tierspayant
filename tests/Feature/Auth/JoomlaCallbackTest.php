<?php

use App\Models\User;
use App\Services\Joomla\JoomlaHandoffState;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => useJoomlaTestKeys());

/**
 * Stub the Joomla profile endpoint. Registered per test rather than in a
 * beforeEach: Http::fake() keeps the first matching stub, so a global success
 * stub would shadow any failure a single test wants to exercise.
 */
function fakeJoomlaProfile(): void
{
    Http::fake([
        'joomla.test/api/me*' => Http::response(joomlaProfilePayload()),
    ]);
}

/** The state Laravel minted before sending the visitor off to Joomla. */
function handoffState(): string
{
    return 'a-state-minted-before-the-redirect';
}

/**
 * Post a handoff the way Joomla does: a cross-site form post carrying the
 * ticket and the state it was handed on the way out.
 *
 * @param  array<string, mixed>  $payload
 */
function postHandoff(array $payload = []): TestResponse
{
    return test()
        ->withCookie(JoomlaHandoffState::COOKIE, handoffState())
        ->post(route('auth.callback'), array_merge(['state' => handoffState()], $payload));
}

test('a valid ticket creates the shadow user and opens a session', function () {
    fakeJoomlaProfile();

    postHandoff(['token' => joomlaToken(['sub' => '5150'])])->assertRedirect();

    $user = User::query()->firstWhere('joomla_user_id', 5150);

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Pharmacie Le Bon Secours')
        ->and($user->email)->toBe('titulaire@officine.bj')
        ->and($user->joomla_groups)->toBe([2]);

    $this->assertAuthenticatedAs($user);
});

test('the profile is fetched from Joomla, never taken from the request', function () {
    fakeJoomlaProfile();

    postHandoff([
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

    postHandoff(['token' => joomlaToken(['sub' => '5150', 'groups' => [2, 6]])])
        ->assertRedirect();

    expect(User::query()->where('joomla_user_id', 5150)->count())->toBe(1)
        ->and($user->fresh()->joomla_groups)->toBe([2, 6]);
});

test('the session id is regenerated to defeat fixation', function () {
    fakeJoomlaProfile();

    $this->get('/');
    $before = session()->getId();

    postHandoff(['token' => joomlaToken(['sub' => '5150'])]);

    expect(session()->getId())->not->toBe($before);
});

test('the callback records when the token version was last checked', function () {
    fakeJoomlaProfile();

    postHandoff(['token' => joomlaToken(['sub' => '5150'])]);

    expect(session('joomla.token_version_checked_at'))->toBeInt();
});

test('a Joomla account with no role here is turned away without a session', function () {
    fakeJoomlaProfile();

    postHandoff(['token' => joomlaToken(['sub' => '5150', 'groups' => [999]])])
        ->assertRedirect(route('auth.denied'));

    $this->assertGuest();
    expect(User::query()->where('joomla_user_id', 5150)->exists())->toBeFalse();
});

test('a refused account is recognised on its groups alone, before Joomla is asked anything', function () {
    fakeJoomlaProfile();

    postHandoff(['token' => joomlaToken(['sub' => '5150', 'groups' => []])]);

    Http::assertNothingSent();
});

test('a network admin, who declares nothing, is still let in', function () {
    fakeJoomlaProfile();

    postHandoff(['token' => joomlaToken(['sub' => '5150', 'groups' => [8]])])
        ->assertRedirect(route('admin.network'));

    $this->assertAuthenticated();
});

test('a handoff carrying no state is refused', function () {
    fakeJoomlaProfile();

    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])])
        ->assertStatus(401);

    $this->assertGuest();
});

test('a handoff whose state does not match the cookie is refused', function () {
    fakeJoomlaProfile();

    $this->withCookie(JoomlaHandoffState::COOKIE, handoffState())
        ->post(route('auth.callback'), [
            'token' => joomlaToken(['sub' => '5150']),
            'state' => 'a-state-the-attacker-picked',
        ])
        ->assertStatus(401);

    $this->assertGuest();
});

test('a replayed ticket is refused', function () {
    fakeJoomlaProfile();

    $token = joomlaToken(['sub' => '5150']);

    postHandoff(['token' => $token])->assertRedirect();
    $this->post(route('auth.logout'));

    postHandoff(['token' => $token])->assertStatus(401);
});

test('a token for another audience is refused with a bare 401', function () {
    fakeJoomlaProfile();

    $response = postHandoff([
        'token' => joomlaToken(['sub' => '5150', 'aud' => 'someone-else']),
    ]);

    $response->assertStatus(401);
    expect($response->getContent())->not->toContain('audience');
    $this->assertGuest();
});

test('an expired token is refused', function () {
    fakeJoomlaProfile();

    postHandoff([
        'token' => joomlaToken(['sub' => '5150', 'iat' => time() - 3600, 'exp' => time() - 60]),
    ])->assertStatus(401);

    $this->assertGuest();
});

test('a missing token is refused', function () {
    fakeJoomlaProfile();

    postHandoff()->assertStatus(401);

    $this->assertGuest();
});

test('a callback is refused when Joomla will not hand over the profile', function () {
    Http::fake(['joomla.test/api/me*' => Http::response(status: 403)]);

    postHandoff(['token' => joomlaToken(['sub' => '5150'])])->assertStatus(401);

    expect(User::query()->where('joomla_user_id', 5150)->exists())->toBeFalse();
    $this->assertGuest();
});
