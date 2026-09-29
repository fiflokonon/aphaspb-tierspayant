<?php

use App\Data\InsufficientData;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\InsurerPenaltyAggregates;
use App\Services\Network\NetworkPdfExport;
use App\Services\Network\NetworkPenaltyJournal;
use App\Services\Network\NetworkStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les compartiments de ville suivent la collation de la base, pas la chaîne.
 *
 * Le filtre ville compare en SQL (`=`, donc la collation) ; les compartiments
 * de la règle de partition sont formés en PHP. En production (MySQL, collation
 * `_ci`), « Bohicon » et « bohicon » sont une seule ville filtrable : ils
 * doivent être un seul compartiment, sinon la part cachée H est fausse.
 *
 * SQLite, pilote des tests, compare à l'octet près. `caseInsensitiveCities()`
 * redéclare la colonne en `COLLATE NOCASE` le temps d'un test (le
 * RefreshDatabase annule ce changement) : c'est l'équivalent le plus proche
 * d'une collation MySQL insensible à la casse.
 */
beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->stats = app(NetworkStatsService::class);
    $this->insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
});

function caseInsensitiveCities(): void
{
    Schema::table('pharmacies', fn (Blueprint $table) => $table->string('city')->collation('nocase')->nullable()->change());
}

/**
 * $count officines de la ville écrite telle quelle, chacune une facture de
 * mars jamais réglée (elle court à partir de mai).
 *
 * @return list<Pharmacy>
 */
function unpaidIn(Insurer $insurer, ?string $city, int $count, int $month = 3): array
{
    $pharmacies = [];

    foreach (range(1, $count) as $ignored) {
        $pharmacy = Pharmacy::factory()->create(['city' => $city]);
        $pharmacies[] = $pharmacy;

        Declaration::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => $month,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 0,
            'status' => DeclarationStatus::Unpaid,
            'is_status_manual' => true,
            'invoice_deposited_on' => '2026-03-31',
            'paid_on' => null,
            'delay_days' => null,
        ]);
    }

    return $pharmacies;
}

/**
 * Bohicon s'écrit de deux façons (3 + 2 officines), Parakou n'en a qu'une.
 * Une collation insensible à la casse voit Bohicon à 5 (publiable) : la part
 * cachée vaut 1, retenue. Des compartiments à l'octet près verraient 3 + 2 + 1
 * = 6 et publieraient — la fuite que la règle doit empêcher.
 */
function mixedCaseDecor(Insurer $insurer): void
{
    unpaidIn($insurer, 'Cotonou', 5);
    unpaidIn($insurer, 'Bohicon', 3);
    unpaidIn($insurer, 'bohicon', 2);
    unpaidIn($insurer, 'Parakou', 1);
}

test('the filterable cities follow the collation', function () {
    Pharmacy::factory()->create(['city' => 'Bohicon']);
    Pharmacy::factory()->create(['city' => 'bohicon']);
    Pharmacy::factory()->create(['city' => 'Cotonou']);

    expect(Pharmacy::filterableCities())->toBe(['Bohicon', 'Cotonou', 'bohicon']);

    caseInsensitiveCities();

    // Une entrée par ville que le filtre distingue, son représentant étant le
    // plus petit selon la collation.
    expect(Pharmacy::filterableCities())->toBe(['Bohicon', 'Cotonou']);
});

test('under an exact collation, differently cased cities are two cities for the filter and the buckets alike', function () {
    mixedCaseDecor($this->insurer);
    $period = [new Period(2026, 3), new Period(2026, 3)];

    // L'octet près : « bohicon » (2) est une ville à part, filtrable seule.
    expect($this->stats->aggregatedAmounts(...[...$period, 'bohicon'])['withheldReason'])->toBe('too-few')
        ->and($this->stats->aggregatedAmounts(...$period)['withheld'])->toBeFalse();
});

