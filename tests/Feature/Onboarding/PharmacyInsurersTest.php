<?php

use App\Models\Insurer;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

/**
 * A user whose officine profile is complete but who has ticked no insurer.
 */
function userAwaitingInsurers(): User
{
    $user = User::factory()->create();
    $user->currentPharmacy->insurers()->detach();

    return $user->fresh();
}

test('only active insurers are offered', function () {
    $active = Insurer::factory()->count(3)->create();
    $inactive = Insurer::factory()->inactive()->count(2)->create();

    $this->actingAs(userAwaitingInsurers())
        ->get(route('onboarding.insurers'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($active, $inactive) {
            $page->component('onboarding/Insurers')->has('selected', 0);

            // Asserted by membership rather than by count: the user factory
            // creates an insurer of its own, and a hard count would break on it.
            $offered = collect($page->toArray()['props']['insurers'])->pluck('id');

            foreach ($active as $insurer) {
                expect($offered)->toContain($insurer->id);
            }

            foreach ($inactive as $insurer) {
                expect($offered)->not->toContain($insurer->id);
            }
        });
});

test('an insurer already tied to the officine is offered even when retired', function () {
    // Reachable by revisiting the step after onboarding: the count under the
    // button reads the selection, so an unlisted tie showed as a phantom.
    $user = userAwaitingInsurers();
    $retired = Insurer::factory()->inactive()->create();

    $user->currentPharmacy->insurers()->attach($retired->id);

    $this->actingAs($user)
        ->get(route('onboarding.insurers'))
        ->assertInertia(function (AssertableInertia $page) use ($retired) {
            $offered = collect($page->toArray()['props']['insurers'])->pluck('id');

            expect($offered)->toContain($retired->id);
        });
});

test('ticking insurers attaches them and finishes the onboarding', function () {
    $user = userAwaitingInsurers();
    $insurers = Insurer::factory()->count(3)->create();

    $this->actingAs($user)
        ->post(route('onboarding.insurers.store'), [
            'insurers' => $insurers->pluck('id')->all(),
        ])
        ->assertRedirect(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]));

    expect($user->fresh()->currentPharmacy->insurers)->toHaveCount(3)
        ->and($user->fresh()->needsOnboarding())->toBeFalse();
});

test('submitting nothing at all is refused', function () {
    $this->actingAs(userAwaitingInsurers())
        ->post(route('onboarding.insurers.store'), [])
        ->assertSessionHasErrors('insurers');
});

test('a second submission replaces the selection instead of stacking it', function () {
    $user = userAwaitingInsurers();
    $first = Insurer::factory()->count(3)->create();
    $second = Insurer::factory()->count(2)->create();

    $this->actingAs($user)->post(route('onboarding.insurers.store'), [
        'insurers' => $first->pluck('id')->all(),
    ]);
    $this->actingAs($user->fresh())->post(route('onboarding.insurers.store'), [
        'insurers' => $second->pluck('id')->all(),
    ]);

    expect($user->fresh()->currentPharmacy->insurers->pluck('id')->sort()->values()->all())
        ->toBe($second->pluck('id')->sort()->values()->all());
});

test('a free-text insurer is created inactive and attached', function () {
    $user = userAwaitingInsurers();

    $this->actingAs($user)
        ->post(route('onboarding.insurers.store'), ['other' => 'Mutuelle des Enseignants'])
        ->assertRedirect();

    $created = Insurer::query()->firstWhere('name', 'Mutuelle des Enseignants');

    expect($created)->not->toBeNull()
        ->and($created->is_active)->toBeFalse()
        ->and($user->fresh()->currentPharmacy->insurers->pluck('id')->all())->toBe([$created->id]);
});

test('a free-text name that already exists does not create a duplicate', function () {
    $existing = Insurer::factory()->create(['name' => 'NSIA Assurances']);

    $this->actingAs(userAwaitingInsurers())
        ->post(route('onboarding.insurers.store'), ['other' => '  NSIA Assurances  ']);

    expect(Insurer::query()->where('name', 'NSIA Assurances')->count())->toBe(1)
        ->and($existing->fresh()->is_active)->toBeTrue();
});

test('the insurers step sends an incomplete profile back to step one', function () {
    $user = User::factory()->create();
    $user->currentPharmacy->update(['city' => null]);

    $this->actingAs($user)
        ->get(route('onboarding.insurers'))
        ->assertRedirect(route('onboarding.profile'));
});
