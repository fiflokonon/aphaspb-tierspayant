<?php

use Inertia\Testing\AssertableInertia;

test('the refusal page is readable without a session and points back to Joomla', function () {
    config(['joomla.site_url' => 'https://joomla.test']);

    $this->get(route('auth.denied'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/AccessDenied')
            ->where('siteUrl', 'https://joomla.test'));
});
