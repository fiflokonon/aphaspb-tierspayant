<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Pharmacy\PharmacyStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
    $this->stats = app(PharmacyStatsService::class);
    $this->pharmacy = Pharmacy::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function declareFor(Pharmacy $pharmacy, int $year, int $month, array $attributes = [], ?Insurer $insurer = null): Declaration
{
    return Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => ($insurer ?? Insurer::factory()->create())->id,
        'period_year' => $year,
        'period_month' => $month,
        ...$attributes,
    ]);
}

test('the summary sums what was invoiced and received, and derives the gap', function () {
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 1_000_000, 'amount_received' => 600_000, 'delay_days' => 20]);
    declareFor($this->pharmacy, 2026, 7, ['amount_invoiced' => 500_000, 'amount_received' => 500_000, 'delay_days' => 10]);

    $summary = $this->stats->summary($this->pharmacy, 12);

    expect($summary['invoiced'])->toBe(1_500_000)
        ->and($summary['received'])->toBe(1_100_000)
        ->and($summary['outstanding'])->toBe(400_000)
        ->and($summary['declarations'])->toBe(2);
});

test('the recovery rate is received over invoiced, and null without anything invoiced', function () {
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 1_000_000, 'amount_received' => 730_000, 'delay_days' => 30]);

    expect($this->stats->summary($this->pharmacy, 12)['recoveryRate'])->toBe(73.0)
        ->and($this->stats->summary(Pharmacy::factory()->create(), 12)['recoveryRate'])->toBeNull();
});

test('the average delay is weighted by the amounts received', function () {
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 100_000, 'amount_received' => 100_000, 'delay_days' => 10]);
    declareFor($this->pharmacy, 2026, 7, ['amount_invoiced' => 900_000, 'amount_received' => 900_000, 'delay_days' => 100]);

    // A plain average would say 55; weighting by the money says 91.
    expect($this->stats->summary($this->pharmacy, 12)['weightedDelayDays'])->toBe(91.0);
});

test('the weighted delay ignores unpaid and rejected declarations', function () {
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 100_000, 'amount_received' => 100_000, 'delay_days' => 40]);
    declareFor($this->pharmacy, 2026, 7, ['amount_invoiced' => 900_000, 'amount_received' => 0, 'delay_days' => null]);
    declareFor($this->pharmacy, 2026, 6, [
        'amount_invoiced' => 900_000, 'amount_received' => 0, 'delay_days' => null,
        'status' => DeclarationStatus::Rejected, 'is_status_manual' => true,
    ]);

    expect($this->stats->summary($this->pharmacy, 12)['weightedDelayDays'])->toBe(40.0);
});

test('the monthly journey covers every month of the window, empty ones included', function () {
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 400_000, 'amount_received' => 100_000, 'delay_days' => 30]);

    $journey = $this->stats->monthlyJourney($this->pharmacy, 12);

    expect($journey)->toHaveCount(12)
        ->and($journey[11]['invoiced'])->toBe(400_000)
        ->and($journey[11]['outstanding'])->toBe(300_000)
        ->and($journey[0]['invoiced'])->toBe(0)
        ->and($journey[0]['label'])->toBe('S');
});

test('the current month is marked in the journey', function () {
    $journey = $this->stats->monthlyJourney($this->pharmacy, 12);

    expect(collect($journey)->where('isCurrent', true)->count())->toBe(1)
        ->and($journey[11]['isCurrent'])->toBeTrue();
});

test('ageing counts from the end of the declared month', function () {
    declareFor($this->pharmacy, 2026, 7, ['amount_invoiced' => 500_000, 'amount_received' => 0, 'delay_days' => null]);
    declareFor($this->pharmacy, 2026, 3, ['amount_invoiced' => 800_000, 'amount_received' => 0, 'delay_days' => null]);

    $buckets = collect($this->stats->ageingBuckets($this->pharmacy))->keyBy('label');

    expect($buckets['0–30 j']->amount)->toBe(500_000)
        ->and($buckets['> 90 j']->amount)->toBe(800_000)
        ->and($buckets['31–60 j']->amount)->toBe(0);
});

