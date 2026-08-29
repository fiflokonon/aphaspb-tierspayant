<?php

use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkStatsService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
});

test('the page lists active insurers and ticks the ones in use', function () {
    $user = User::factory()->create();
    $extra = Insurer::factory()->create();
    $retired = Insurer::factory()->inactive()->create();

    $this->actingAs($user)
        ->get(route('pharmacy.insurers'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($user, $extra, $retired) {
            $props = $page->toArray()['props'];
            $offered = collect($props['insurers'])->pluck('id');

            expect($page->toArray()['component'])->toBe('pharmacy/Insurers')
                ->and($offered)->toContain($extra->id)
                // Retired and unrelated to this officine: nothing to offer.
                ->and($offered)->not->toContain($retired->id)
                ->and($props['selected'])
                ->toBe($user->currentPharmacy->insurers()->pluck('insurers.id')->all());
        });
});

test('an insurer the APhaSPB retired stays listed while the officine still works with it', function () {
    $user = User::factory()->create();
    $retired = Insurer::factory()->inactive()->create(['name' => 'Atlantique Assurances']);

    $user->currentPharmacy->insurers()->attach($retired->id);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($retired) {
            $row = collect($page->toArray()['props']['insurers'])->firstWhere('id', $retired->id);

            // Counted by the button and invisible in the list is the bug this
            // fixes: the officine could neither see it nor untick it.
            expect($row)->not->toBeNull()
                ->and($row['isActive'])->toBeFalse();
        });
});

test('a retired insurer can be untied like any other', function () {
    $user = User::factory()->create();
    $kept = $user->currentPharmacy->insurers()->sole();
    $retired = Insurer::factory()->inactive()->create();

    $user->currentPharmacy->insurers()->attach($retired->id);

    $this->actingAs($user)
        ->patch(route('pharmacy.insurers.update'), ['insurers' => [$kept->id]])
        ->assertRedirect(route('pharmacy.insurers'));

    expect($user->fresh()->currentPharmacy->insurers->pluck('id')->all())->toBe([$kept->id]);
});

test('unticking an insurer keeps its past declarations', function () {
    $user = User::factory()->create();
    $dropped = $user->currentPharmacy->insurers()->sole();
    $kept = Insurer::factory()->create();

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $dropped->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $this->actingAs($user)
        ->patch(route('pharmacy.insurers.update'), ['insurers' => [$kept->id]])
        ->assertRedirect(route('pharmacy.insurers'));

    expect($user->fresh()->currentPharmacy->insurers->pluck('id')->all())->toBe([$kept->id])
        ->and(Declaration::query()->where('insurer_id', $dropped->id)->count())->toBe(1);
});

test('a declaration for an untied insurer still counts in the network statistics', function () {
    $user = User::factory()->create();
    $dropped = $user->currentPharmacy->insurers()->sole();

    // Five officines so the insurer clears the anonymity threshold.
    foreach (range(1, 5) as $i) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $i === 1 ? $user->currentPharmacy->id : Pharmacy::factory(),
            'insurer_id' => $dropped->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    $this->actingAs($user)
        ->patch(route('pharmacy.insurers.update'), ['insurers' => [Insurer::factory()->create()->id]]);

    $rows = app(NetworkStatsService::class)->perInsurer(new Period(2026, 8), new Period(2026, 8));

    expect($rows[$dropped->id]->declaringPharmacies)->toBe(5);
});

test('unticking everything is refused', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('pharmacy.insurers.update'), ['insurers' => []])
        ->assertSessionHasErrors('insurers');
});

test('the free-text entry creates an inactive insurer, as at onboarding', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('pharmacy.insurers.update'), ['other' => 'Mutuelle des Enseignants']);

    $created = Insurer::query()->firstWhere('name', 'Mutuelle des Enseignants');

    expect($created)->not->toBeNull()
        ->and($created->is_active)->toBeFalse()
        ->and($user->fresh()->currentPharmacy->insurers->pluck('id')->all())->toBe([$created->id]);
});

test('each insurer carries how many declarations this officine filed with it', function () {
    $user = User::factory()->create();
    $declared = $user->currentPharmacy->insurers()->sole();
    $never = Insurer::factory()->create();

    foreach ([6, 7] as $month) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $declared->id,
            'period_year' => 2026,
            'period_month' => $month,
        ]);
    }

    // Another officine's declarations must not inflate the count.
    Declaration::factory()->paid()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $declared->id,
        'period_year' => 2026,
        'period_month' => 5,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers'))
        ->assertInertia(function (AssertableInertia $page) use ($declared, $never) {
            $rows = collect($page->toArray()['props']['insurers'])->keyBy('id');

            expect($rows[$declared->id]['declarations'])->toBe(2)
                ->and($rows[$never->id]['declarations'])->toBe(0);
        });
});

test('saving the selection confirms with a toast', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->patch(route('pharmacy.insurers.update'), ['insurers' => [Insurer::factory()->create()->id]]);

    $response->assertRedirect(route('pharmacy.insurers'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Vos assureurs sont enregistrés.']);
});

test('an admin account cannot reach the officine insurers page', function () {
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('pharmacy.insurers'))
        ->assertForbidden();
});
