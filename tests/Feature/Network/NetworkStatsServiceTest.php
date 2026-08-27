<?php

use App\Data\InsufficientData;
use App\Data\InsurerAmounts;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkStatsService;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->service = app(NetworkStatsService::class);
    $this->insurer = Insurer::factory()->create();
});

/**
 * Record one declaration per pharmacy so the anonymity threshold is met.
 *
 * @param  list<array<string, mixed>>  $declarations
 */
function recordForDistinctPharmacies(Insurer $insurer, array $declarations): void
{
    foreach ($declarations as $attributes) {
        Declaration::factory()->create([
            ...$attributes,
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }
}

test('the average delay ignores unpaid and rejected declarations', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 20],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 40],
        ['amount_invoiced' => 100, 'amount_received' => 60, 'delay_days' => 60],
        ['amount_invoiced' => 100, 'amount_received' => 0, 'delay_days' => null],
        ['amount_invoiced' => 100, 'amount_received' => 0, 'delay_days' => null, 'status' => DeclarationStatus::Rejected, 'is_status_manual' => true],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators)->toBeInstanceOf(InsurerIndicators::class)
        ->and($indicators->averageDelayDays)->toBe(40.0);
});

test('the rejection and unpaid rates count against every declaration', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 0, 'delay_days' => null],
        ['amount_invoiced' => 100, 'amount_received' => 0, 'delay_days' => null, 'status' => DeclarationStatus::Rejected, 'is_status_manual' => true],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->unpaidRate)->toBe(20.0)
        ->and($indicators->rejectionRate)->toBe(20.0);
});

test('the within threshold share counts settled declarations under 30 days', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 30],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 31],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 90],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 5],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->withinThresholdShare)->toBe(60.0);
});

test('amounts are summed and the outstanding balance derived', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 1_000_000, 'amount_received' => 1_000_000, 'delay_days' => 12],
        ['amount_invoiced' => 1_000_000, 'amount_received' => 500_000, 'delay_days' => 40],
        ['amount_invoiced' => 1_000_000, 'amount_received' => 0, 'delay_days' => null],
        ['amount_invoiced' => 1_000_000, 'amount_received' => 250_000, 'delay_days' => 55],
        ['amount_invoiced' => 1_000_000, 'amount_received' => 250_000, 'delay_days' => 55],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->amountInvoiced)->toBe(5_000_000)
        ->and($indicators->amountReceived)->toBe(2_000_000)
        ->and($indicators->amountOutstanding)->toBe(3_000_000)
        ->and($indicators->recoveryRate)->toBe(40.0);
});

test('the city filter narrows the aggregate to one city', function () {
    foreach (['Cotonou', 'Cotonou', 'Cotonou', 'Cotonou', 'Cotonou', 'Parakou'] as $city) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
            'insurer_id' => $this->insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8), 'Cotonou')[$this->insurer->id];

    expect($indicators->declaringPharmacies)->toBe(5);
});

test('the aggregation costs two queries whatever the number of insurers', function () {
    Insurer::factory()->count(7)->create()->each(
        fn (Insurer $insurer) => Pharmacy::factory()->count(5)->create()->each(
            fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
                'pharmacy_id' => $pharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
            ]),
        ),
    );

    // Warm the settings cache first: this test guards against an N+1 over
    // insurers, not against the two threshold reads that precede it.
    app(SettingsRepository::class)->paymentDelayThresholdDays();
    app(SettingsRepository::class)->anonymityMinPharmacies();

    DB::enableQueryLog();

    $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8));

    // One grouped aggregate, one lookup of insurer names. Eight insurers, or
    // eight hundred, must not change this number.
    expect(DB::getQueryLog())->toHaveCount(2);
});

test('the network weighted delay differs from the plain average', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 100_000, 'amount_received' => 100_000, 'delay_days' => 10],
        ['amount_invoiced' => 900_000, 'amount_received' => 900_000, 'delay_days' => 100],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
    ]);

    $summary = $this->service->networkSummary(new Period(2026, 8), new Period(2026, 8));

    // Plain average: 28. Weighted by the money actually paid: 91.
    expect($summary['averageDelayDays'])->toBe(28.0)
        ->and($summary['weightedDelayDays'])->toBe(91.0);
});

test('the network outstanding beyond ninety days counts only old enough months', function () {
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));

    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 300_000, 'amount_received' => 0, 'delay_days' => null],
    ]);

    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $this->insurer->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 700_000,
        'amount_received' => 0,
        'delay_days' => null,
    ]);

    $summary = $this->service->networkSummary(new Period(2026, 1), new Period(2026, 8));

    expect($summary['outstandingBeyond90'])->toBe(700_000);
});

test('the aggregated amounts per insurer honour the anonymity threshold', function () {
    $shown = Insurer::factory()->create(['name' => 'Assez de declarants']);
    $hidden = Insurer::factory()->create(['name' => 'Trop peu']);

    foreach (range(1, 5) as $i) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $shown->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 700_000,
            'delay_days' => 30,
        ]);
    }

    foreach (range(1, 3) as $i) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $hidden->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    $rows = $this->service->aggregatedByInsurer(new Period(2026, 8), new Period(2026, 8));

    expect($rows[$shown->id])->toBeInstanceOf(InsurerAmounts::class)
        ->and($rows[$shown->id]->invoiced)->toBe(5_000_000)
        ->and($rows[$shown->id]->outstanding)->toBe(1_500_000)
        ->and($rows[$shown->id]->recoveryRate)->toBe(70.0)
        ->and($rows[$hidden->id])->toBeInstanceOf(InsufficientData::class);
});
