<?php

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->admin = User::factory()->networkAdmin()->notOnboarded()->create();
});

test('the admin can raise the anonymity threshold', function () {
    Http::fake();

    $this->actingAs($this->admin)
        ->patch(route('admin.settings.anonymity'), ['minimum' => 8])
        ->assertRedirect(route('admin.insurers'));

    expect(app(SettingsRepository::class)->anonymityMinPharmacies())->toBe(8);
});

test('the threshold cannot be pushed below the floor', function () {
    Http::fake();

    // One declaring officine behind an insurer's figures is that officine's
    // figures. The floor is the whole point of exposing the setting safely.
    $this->actingAs($this->admin)
        ->patch(route('admin.settings.anonymity'), [
            'minimum' => SettingsRepository::ANONYMITY_FLOOR - 1,
        ])
        ->assertSessionHasErrors('minimum');

    expect(app(SettingsRepository::class)->anonymityMinPharmacies())->toBe(5);
});

test('a threshold that would hide the whole network is refused', function () {
    Http::fake();

    $this->actingAs($this->admin)
        ->patch(route('admin.settings.anonymity'), ['minimum' => 5000])
        ->assertSessionHasErrors('minimum');
});

test('an officine cannot touch the threshold', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.settings.anonymity'), ['minimum' => 2])
        ->assertForbidden();
});

test('the insurers page carries the floor the form has to respect', function () {
    Http::fake();

    $this->actingAs($this->admin)
        ->get(route('admin.insurers'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('anonymityFloor', SettingsRepository::ANONYMITY_FLOOR)
            ->etc(),
        );
});
