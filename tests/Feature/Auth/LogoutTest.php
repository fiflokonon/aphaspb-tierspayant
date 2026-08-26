<?php

use App\Models\User;

test('logging out destroys the session', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('auth.logout'))->assertRedirect('/');

    $this->assertGuest();
});