test('ageing counts only what remains due, not what was invoiced', function () {
    declareFor($this->pharmacy, 2026, 7, ['amount_invoiced' => 500_000, 'amount_received' => 500_000, 'delay_days' => 12]);

    expect(collect($this->stats->ageingBuckets($this->pharmacy))->sum('amount'))->toBe(0);
});

test('who owes the most is sorted descending and keeps settled insurers at zero', function () {
    $big = Insurer::factory()->create(['name' => 'Grosse dette']);
    $small = Insurer::factory()->create(['name' => 'Petite dette']);
    $clear = Insurer::factory()->create(['name' => 'À jour']);

    $this->pharmacy->insurers()->attach([$big->id, $small->id, $clear->id]);

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 1_000_000, 'amount_received' => 0, 'delay_days' => null], $big);
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 500_000, 'amount_received' => 300_000, 'delay_days' => 20], $small);
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 400_000, 'amount_received' => 400_000, 'delay_days' => 15], $clear);

    $owed = $this->stats->outstandingByInsurer($this->pharmacy, 12);

    expect(array_column($owed, 'insurerName'))->toBe(['Grosse dette', 'Petite dette', 'À jour'])
        ->and($owed[0]['outstanding'])->toBe(1_000_000)
        ->and($owed[2]['outstanding'])->toBe(0);
});

test('who owes the most ignores declarations older than the window', function () {
    $insurer = Insurer::factory()->create(['name' => 'Assureur']);

    $this->pharmacy->insurers()->attach($insurer->id);

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 300_000, 'amount_received' => 0, 'delay_days' => null], $insurer);
    declareFor($this->pharmacy, 2025, 8, ['amount_invoiced' => 900_000, 'amount_received' => 0, 'delay_days' => null], $insurer);

    $owed = $this->stats->outstandingByInsurer($this->pharmacy, 12);

    expect($owed[0]['outstanding'])->toBe(300_000);
});

test('recovery by insurer gives the amounts and the rate of each one', function () {
    $arch = Insurer::factory()->create(['name' => 'ARCH']);
    $nsia = Insurer::factory()->create(['name' => 'NSIA']);

    $this->pharmacy->insurers()->attach([$arch->id, $nsia->id]);

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 1_000_000, 'amount_received' => 480_000, 'delay_days' => 50], $arch);
    declareFor($this->pharmacy, 2026, 7, ['amount_invoiced' => 500_000, 'amount_received' => 430_000, 'delay_days' => 20], $nsia);

    $recovery = collect($this->stats->recoveryByInsurer($this->pharmacy, 12))->keyBy('insurerName');

    expect($recovery['ARCH']['invoiced'])->toBe(1_000_000)
        ->and($recovery['ARCH']['received'])->toBe(480_000)
        ->and($recovery['ARCH']['outstanding'])->toBe(520_000)
        ->and($recovery['ARCH']['recoveryRate'])->toBe(48.0)
        ->and($recovery['NSIA']['recoveryRate'])->toBe(86.0);
});

test('recovery by insurer keeps a ticked insurer that never declared, without a rate', function () {
    $insurer = Insurer::factory()->create(['name' => 'Jamais déclaré']);

    $this->pharmacy->insurers()->attach($insurer->id);

    $recovery = $this->stats->recoveryByInsurer($this->pharmacy, 12);

    expect($recovery)->toHaveCount(1)
        ->and($recovery[0]['insurerName'])->toBe('Jamais déclaré')
        ->and($recovery[0]['invoiced'])->toBe(0)
        ->and($recovery[0]['recoveryRate'])->toBeNull();
});

test('recovery by insurer is sorted by outstanding descending', function () {
    $big = Insurer::factory()->create(['name' => 'Grosse dette']);
    $small = Insurer::factory()->create(['name' => 'Petite dette']);

    $this->pharmacy->insurers()->attach([$big->id, $small->id]);

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 1_000_000, 'amount_received' => 0, 'delay_days' => null], $big);
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 500_000, 'amount_received' => 300_000, 'delay_days' => 20], $small);

    expect(array_column($this->stats->recoveryByInsurer($this->pharmacy, 12), 'insurerName'))
        ->toBe(['Grosse dette', 'Petite dette']);
});

