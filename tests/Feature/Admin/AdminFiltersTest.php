<?php

use App\Enums\StatsPeriod;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
    $this->admin = User::factory()->networkAdmin()->create();
    $this->insurer = Insurer::factory()->create();
});

/**
 * Five officines of one city so the insurer clears the anonymity threshold.
 */
function declareInCity(Insurer $insurer, string $city, int $month, int $delay = 30): void
{
    foreach (range(1, 5) as $i) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => $month,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 1_000_000,
            'delay_days' => $delay,
        ]);
    }
}

test('the network screen offers the cities and the periods to filter by', function () {
    declareInCity($this->insurer, 'Cotonou', 8);
    declareInCity($this->insurer, 'Parakou', 8);

    $this->actingAs($this->admin)
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];

            // The admin's own officine has a city too, so the list is a
            // superset of the two declared here rather than equal to them.
            expect($props['cities'])->toContain('Cotonou', 'Parakou');

            $page->where('period', StatsPeriod::CurrentQuarter->value)
                ->has('periods', count(StatsPeriod::cases()))
                ->where('periods.0.value', StatsPeriod::CurrentQuarter->value)
                ->where('periods.0.label', 'Trimestre en cours');
        });
});

test('the network screen narrows to the city in the query string', function () {
    declareInCity($this->insurer, 'Cotonou', 8);
    declareInCity($this->insurer, 'Parakou', 8);

    $this->actingAs($this->admin)
        ->get(route('admin.network', ['city' => 'Cotonou']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('city', 'Cotonou')
            ->where('summary.declaringPharmacies', 5),
        );
});

test('the network screen honours the period in the query string', function () {
    // February is outside the current quarter but inside the current semester
    // only from July on — so it lands in the previous semester here.
    declareInCity($this->insurer, 'Cotonou', 2);

    $this->actingAs($this->admin)
        ->get(route('admin.network', ['period' => StatsPeriod::PreviousSemester->value]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('period', StatsPeriod::PreviousSemester->value)
            ->where('summary.declarations', 5),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.network'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.declarations', 0),
        );
});

test('an unknown period falls back to the screen default', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.network', ['period' => 'la-semaine-des-quatre-jeudis']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('period', StatsPeriod::CurrentQuarter->value),
        );
});

test('the trends screen offers the same filters and narrows its amounts too', function () {
    declareInCity($this->insurer, 'Cotonou', 8);
    declareInCity($this->insurer, 'Parakou', 8);

    $this->actingAs($this->admin)
        ->get(route('admin.trends', ['city' => 'Cotonou']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('city', 'Cotonou')
            ->where('period', StatsPeriod::LastTwelveMonths->value)
            ->has('periods', count(StatsPeriod::cases()))
            // The bug this closes: the amounts ignored the city while the
            // delays honoured it, on the same row of KPIs.
            ->where('summary.invoiced', 5_000_000)
            ->where('summary.declaringPharmacies', 5),
        );
});

test('the trends screen honours the period in the query string', function () {
    declareInCity($this->insurer, 'Cotonou', 2);

    $this->actingAs($this->admin)
        ->get(route('admin.trends', ['period' => StatsPeriod::CurrentQuarter->value]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('period', StatsPeriod::CurrentQuarter->value)
            ->where('summary.invoiced', 0),
        );
});

test('the export screen offers the same filters', function () {
    declareInCity($this->insurer, 'Cotonou', 8);

    $this->actingAs($this->admin)
        ->get(route('admin.csv-exports', ['city' => 'Cotonou']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('city', 'Cotonou')
            ->has('cities')
            ->has('periods', count(StatsPeriod::cases()))
            ->where('period', StatsPeriod::LastTwelveMonths->value),
        );
});

test('the downloaded csv honours both filters', function () {
    declareInCity($this->insurer, 'Cotonou', 8, delay: 11);
    declareInCity($this->insurer, 'Parakou', 8, delay: 99);

    $csv = $this->actingAs($this->admin)
        ->get(route('admin.csv-exports.download', [
            'city' => 'Cotonou',
            'period' => StatsPeriod::CurrentQuarter->value,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('11')
        ->and($csv)->not->toContain('99');
});

test('changing a filter on the trends screen refetches the deferred curve', function () {
    declareInCity($this->insurer, 'Cotonou', 8, delay: 10);
    declareInCity($this->insurer, 'Parakou', 8, delay: 200);

    // What the page's watcher issues: a partial visit naming the deferred prop.
    // Without 'trend' in that list the KPIs would move and the chart would not.
    // Asked of the middleware itself: a mismatched version answers 409 rather
    // than the payload, and the value is a hash of the Vite manifest.
    $version = app(HandleInertiaRequests::class)->version(request());

    $response = $this->actingAs($this->admin)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'admin/Trends',
            'X-Inertia-Partial-Data' => 'summary,amounts,trend,period,periodLabel,city',
        ])
        ->get(route('admin.trends', ['city' => 'Cotonou']))
        ->assertOk();

    $props = $response->json('props');

    expect($props)->toHaveKey('trend')
        ->and((float) $props['trend']['network']['2026-08'])->toBe(10.0);
});
