<?php

use App\Enums\PharmacyRole;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

test('a user arriving from Joomla has no pharmacy yet', function () {
    expect(User::factory()->notOnboarded()->create()->currentPharmacy)->toBeNull();
});

test('the profile step is shown to a user without a pharmacy', function () {
    $this->actingAs(User::factory()->notOnboarded()->create())
        ->get(route('onboarding.profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('onboarding/Profile')
            ->has('cities'),
        );
});

test('submitting the profile creates the officine and makes the user its owner', function () {
    $user = User::factory()->notOnboarded()->create();

    $this->actingAs($user)
        ->post(route('onboarding.profile.store'), [
            'name' => 'Pharmacie Le Bon Secours',
            'onpb_license' => 'ONPB-4212',
            'city' => 'Cotonou',
        ])
        ->assertRedirect(route('onboarding.insurers'));

    $pharmacy = $user->fresh()->currentPharmacy;

    expect($pharmacy)->not->toBeNull()
        ->and($pharmacy->name)->toBe('Pharmacie Le Bon Secours')
        ->and($pharmacy->city)->toBe('Cotonou')
        ->and($pharmacy->owner_name)->toBe($user->name)
        ->and($pharmacy->hasCompleteProfile())->toBeTrue()
        ->and($user->fresh()->pharmacyRole($pharmacy))->toBe(PharmacyRole::Owner);
});

test('the titulaire comes from the Joomla account, never from the form', function () {
    $user = User::factory()->notOnboarded()->create(['name' => 'Awa Hounkpatin']);

    // The field is displayed, not typed. A post that carries one anyway — the
    // browser is the attacker's to edit — must not reach the officine.
    $this->actingAs($user)
        ->post(route('onboarding.profile.store'), [
            'name' => 'Pharmacie Le Bon Secours',
            'city' => 'Cotonou',
            'owner_name' => 'Quelqu’un d’autre',
        ])
        ->assertRedirect(route('onboarding.insurers'));

    expect($user->fresh()->currentPharmacy->owner_name)->toBe('Awa Hounkpatin');
});

test('the ONPB licence may be left blank', function () {
    $user = User::factory()->notOnboarded()->create();

    $this->actingAs($user)
        ->post(route('onboarding.profile.store'), [
            'name' => 'Pharmacie Sans Licence',
            'city' => 'Parakou',
        ])
        ->assertRedirect();

    expect($user->fresh()->currentPharmacy->onpb_license)->toBeNull();
});

test('a duplicate ONPB licence is refused', function () {
    Pharmacy::factory()->create(['onpb_license' => 'ONPB-0001']);

    $this->actingAs(User::factory()->notOnboarded()->create())
        ->post(route('onboarding.profile.store'), [
            'name' => 'Pharmacie Copie',
            'onpb_license' => 'ONPB-0001',
            'city' => 'Cotonou',
        ])
        ->assertSessionHasErrors('onpb_license');
});

test('the name and the city are both required', function () {
    $this->actingAs(User::factory()->notOnboarded()->create())
        ->post(route('onboarding.profile.store'), [])
        ->assertSessionHasErrors(['name', 'city']);
});

test('a second submission updates the officine instead of creating another', function () {
    $user = User::factory()->notOnboarded()->create();
    $payload = ['name' => 'Pharmacie A', 'city' => 'Cotonou'];

    $this->actingAs($user)->post(route('onboarding.profile.store'), $payload);

    // Each real request reloads the user from the session, so the in-memory
    // instance the test holds must be refreshed the same way.
    $this->actingAs($user->fresh())
        ->post(route('onboarding.profile.store'), [...$payload, 'city' => 'Bohicon']);

    expect($user->fresh()->pharmacies()->count())->toBe(1)
        ->and($user->fresh()->currentPharmacy->city)->toBe('Bohicon');
});

test('an onboarded officine is redirected away from the onboarding', function () {
    $user = User::factory()->create();
    $user->currentPharmacy->insurers()->attach(Insurer::factory()->create());

    $this->actingAs($user)
        ->get(route('onboarding.profile'))
        ->assertRedirect(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]));
});

test('a pharmacy route sends an un-onboarded officine to the onboarding', function () {
    $this->actingAs(User::factory()->notOnboarded()->create())
        ->get(route('pharmacy.declare'))
        ->assertRedirect(route('onboarding.profile'));
});