test('recovery by insurer ignores declarations older than the window', function () {
    $insurer = Insurer::factory()->create(['name' => 'Assureur']);

    $this->pharmacy->insurers()->attach($insurer->id);

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 400_000, 'amount_received' => 400_000, 'delay_days' => 10], $insurer);
    declareFor($this->pharmacy, 2025, 8, ['amount_invoiced' => 900_000, 'amount_received' => 0, 'delay_days' => null], $insurer);

    $recovery = $this->stats->recoveryByInsurer($this->pharmacy, 12);

    expect($recovery[0]['invoiced'])->toBe(400_000)
        ->and($recovery[0]['recoveryRate'])->toBe(100.0);
});

test('recovery by insurer never reads another officine declarations', function () {
    $insurer = Insurer::factory()->create(['name' => 'Assureur']);
    $other = Pharmacy::factory()->create();

    $this->pharmacy->insurers()->attach($insurer->id);

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 100_000, 'amount_received' => 100_000, 'delay_days' => 10], $insurer);
    declareFor($other, 2026, 8, ['amount_invoiced' => 9_000_000, 'amount_received' => 0, 'delay_days' => null], $insurer);

    $recovery = $this->stats->recoveryByInsurer($this->pharmacy, 12);

    expect($recovery)->toHaveCount(1)
        ->and($recovery[0]['invoiced'])->toBe(100_000);
});

test('the outstanding beyond a given age is summed', function () {
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 300_000, 'amount_received' => 0, 'delay_days' => null]);
    declareFor($this->pharmacy, 2026, 4, ['amount_invoiced' => 700_000, 'amount_received' => 0, 'delay_days' => null]);

    expect($this->stats->outstandingBeyond($this->pharmacy, 60))->toBe(700_000);
});

test('an officine only ever sees its own declarations', function () {
    $other = Pharmacy::factory()->create();

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 100_000, 'amount_received' => 100_000, 'delay_days' => 10]);
    declareFor($other, 2026, 8, ['amount_invoiced' => 9_000_000, 'amount_received' => 0, 'delay_days' => null]);

    expect($this->stats->summary($this->pharmacy, 12)['invoiced'])->toBe(100_000)
        ->and($this->stats->summary($other, 12)['invoiced'])->toBe(9_000_000);
});

test('the summary costs a bounded number of queries', function () {
    Insurer::factory()->count(7)->create()->each(function (Insurer $insurer) {
        $this->pharmacy->insurers()->attach($insurer);

        foreach (range(1, 8) as $month) {
            declareFor($this->pharmacy, 2026, $month, ['amount_invoiced' => 100_000, 'amount_received' => 50_000, 'delay_days' => 40], $insurer);
        }
    });

    DB::enableQueryLog();

    $this->stats->summary($this->pharmacy, 12);

    expect(DB::getQueryLog())->toHaveCount(2);
});

test('the monthly journey can be narrowed to a single insurer', function () {
    $mutuelle = Insurer::factory()->create();
    $autre = Insurer::factory()->create();

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 400_000, 'amount_received' => 100_000], $mutuelle);
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 900_000, 'amount_received' => 900_000], $autre);

    $journey = $this->stats->monthlyJourney($this->pharmacy, 12, $mutuelle->id);

    expect($journey)->toHaveCount(12)
        ->and($journey[11]['invoiced'])->toBe(400_000)
        ->and($journey[11]['received'])->toBe(100_000)
        ->and($journey[11]['outstanding'])->toBe(300_000);
});

test('the monthly journey covers every insurer when none is given', function () {
    $mutuelle = Insurer::factory()->create();
    $autre = Insurer::factory()->create();

    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 400_000, 'amount_received' => 100_000], $mutuelle);
    declareFor($this->pharmacy, 2026, 8, ['amount_invoiced' => 900_000, 'amount_received' => 900_000], $autre);

    expect($this->stats->monthlyJourney($this->pharmacy, 12)[11]['invoiced'])->toBe(1_300_000);
});
