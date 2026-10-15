<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
    $this->admin = User::factory()->networkAdmin()->notOnboarded()->create();
    $this->insurer = Insurer::factory()->create(['name' => 'Assur Bénin']);
});

/**
 * One officine per call, each with an unpaid month of `$owed` FCFA.
 */
function creditorIn(Insurer $insurer, string $city, int $owed = 100_000, string $name = ''): Pharmacy
{
    $pharmacy = Pharmacy::factory()->create(array_filter(['city' => $city, 'name' => $name]));

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_month' => 5,
        'amount_invoiced' => $owed + 50_000,
        'amount_received' => 50_000,
    ]);

    return $pharmacy;
}

test('the sheet names the creditors and the partners, never an amount per officine', function () {
    $creditor = creditorIn($this->insurer, 'Cotonou', 123_457, 'Pharmacie Créancière');

    $paidUp = Pharmacy::factory()->create(['name' => 'Pharmacie Soldée']);
    Declaration::factory()->create([
        'pharmacy_id' => $paidUp->id,
        'insurer_id' => $this->insurer->id,
        'amount_invoiced' => 80_000,
        'amount_received' => 80_000,
    ]);
    $paidUp->insurers()->attach($this->insurer);

    $this->actingAs($this->admin)
        ->get(route('admin.insurers.show', $this->insurer))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($creditor) {
            $page->component('admin/InsurerSheet')
                ->where('creditors', [['name' => 'Pharmacie Créancière', 'slug' => $creditor->slug, 'city' => 'Cotonou']])
                ->where('partners.0.name', 'Pharmacie Soldée')
                ->where('outstanding.withheld', true)
                ->where('outstanding.amount', null);

            $serialised = json_encode($page->toArray()['props'], JSON_THROW_ON_ERROR);

            expect($serialised)->not->toContain('123457');
        });
});

test('the total owed is published once it rests on the threshold of creditors', function () {
    foreach (range(1, 5) as $index) {
        creditorIn($this->insurer, 'Cotonou', 100_000);
    }

    $this->actingAs($this->admin)
        ->get(route('admin.insurers.show', $this->insurer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('creditors', 5)
            ->where('outstanding.withheld', false)
            ->where('outstanding.amount', 500_000),
        );
});

test('the total owed is withheld below the threshold of creditors', function () {
    foreach (range(1, 4) as $index) {
        creditorIn($this->insurer, 'Cotonou');
    }

    $this->actingAs($this->admin)
        ->get(route('admin.insurers.show', $this->insurer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('creditors', 4)
            ->where('outstanding.withheld', true)
            ->where('outstanding.withheldReason', 'too-few')
            ->where('outstanding.required', 5),
        );
});

test('the total owed is withheld when the creditors of small cities rest on too few officines', function () {
    foreach (range(1, 5) as $index) {
        creditorIn($this->insurer, 'Cotonou');
    }

    // Total − Cotonou's published figure would give this officine's debt back.
    creditorIn($this->insurer, 'Parakou');

    $this->actingAs($this->admin)
        ->get(route('admin.insurers.show', $this->insurer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('creditors', 6)
            ->where('outstanding.withheldReason', 'city-share')
            ->where('outstanding.amount', null),
        );
});

test('a pharmacy account cannot open an insurer sheet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.insurers.show', $this->insurer))
        ->assertForbidden();
});