test('under a case-insensitive collation, the summary and the insurer row follow the filter', function () {
    caseInsensitiveCities();
    mixedCaseDecor($this->insurer);
    $period = [new Period(2026, 3), new Period(2026, 3)];

    expect($this->stats->aggregatedAmounts(...[...$period, 'bohicon'])['declaringPharmacies'])->toBe(5)
        ->and($this->stats->aggregatedAmounts(...$period)['withheldReason'])->toBe('city-share')
        ->and($this->stats->networkSummary(...$period)['withheld'])->toBeTrue()
        ->and($this->stats->perInsurer(...$period)[$this->insurer->id])->toBeInstanceOf(InsufficientData::class);
});

test('under a case-insensitive collation, curve points and report months follow the filter', function () {
    caseInsensitiveCities();

    // Juillet : 5 partout, rien de caché. Août : Parakou n'en a plus qu'une.
    $parakou = unpaidIn($this->insurer, 'Parakou', 5, month: 7);
    unpaidIn($this->insurer, 'Cotonou', 5, month: 8);
    unpaidIn($this->insurer, 'Bohicon', 3, month: 7);
    unpaidIn($this->insurer, 'bohicon', 2, month: 7);

    foreach (Pharmacy::query()->where('city', 'bohicon')->get() as $pharmacy) {
        Declaration::factory()->paid()->create(['pharmacy_id' => $pharmacy->id, 'insurer_id' => $this->insurer->id, 'period_year' => 2026, 'period_month' => 8, 'delay_days' => 30]);
    }

    Declaration::factory()->paid()->create(['pharmacy_id' => $parakou[0]->id, 'insurer_id' => $this->insurer->id, 'period_year' => 2026, 'period_month' => 8, 'delay_days' => 30]);
    DB::table('declarations')->where('period_month', 8)->update(['status' => 'paid', 'amount_received' => DB::raw('amount_invoiced'), 'delay_days' => 30]);

    $trend = $this->stats->delayTrend(new Period(2026, 7), new Period(2026, 8));

    expect($this->stats->perInsurer(new Period(2026, 7), new Period(2026, 8))[$this->insurer->id])->toBeInstanceOf(InsurerIndicators::class)
        ->and($trend['insurers'][$this->insurer->id]['withheld'])->toBe(['2026-08']);

    $export = app(NetworkPdfExport::class);
    $months = collect((new ReflectionMethod($export, 'data'))->invoke($export, new Period(2026, 7), new Period(2026, 8), null)['rows'][0]['monthly'])->keyBy('month');

    expect($months[8]['withheld'])->toBeTrue();
});

test('under a case-insensitive collation, the journal and the penalty split follow the filter', function () {
    caseInsensitiveCities();
    mixedCaseDecor($this->insurer);

    $journal = app(NetworkPenaltyJournal::class)->for(new Period(2026, 3), new Period(2026, 9));
    $figures = app(InsurerPenaltyAggregates::class)->forInsurers([$this->insurer->id], new Period(2026, 3), new Period(2026, 3));

    expect($journal->total->month('2026-05')->withheld)->toBeTrue()
        ->and(array_keys($figures[$this->insurer->id]->citySplitPharmacies))->toBe(['Cotonou', 'Bohicon', 'Parakou'])
        ->and($figures[$this->insurer->id]->citySplitPharmacies['Bohicon']->due)->toBe(5);
});

test('on MySQL, accents and case make one city for the filter and the buckets', function () {
    // Pas de MySQL en CI aujourd'hui : ce test ne tourne que branché sur la
    // base de production (collation `_ai_ci`).
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL uniquement.');
    }

    unpaidIn($this->insurer, 'Cotonou', 5);
    unpaidIn($this->insurer, 'Bohicon', 3);
    unpaidIn($this->insurer, 'bohicón', 2);
    unpaidIn($this->insurer, 'Parakou', 1);
    $period = [new Period(2026, 3), new Period(2026, 3)];

    expect(Pharmacy::filterableCities())->toHaveCount(3)
        ->and($this->stats->aggregatedAmounts(...[...$period, 'Bohicon'])['declaringPharmacies'])->toBe(5)
        ->and($this->stats->aggregatedAmounts(...$period)['withheldReason'])->toBe('city-share');
})->group('mysql');
