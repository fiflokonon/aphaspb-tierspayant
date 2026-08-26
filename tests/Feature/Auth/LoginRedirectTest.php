<?php

beforeEach(function () {
    config(['joomla.login_url' => 'https://joomla.test/login']);
});

test('a guest asking to log in is sent to Joomla', function () {
    $this->get(route('login'))
        ->assertRedirect('https://joomla.test/login');
});

test('an invitation code survives the round trip to Joomla', function () {
    $this->get(route('login', ['invitation' => 'abc123']))
        ->assertRedirect('https://joomla.test/login');

    expect(session('invitation'))->toBe('abc123');
});

test('the guard sends unauthenticated visitors to the login route', function () {
    $this->get('/settings/profile')
        ->assertRedirect(route('login'));
});
