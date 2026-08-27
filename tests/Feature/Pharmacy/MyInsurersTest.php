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
    Insurer::factory()->inactive()->create();

    $this->actingAs($user)
        ->get(route('pharmacy.insurers'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($user, $extra) {
            $props = $page->toArray()['props'];
            $offered = collect($props['insurers'])->pluck('id');

            expect($page->toArray()['component'])->toBe('pharmacy/Insurers')
                ->and($offered)->toContain($extra->id)
                ->and($props['selected'])
                ->toBe($user->currentPharmacy->insurers()->pluck('insurers.id')->all());
        });
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

test('the page names the insurers that would lose their monthly slot', function () {
    $user = User::factory()->create();
    $declared = $user->currentPharmacy->insurers()->sole();

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $declared->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('withDeclarations', [$declared->id]),
        );
});

test('an admin account cannot reach the officine insurers page', function () {
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('pharmacy.insurers'))
        ->assertForbidden();
});
