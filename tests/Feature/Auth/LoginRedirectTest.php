<?php

use App\Services\Joomla\JoomlaHandoffState;

beforeEach(function () {
    config(['joomla.login_url' => 'https://joomla.test/login']);
});

test('a guest asking to log in is sent to Joomla', function () {
    $response = $this->get(route('login'));

    expect($response->headers->get('Location'))->toStartWith('https://joomla.test/login?state=');
});

test('the redirect leaves behind the state the callback will demand', function () {
    $response = $this->get(route('login'));

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    $response->assertCookie(JoomlaHandoffState::COOKIE, $query['state']);
});

test('the state cookie is set up to survive a cross-site post', function () {
    $cookie = collect($this->get(route('login'))->headers->getCookies())
        ->firstWhere(fn ($cookie) => $cookie->getName() === JoomlaHandoffState::COOKIE);

    expect($cookie->getSameSite())->toBe('none')
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue();
});

test('an invitation code survives the round trip to Joomla', function () {
    $this->get(route('login', ['invitation' => 'abc123']));

    expect(session('invitation'))->toBe('abc123');
});

test('the guard sends unauthenticated visitors to the login route', function () {
    $this->get('/settings/profile')
        ->assertRedirect(route('login'));
});
