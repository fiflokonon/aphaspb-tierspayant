<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

test('a stranger at the root is handed to Joomla instead of being shown the app', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('a network admin is dropped on the network console', function () {
    Http::fake();

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('home'))
        ->assertRedirect(route('admin.network'));
});

test('a pharmacist who has not finished setting up is sent back to the onboarding', function () {
    Http::fake();

    $this->actingAs(User::factory()->notOnboarded()->create())
        ->get(route('home'))
        ->assertRedirect(route('onboarding.profile'));
});

test('an onboarded pharmacist is dropped on their dashboard', function () {
    Http::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]));
});
