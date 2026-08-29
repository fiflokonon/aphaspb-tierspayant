<?php

use App\Data\InsufficientData;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkStatsService;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->service = app(NetworkStatsService::class);
    $this->from = new Period(2026, 8);
    $this->to = new Period(2026, 8);
});

/**
 * Give an insurer declarations from exactly $count distinct pharmacies.
 */
function declareFrom(Insurer $insurer, int $count): void
{
    Pharmacy::factory()->count($count)->create()->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]),
    );
}

test('an insurer just under the threshold yields no figures at all', function () {
    $insurer = Insurer::factory()->create();
    declareFrom($insurer, 4);

    $result = $this->service->perInsurer($this->from, $this->to)[$insurer->id];

    expect($result)->toBeInstanceOf(InsufficientData::class)
        ->and($result->declaringPharmacies)->toBe(4)
        ->and($result->required)->toBe(5);
});

test('an insurer at the threshold yields its indicators', function () {
    $insurer = Insurer::factory()->create();
    declareFrom($insurer, 5);

    $result = $this->service->perInsurer($this->from, $this->to)[$insurer->id];

    expect($result)->toBeInstanceOf(InsurerIndicators::class)
        ->and($result->declaringPharmacies)->toBe(5);
});

test('many declarations from few pharmacies stay below the threshold', function () {
    $insurer = Insurer::factory()->create();
    $pharmacies = Pharmacy::factory()->count(2)->create();

    foreach ($pharmacies as $pharmacy) {
        foreach (range(1, 8) as $month) {
            Declaration::factory()->paid()->create([
                'pharmacy_id' => $pharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => $month,
            ]);
        }
    }

    $result = $this->service->perInsurer(new Period(2026, 1), new Period(2026, 8))[$insurer->id];

    expect($result)->toBeInstanceOf(InsufficientData::class)
        ->and($result->declaringPharmacies)->toBe(2);
});

test('the threshold follows the admin setting', function () {
    app(SettingsRepository::class)->set('anonymity_min_pharmacies', 3);

    $insurer = Insurer::factory()->create();
    declareFrom($insurer, 3);

    expect($this->service->perInsurer($this->from, $this->to)[$insurer->id])
        ->toBeInstanceOf(InsurerIndicators::class);
});

test('an insufficient insurer is absent from the delay trend', function () {
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));

    $shown = Insurer::factory()->create(['name' => 'Assez de declarants']);
    $hidden = Insurer::factory()->create(['name' => 'Trop peu de declarants']);

    declareFrom($shown, 5);
    declareFrom($hidden, 2);

    $trend = $this->service->delayTrend(...Period::lastMonths(12));

    expect($trend['insurers'])->toHaveKey($shown->id)
        ->and($trend['insurers'])->not->toHaveKey($hidden->id);
});

test('no aggregate exposes anything traceable to one pharmacy', function () {
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));

    $insurer = Insurer::factory()->create();
    $pharmacies = Pharmacy::factory()->count(5)->create();

    foreach ($pharmacies as $pharmacy) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'private_note' => 'note privee a ne jamais divulguer',
        ]);
    }

    $serialised = json_encode([
        $this->service->perInsurer($this->from, $this->to),
        $this->service->delayTrend(...Period::lastMonths(12)),
        $this->service->aggregatedAmounts($this->from, $this->to),
    ], JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain('note privee')
        ->and($serialised)->not->toContain('pharmacy_id')
        ->and($serialised)->not->toContain('private_note');

    foreach ($pharmacies as $pharmacy) {
        expect($serialised)->not->toContain($pharmacy->name);
    }
});

test('the masked insurer count reports how many are hidden this quarter', function () {
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));

    $shown = Insurer::factory()->create();
    $hiddenOne = Insurer::factory()->create();
    $hiddenTwo = Insurer::factory()->create();

    declareFrom($shown, 5);
    declareFrom($hiddenOne, 2);
    declareFrom($hiddenTwo, 4);

    expect($this->service->maskedInsurerCount())->toBe(2);
});

test('the masked insurer count is zero when every insurer clears the threshold', function () {
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));

    declareFrom(Insurer::factory()->create(), 5);
    declareFrom(Insurer::factory()->create(), 6);

    expect($this->service->maskedInsurerCount())->toBe(0);
});

test('the current quarter spans three months ending on the current one', function () {
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));

    [$from, $to] = Period::currentQuarter();

    expect($from->year)->toBe(2026)
        ->and($from->month)->toBe(7)
        ->and($to->year)->toBe(2026)
        ->and($to->month)->toBe(9);
});
