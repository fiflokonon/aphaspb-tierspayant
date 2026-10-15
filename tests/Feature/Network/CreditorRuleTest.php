<?php

use App\Data\InsurerAmounts;
use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkStatsService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/*
 * Since the insurer sheet names the officines an insurer still owes
 * (15/10/2026), what is owed — received, outstanding, recovery rate — rests on
 * the threshold of creditors, not merely of declarants.
 */

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
    $this->insurer = Insurer::factory()->create();
    $this->service = app(NetworkStatsService::class);
});

/**
 * `$paid` officines fully paid and `$owed` officines owed 400 000 FCFA, in `$city`.
 */
function declareOwed(Insurer $insurer, int $paid, int $owed, string $city = 'Cotonou'): void
{
    foreach (range(1, $paid + $owed) as $index) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => 1_000_000,
            'amount_received' => $index <= $paid ? 1_000_000 : 600_000,
        ]);
    }
}

function amountsOf(Insurer $insurer, ?string $city = null): InsurerAmounts
{
    return app(NetworkStatsService::class)->aggregatedByInsurer(new Period(2026, 8), new Period(2026, 8), $city)[$insurer->id];
}

test('a single creditor among enough declarants keeps the invoiced and withholds what is owed', function () {
    declareOwed($this->insurer, paid: 5, owed: 1);

    $amounts = amountsOf($this->insurer);

    expect($amounts->invoiced)->toBe(6_000_000)
        ->and($amounts->received)->toBeNull()
        ->and($amounts->outstanding)->toBeNull()
        ->and($amounts->recoveryRate)->toBeNull()
        ->and($amounts->owedWithheld)->toBeTrue();

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->amountOutstanding)->toBeNull()
        ->and($indicators->amountReceived)->toBeNull();
});

test('what is owed shows once it rests on the threshold of creditors, or on none', function () {
    declareOwed($this->insurer, paid: 1, owed: 5);

    expect(amountsOf($this->insurer)->outstanding)->toBe(2_000_000)
        ->and(amountsOf($this->insurer)->owedWithheld)->toBeFalse();

    $settled = Insurer::factory()->create();
    declareOwed($settled, paid: 5, owed: 0);

    expect(amountsOf($settled)->outstanding)->toBe(0);
});

test('the city filter re-applies the creditor threshold inside the city', function () {
    declareOwed($this->insurer, paid: 0, owed: 5, city: 'Cotonou');
    declareOwed($this->insurer, paid: 4, owed: 1, city: 'Parakou');

    expect(amountsOf($this->insurer, 'Parakou')->outstanding)->toBeNull()
        ->and(amountsOf($this->insurer, 'Cotonou')->outstanding)->toBe(2_000_000);
});

test('unfiltered, the creditors of the cities that withhold them must not rest on too few officines', function () {
    declareOwed($this->insurer, paid: 0, owed: 5, city: 'Cotonou');
    // Parakou publishes its delays (5 declarants) but not what is owed there:
    // the network figure minus Cotonou's would give its one creditor back.
    declareOwed($this->insurer, paid: 4, owed: 1, city: 'Parakou');

    expect(amountsOf($this->insurer)->outstanding)->toBeNull()
        ->and(amountsOf($this->insurer)->invoiced)->toBe(10_000_000);
});

test('the network summary withholds what is owed when the insurers that withhold it rest on too few creditors', function () {
    declareOwed($this->insurer, paid: 0, owed: 5);

    // Its row withholds the outstanding; the summary minus the published row
    // would give its single creditor's debt back.
    $other = Insurer::factory()->create();
    declareOwed($other, paid: 5, owed: 1);

    $summary = $this->service->aggregatedAmounts(new Period(2026, 8), new Period(2026, 8));

    expect($summary['withheld'])->toBeFalse()
        ->and($summary['invoiced'])->toBe(11_000_000)
        ->and($summary['outstanding'])->toBeNull()
        ->and($summary['owedWithheld'])->toBeTrue();

    // Once that row rests on five creditors it publishes, and nothing is
    // hidden behind the summary any more.
    declareOwed($other, paid: 0, owed: 4);

    expect($this->service->aggregatedAmounts(new Period(2026, 8), new Period(2026, 8))['outstanding'])->toBe(4_000_000);
});

test('the same holds for the summary of one city', function () {
    declareOwed($this->insurer, paid: 0, owed: 5, city: 'Parakou');
    $other = Insurer::factory()->create();
    declareOwed($other, paid: 5, owed: 1, city: 'Parakou');

    expect($this->service->aggregatedAmounts(new Period(2026, 8), new Period(2026, 8), 'Parakou')['outstanding'])->toBeNull();
});

test('the trends screen says the owed amounts are withheld', function () {
    declareOwed($this->insurer, paid: 5, owed: 1);

    $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('admin.trends', ['period' => 'last-12-months']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('amounts.0.sufficient', true)
            ->where('amounts.0.owedWithheld', true)
            ->where('amounts.0.outstanding', null)
            ->where('amounts.0.invoiced', 6_000_000)
            ->where('summary.owedWithheld', true),
        );
});
