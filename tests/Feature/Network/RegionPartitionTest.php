<?php

use App\Data\InsufficientData;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Models\City;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\Region;
use App\Models\User;
use App\Services\Network\NetworkPenaltyJournal;
use App\Services\Network\NetworkStatsService;
use App\Services\Network\RegionScopes;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
    $this->insurer = Insurer::factory()->create();
    $this->south = Region::factory()->create(['name' => 'Sud']);
    $this->north = Region::factory()->create(['name' => 'Nord']);

    foreach (['Cotonou' => $this->south, 'Ouidah' => $this->south, 'Parakou' => $this->north, 'Kandi' => $this->north] as $name => $region) {
        City::factory()->create(['name' => $name, 'region_id' => $region->id]);
    }
});

/**
 * `$count` officines of `$city`, each with a paid month for the insurer.
 */
function paidIn(Insurer $insurer, ?string $city, int $count, int $invoiced = 1_000_000): void
{
    foreach (range(1, $count) as $i) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => $invoiced,
            'amount_received' => $invoiced,
            'delay_days' => 30,
        ]);
    }
}

function regionEntry(Insurer $insurer, Region ...$regions): InsurerIndicators|InsufficientData|null
{
    $scope = app(RegionScopes::class)->fromIds(array_map(fn (Region $region) => $region->id, $regions));

    return app(NetworkStatsService::class)->perInsurer(new Period(2026, 8), new Period(2026, 8), $scope)[$insurer->id] ?? null;
}

test('a region filter narrows the aggregates to the communes of the chosen regions', function () {
    paidIn($this->insurer, 'Cotonou', 5);
    paidIn($this->insurer, 'Parakou', 5);
    paidIn($this->insurer, 'Kandi', 5);

    expect(regionEntry($this->insurer, $this->south)->declaringPharmacies)->toBe(5)
        ->and(regionEntry($this->insurer, $this->north)->declaringPharmacies)->toBe(10)
        ->and(regionEntry($this->insurer, $this->south, $this->north)->declaringPharmacies)->toBe(15);
});

test('a region whose small communes rest on too few officines withholds every region figure of the insurer', function () {
    paidIn($this->insurer, 'Cotonou', 5);
    // Sud − Cotonou published would give this one officine back.
    paidIn($this->insurer, 'Ouidah', 1);
    paidIn($this->insurer, 'Parakou', 5);
    paidIn($this->insurer, 'Kandi', 5);

    // Unfiltered: the hidden share is that single officine too.
    expect(regionEntry($this->insurer, $this->south))->toBeInstanceOf(InsufficientData::class)
        ->and(regionEntry($this->insurer, $this->south)->cityShare)->toBeTrue()
        // Nord is clean by itself, but Nord + Sud − Nord would publish Sud:
        // the rule reads the whole partition, not the selection.
        ->and(regionEntry($this->insurer, $this->north))->toBeInstanceOf(InsufficientData::class);
});

test('a clean partition publishes the region figures even when a region hides several small communes', function () {
    paidIn($this->insurer, 'Cotonou', 5);
    paidIn($this->insurer, 'Ouidah', 5);
    // Nord hides 3 + 2 = 5 officines in small communes: at the threshold.
    paidIn($this->insurer, 'Parakou', 3);
    paidIn($this->insurer, 'Kandi', 2);

    expect(regionEntry($this->insurer, $this->south))->toBeInstanceOf(InsurerIndicators::class)
        ->and(regionEntry($this->insurer, $this->north))->toBeInstanceOf(InsurerIndicators::class);
});

test('communes outside every region form the rest, which follows the same rule', function () {
    paidIn($this->insurer, 'Cotonou', 5);
    paidIn($this->insurer, 'Parakou', 5);
    // Total − Sud − Nord would give this officine back.
    paidIn($this->insurer, 'Hameau', 1);
    paidIn($this->insurer, 'Bohicon', 5);

    expect(regionEntry($this->insurer, $this->south))->toBeInstanceOf(InsufficientData::class);

    paidIn($this->insurer, 'Hameau', 4);

    expect(regionEntry($this->insurer, $this->south))->toBeInstanceOf(InsurerIndicators::class);
});

test('the network summary filtered by regions follows the region rule', function () {
    paidIn($this->insurer, 'Cotonou', 5);
    paidIn($this->insurer, 'Ouidah', 1);
    paidIn($this->insurer, 'Parakou', 5);

    $scope = app(RegionScopes::class)->fromIds([$this->north->id]);
    $summary = app(NetworkStatsService::class)->networkSummary(new Period(2026, 8), new Period(2026, 8), $scope);

    expect($summary['withheld'])->toBeTrue()
        ->and($summary['withheldReason'])->toBe('city-share')
        ->and($summary['declaringPharmacies'])->toBeNull();
});

test('the penalty journal filtered by regions withholds a month the region rule withholds', function () {
    $insurer = Insurer::factory()->create(['penalty_trigger_days' => 30, 'penalty_rate_bp' => 200]);

    foreach (['Cotonou' => 5, 'Ouidah' => 1, 'Parakou' => 5] as $city => $count) {
        foreach (range(1, $count) as $i) {
            Declaration::factory()->create([
                'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => 3,
                'amount_invoiced' => 1_000_000,
                'amount_received' => 0,
            ]);
        }
    }

    $scope = app(RegionScopes::class)->fromIds([$this->north->id]);
    $ledger = app(NetworkPenaltyJournal::class)->for(new Period(2026, 3), new Period(2026, 8), $scope)->toArray();

    // Nord alone is clean, but its total minus Cotonou's would not be: Sud
    // hides one officine, so no region total of a month that accrued shows.
    $published = collect($ledger['total']['months'])->filter(fn (array $month) => ($month['accrued'] ?? 0) > 0);

    expect($published)->toBeEmpty()
        ->and(collect($ledger['total']['months'])->where('withheld', true))->not->toBeEmpty();
});

test('the network screen reads the regions from the query string and offers them', function () {
    paidIn($this->insurer, 'Parakou', 5);
    paidIn($this->insurer, 'Cotonou', 5);

    $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('admin.network', ['regions' => [$this->north->id, 9999], 'period' => 'last-12-months']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('regions', [
                ['id' => $this->north->id, 'name' => 'Nord'],
                ['id' => $this->south->id, 'name' => 'Sud'],
            ])
            ->where('selectedRegions', [$this->north->id])
            ->where('city', null)
            ->where('summary.declaringPharmacies', 5),
        );
});

test('the CSV export honours the region filter', function () {
    $other = Insurer::factory()->create(['name' => 'Seulement au Sud']);
    paidIn($this->insurer, 'Parakou', 5);
    paidIn($other, 'Cotonou', 5);

    $csv = $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('admin.csv-exports.download', ['regions' => [$this->north->id], 'period' => 'last-12-months']))
        ->streamedContent();

    expect($csv)->toContain($this->insurer->name)
        ->and($csv)->not->toContain('Seulement au Sud');
});

test('a chosen city wins over the regions', function () {
    paidIn($this->insurer, 'Parakou', 5);
    paidIn($this->insurer, 'Cotonou', 5);

    $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('admin.network', ['regions' => [$this->north->id], 'city' => 'Cotonou', 'period' => 'last-12-months']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('city', 'Cotonou')
            ->where('selectedRegions', [])
            ->where('summary.declaringPharmacies', 5),
        );
});
