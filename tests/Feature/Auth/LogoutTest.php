<?php

use App\Models\User;

beforeEach(function () {
    config([
        'joomla.site_url' => 'https://joomla.test',
        'joomla.login_url' => 'https://joomla.test/handoff',
    ]);
});

test('logging out destroys the session', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('auth.logout'))->assertRedirect('https://joomla.test');

    $this->assertGuest();
});

test('logging out leaves the application instead of bouncing through the handoff', function () {
    $this->actingAs(User::factory()->create());

    $location = $this->post(route('auth.logout'))->headers->get('Location');

    // Joomla keeps a session of its own that this application cannot end, and
    // the login route is the handoff: sending someone there would sign them
    // straight back in, and the button would read as broken.
    expect($location)->toBe('https://joomla.test')
        ->and($location)->not->toBe(route('login'));
});

test('the button gets an Inertia location rather than a cross-origin redirect', function () {
    $this->actingAs(User::factory()->create());

    // The button posts over XHR. A 302 towards joomla.test would be followed
    // by the browser, not by Inertia, and blocked on CORS: the session would
    // end with the user still sitting on the console, an error in the log.
    $this->post(route('auth.logout'), [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://joomla.test');

    $this->assertGuest();
});
