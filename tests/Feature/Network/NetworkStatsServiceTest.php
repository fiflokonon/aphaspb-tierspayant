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

/**
 * Record one paid declaration per pharmacy in a given month.
 */
function recordForDistinctPharmaciesIn(Insurer $insurer, int $year, int $month, int $count): void
{
    foreach (range(1, $count) as $i) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => $year,
            'period_month' => $month,
            'delay_days' => 30,
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

test('the recovered share counts the money that arrived inside the standard delay', function () {
    // Seuil standard : 30 jours. Chaque officine règle 1 000 000 facturés en
    // deux fois — 400 000 à J+10, 600 000 à J+50 —, donc 40 % de l'argent
    // seulement est arrivé dans les clous.
    foreach (range(1, 5) as $i) {
        Declaration::factory()
            ->instalments([
                ['amount' => 400_000, 'paid_on' => '2026-08-11'],
                ['amount' => 600_000, 'paid_on' => '2026-09-20'],
            ])
            ->create([
                'pharmacy_id' => Pharmacy::factory(),
                'insurer_id' => $this->insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
                'amount_invoiced' => 1_000_000,
                'invoice_deposited_on' => '2026-08-01',
            ]);
    }

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->recoveredWithinDelayShare)->toBe(40.0)
        // Le mois est jugé sur son dernier versement : 50 jours, hors délai.
        // C'est bien le point de l'indicateur : compté par déclaration, cet
        // assureur est à 0 % ; il a pourtant fait rentrer 40 % de l'argent.
        ->and($indicators->averageDelayDays)->toBe(50.0)
        ->and($indicators->withinThresholdShare)->toBe(0.0);
});

test('what an insurer never paid weighs against its recovered share', function () {
    foreach (range(1, 5) as $i) {
        Declaration::factory()
            ->instalments([['amount' => 500_000, 'paid_on' => '2026-08-11']])
            ->create([
                'pharmacy_id' => Pharmacy::factory(),
                'insurer_id' => $this->insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
                'amount_invoiced' => 1_000_000,
                'invoice_deposited_on' => '2026-08-01',
            ]);
    }

    // La moitié facturée est arrivée à J+10, l'autre moitié n'est jamais
    // arrivée : la part recouvrée dans le délai se mesure sur le facturé, donc
    // ce qui n'a pas été payé du tout pèse comme ce qui a été payé en retard.
    expect($this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id]->recoveredWithinDelayShare)
        ->toBe(50.0);
});

test('the recovered share is judged against each insurer own standard delay', function () {
    $lenient = Insurer::factory()->create(['standard_delay_days' => 90]);

    foreach ([$this->insurer, $lenient] as $insurer) {
        foreach (range(1, 5) as $i) {
            Declaration::factory()
                ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-09-15']])
                ->create([
                    'pharmacy_id' => Pharmacy::factory(),
                    'insurer_id' => $insurer->id,
                    'period_year' => 2026,
                    'period_month' => 8,
                    'amount_invoiced' => 1_000_000,
                    'invoice_deposited_on' => '2026-08-01',
                ]);
        }
    }

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8));

    // Quarante-cinq jours : hors délai pour l'assureur à 30 jours, dans les
    // clous pour celui à 90.
    expect($indicators[$this->insurer->id]->recoveredWithinDelayShare)->toBe(0.0)
        ->and($indicators[$lenient->id]->recoveredWithinDelayShare)->toBe(100.0);
});

test('an insurer that received nothing keeps an honest zero recovered', function () {
    recordForDistinctPharmacies($this->insurer, array_fill(0, 5, [
        'amount_invoiced' => 1_000_000, 'amount_received' => 0, 'delay_days' => null,
    ]));

    expect($this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id]->recoveredWithinDelayShare)
        ->toBe(0.0);
});

test('money received without any instalment to date it reads as unknown, not zero', function () {
    // L'état de toute déclaration antérieure aux dates de paiement : un montant
    // encaissé, aucune ligne pour dire quand. Annoncer « 0 % recouvré dans les
    // délais » serait une accusation tirée d'une donnée absente.
    foreach (range(1, 5) as $i) {
        $declaration = Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $this->insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 800_000,
            'delay_days' => 20,
        ]);

        $declaration->payments()->delete();
    }

    expect($this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id]->recoveredWithinDelayShare)
        ->toBeNull();
});

test('a transfer with no delay to measure does not shorten the first-transfer average', function () {
    foreach (range(1, 4) as $i) {
        Declaration::factory()
            ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-08-21']])
            ->create([
                'pharmacy_id' => Pharmacy::factory(),
                'insurer_id' => $this->insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
                'amount_invoiced' => 1_000_000,
                'invoice_deposited_on' => '2026-08-01',
            ]);
    }

    // Un versement repris par la migration depuis une déclaration sans date de
    // dépôt porte un délai NULL : SUM l'ignore, donc le compter au
    // dénominateur rabaisserait le délai du premier versement.
    $undated = Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $this->insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
    ]);

    $undated->payments()->delete();
    $undated->payments()->create(['amount' => 1_000_000, 'paid_on' => '2026-08-21', 'delay_days' => null]);

    expect($this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id]->averageFirstInstalmentDelayDays)
        ->toBe(20.0);
});

test('money not backed by any transfer makes the recovered share unknown, even beside documented months', function () {
    foreach (range(1, 4) as $i) {
        Declaration::factory()
            ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-08-11']])
            ->create([
                'pharmacy_id' => Pharmacy::factory(),
                'insurer_id' => $this->insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
                'amount_invoiced' => 1_000_000,
                'invoice_deposited_on' => '2026-08-01',
            ]);
    }

    // Un seul mois encaissé sans détail suffit : un drapeau « cet assureur
    // a-t-il des versements ? » laisserait passer le chiffre et lui refuserait
    // silencieusement le crédit de cet argent.
    $undocumented = Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $this->insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'delay_days' => 5,
    ]);

    $undocumented->payments()->delete();

    expect($this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id]->recoveredWithinDelayShare)
        ->toBeNull();
});

test('the instalment figures describe how an insurer settles a month', function () {
    // Trois officines réglées en une fois, deux en deux fois : le fractionnement
    // ne se lit ni dans les montants ni dans les délais des déclarations.
    foreach (range(1, 3) as $i) {
        Declaration::factory()
            ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-08-21']])
            ->create([
                'pharmacy_id' => Pharmacy::factory(),
                'insurer_id' => $this->insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
                'amount_invoiced' => 1_000_000,
                'invoice_deposited_on' => '2026-08-01',
            ]);
    }

    foreach (range(1, 2) as $i) {
        Declaration::factory()
            ->instalments([
                ['amount' => 400_000, 'paid_on' => '2026-08-06'],
                ['amount' => 600_000, 'paid_on' => '2026-08-26'],
            ])
            ->create([
                'pharmacy_id' => Pharmacy::factory(),
                'insurer_id' => $this->insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
                'amount_invoiced' => 1_000_000,
                'invoice_deposited_on' => '2026-08-01',
            ]);
    }

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->instalments)->toBe(7)
        ->and($indicators->instalmentsPerDeclaration)->toBe(1.4)
        ->and($indicators->multiInstalmentShare)->toBe(40.0)
        // Premier versement : 20 j pour trois d'entre elles, 5 j pour les deux
        // fractionnées — soit 14 en moyenne. Le délai des déclarations, lui,
        // se compte au dernier versement : 20, 20, 20, 25, 25, soit 22. L'écart
        // entre les deux chiffres est exactement ce que le fractionnement coûte.
        ->and($indicators->averageFirstInstalmentDelayDays)->toBe(14.0)
        ->and($indicators->averageDelayDays)->toBe(22.0);
});

test('a month that received nothing does not dilute the instalment averages', function () {
    foreach (range(1, 4) as $i) {
        Declaration::factory()
            ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-08-21']])
            ->create([
                'pharmacy_id' => Pharmacy::factory(),
                'insurer_id' => $this->insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
                'amount_invoiced' => 1_000_000,
                'invoice_deposited_on' => '2026-08-01',
            ]);
    }

    Declaration::factory()->unpaid()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $this->insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
    ]);

    // Cinq déclarations, quatre encaissées : « 0,8 versement par déclaration »
    // laisserait croire que l'assureur paie par fractions.
    expect($this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id]->instalmentsPerDeclaration)
        ->toBe(1.0);
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

test('the within threshold share is judged against the insurer own standard delay', function () {
    $this->insurer->update(['standard_delay_days' => 60]);

    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 30],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 31],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 90],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 5],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    // The same five delays that give 60 % against thirty days give 80 % here.
    expect($indicators->withinThresholdShare)->toBe(80.0)
        ->and($indicators->standardDelayDays)->toBe(60);
});

test('the network within threshold share honours each insurer own standard delay', function () {
    $lenient = Insurer::factory()->create(['standard_delay_days' => 90]);

    // Trois déclarations de chaque côté, six officines distinctes : le résumé
    // réseau est retenu sous le seuil de 5 depuis le 28/09/2026.
    $late = array_fill(0, 3, ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 45]);
    recordForDistinctPharmacies($this->insurer, $late);
    recordForDistinctPharmacies($lenient, $late);

    // Forty-five days: late for the thirty-day insurer, on time for the other.
    expect($this->service->networkSummary(new Period(2026, 8), new Period(2026, 8))['withinThresholdShare'])
        ->toBe(50.0);
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

test('the network totals narrow to one city like every other aggregate', function () {
    // Cinq à Cotonou (une seule officine y serait retenue depuis le
    // 28/09/2026), une à Parakou, au montant distinct pour que le filtre se
    // voie.
    foreach ([...array_fill(0, 5, 'Cotonou'), 'Parakou'] as $city) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
            'insurer_id' => $this->insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => $city === 'Parakou' ? 9_000_000 : 1_000_000,
            'amount_received' => 400_000,
            'delay_days' => 20,
        ]);
    }

    $totals = $this->service->aggregatedAmounts(new Period(2026, 8), new Period(2026, 8), 'Cotonou');

    expect($totals['withheld'])->toBeFalse()
        ->and($totals['invoiced'])->toBe(5_000_000)
        ->and($totals['declaringPharmacies'])->toBe(5);
});

test('the delay curve follows the bounds it is handed', function () {
    foreach ([[2026, 8], [2026, 2]] as [$year, $month]) {
        recordForDistinctPharmaciesIn($this->insurer, $year, $month, 5);
    }

    // A month without declarations carries no point — the chart fills the gaps.
    expect(array_keys($this->service->delayTrend(new Period(2026, 1), new Period(2026, 8))['network']))
        ->toBe(['2026-02', '2026-08'])
        ->and(array_keys($this->service->delayTrend(new Period(2026, 7), new Period(2026, 8))['network']))
        ->toBe(['2026-08']);
});

test('the delay curve narrows to one city', function () {
    foreach (range(1, 5) as $i) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => 'Cotonou']),
            'insurer_id' => $this->insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'delay_days' => 10,
        ]);
    }

    Declaration::factory()->paid()->create([
        'pharmacy_id' => Pharmacy::factory()->create(['city' => 'Parakou']),
        'insurer_id' => $this->insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'delay_days' => 200,
    ]);

    $trend = $this->service->delayTrend(new Period(2026, 8), new Period(2026, 8), 'Cotonou');

    expect($trend['network']['2026-08'])->toBe(10.0);
});

test('the aggregation costs a fixed number of queries whatever the number of insurers', function () {
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
    // insurers, not against the anonymity read that precedes it.
    app(SettingsRepository::class)->anonymityMinPharmacies();

    DB::enableQueryLog();

    $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8));

    // One grouped aggregate over the declarations, one over the instalments,
    // one lookup of insurer names, and — since the city partition rule of
    // 28/09/2026 — one count of declarants per insurer and city (unfiltered
    // by city only). Eight insurers, or eight hundred, must not change this
    // number.
    expect(DB::getQueryLog())->toHaveCount(4);
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

    // Quatre officines réglées en août s'ajoutent pour que le résumé repose sur
    // le seuil (retenu en deçà depuis le 28/09/2026) ; elles ne doivent rien.
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 300_000, 'amount_received' => 0, 'delay_days' => null],
        ...array_fill(0, 4, ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10]),
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

test('an insurer filter narrows the per-insurer aggregate to that insurer alone', function () {
    $other = Insurer::factory()->create();

    recordForDistinctPharmaciesIn($this->insurer, 2026, 8, 5);
    recordForDistinctPharmaciesIn($other, 2026, 8, 5);

    $everyone = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8));
    $filtered = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8), null, $this->insurer->id);

    expect(array_keys($everyone))->toContain($this->insurer->id, $other->id)
        ->and(array_keys($filtered))->toBe([$this->insurer->id]);
});

test('an insurer filter narrows the network summary, which otherwise spans every insurer', function () {
    $other = Insurer::factory()->create();

    recordForDistinctPharmaciesIn($this->insurer, 2026, 8, 5);
    recordForDistinctPharmaciesIn($other, 2026, 8, 5);

    $everyone = $this->service->networkSummary(new Period(2026, 8), new Period(2026, 8));
    $filtered = $this->service->networkSummary(new Period(2026, 8), new Period(2026, 8), null, $this->insurer->id);

    // Le résumé est l'agrégat que le filtre doit le plus visiblement resserrer :
    // c'est lui qui ouvre le document.
    expect($everyone['declarations'])->toBe(10)
        ->and($filtered['declarations'])->toBe(5);
});

test('the network summary resting on fewer officines than the threshold is withheld, even unfiltered', function () {
    // Un réseau au lancement : quatre déclarantes. Publié, le résumé dirait
    // 4 officines, 4 déclarations, 30 j — les chiffres d'officines que le
    // suivi des déclarations nomme.
    recordForDistinctPharmaciesIn($this->insurer, 2026, 8, 4);

    $summary = $this->service->networkSummary(new Period(2026, 8), new Period(2026, 8));
    $amounts = $this->service->aggregatedAmounts(new Period(2026, 8), new Period(2026, 8));

    expect($summary['withheld'])->toBeTrue()
        ->and($summary['required'])->toBe(5)
        ->and($summary['declaringPharmacies'])->toBeNull()
        ->and($summary['declarations'])->toBeNull()
        ->and($summary['averageDelayDays'])->toBeNull()
        ->and($summary['outstandingBeyond90'])->toBeNull()
        ->and($amounts['withheld'])->toBeTrue()
        ->and($amounts['invoiced'])->toBeNull()
        ->and($amounts['declaringPharmacies'])->toBeNull();
});

test('the network summary resting on the threshold is published', function () {
    recordForDistinctPharmaciesIn($this->insurer, 2026, 8, 5);

    $summary = $this->service->networkSummary(new Period(2026, 8), new Period(2026, 8));
    $amounts = $this->service->aggregatedAmounts(new Period(2026, 8), new Period(2026, 8));

    expect($summary['withheld'])->toBeFalse()
        ->and($summary['declaringPharmacies'])->toBe(5)
        ->and($summary['declarations'])->toBe(5)
        ->and($summary['averageDelayDays'])->toBe(30.0)
        ->and($amounts['withheld'])->toBeFalse()
        ->and($amounts['declaringPharmacies'])->toBe(5);
});

test('a network summary with nothing declared is published at zero, not withheld', function () {
    $summary = $this->service->networkSummary(new Period(2026, 8), new Period(2026, 8));

    expect($summary['withheld'])->toBeFalse()
        ->and($summary['declaringPharmacies'])->toBe(0)
        ->and($summary['declarations'])->toBe(0);
});

test('the city filter re-applies the threshold to the network summary', function () {
    // Six officines au total : le réseau entier est publié. Mais Parakou n'en
    // compte qu'une, au montant distinct, et ses chiffres seraient les siens.
    foreach ([...array_fill(0, 5, 'Cotonou'), 'Parakou'] as $city) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
            'insurer_id' => $this->insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => $city === 'Parakou' ? 4_210_000 : 1_000_000,
            'amount_received' => 0,
            'delay_days' => null,
        ]);
    }

    $everyone = $this->service->aggregatedAmounts(new Period(2026, 8), new Period(2026, 8));
    $parakou = $this->service->aggregatedAmounts(new Period(2026, 8), new Period(2026, 8), 'Parakou');
    $summary = $this->service->networkSummary(new Period(2026, 8), new Period(2026, 8), 'Parakou');

    // Non filtré, publié jusqu'au 28/09/2026 (9 210 000) : moins Cotonou, il
    // rendait Parakou. La règle de partition le retient désormais.
    expect($everyone['withheld'])->toBeTrue()
        ->and($everyone['withheldReason'])->toBe('city-share')
        ->and($everyone['invoiced'])->toBeNull()
        ->and($parakou['withheld'])->toBeTrue()
        ->and($parakou['withheldReason'])->toBe('too-few')
        ->and($parakou['invoiced'])->toBeNull()
        ->and($summary['withheld'])->toBeTrue()
        ->and($summary['declarations'])->toBeNull();
});

test('a delay curve month resting on fewer officines than the threshold is withheld', function () {
    // Cinq officines en août : l'assureur est autorisé sur la période. En
    // juillet une seule, à 200 j : publié, le point de juillet serait son délai.
    recordForDistinctPharmaciesIn($this->insurer, 2026, 8, 5);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $this->insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'delay_days' => 200,
    ]);

    $trend = $this->service->delayTrend(new Period(2026, 7), new Period(2026, 8));
    $series = $trend['insurers'][$this->insurer->id];

    expect($trend['network'])->toBe(['2026-08' => 30.0])
        ->and($trend['withheldMonths'])->toBe(['2026-07'])
        ->and($series['points'])->toBe(['2026-08' => 30.0])
        ->and($series['withheld'])->toBe(['2026-07'])
        ->and($trend['required'])->toBe(5);
});

test('the network curve averages published points only', function () {
    // Deux assureurs autorisés sur la période ; en juillet le second n'a
    // qu'une officine, à 200 j. L'inclure dans la moyenne réseau de juillet
    // la rendrait déductible : réseau × 2 − point visible.
    $other = Insurer::factory()->create();
    recordForDistinctPharmaciesIn($this->insurer, 2026, 7, 5);
    recordForDistinctPharmaciesIn($other, 2026, 8, 5);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $other->id,
        'period_year' => 2026,
        'period_month' => 7,
        'delay_days' => 200,
    ]);

    $trend = $this->service->delayTrend(new Period(2026, 7), new Period(2026, 8));

    expect($trend['network'])->toBe(['2026-07' => 30.0, '2026-08' => 30.0])
        ->and($trend['withheldMonths'])->toBe([])
        ->and($trend['insurers'][$other->id]['withheld'])->toBe(['2026-07']);
});

/**
 * $count officines de la ville, une déclaration payée chacune au mois dit.
 */
function declarePaidInCity(Insurer $insurer, ?string $city, int $count, int $month = 8, int $invoiced = 1_000_000): void
{
    Pharmacy::factory()->count($count)->create(['city' => $city])->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => $month,
            'amount_invoiced' => $invoiced,
            'amount_received' => $invoiced,
            'delay_days' => 30,
        ]),
    );
}

test('an unfiltered aggregate is withheld when the unpublishable cities hold 1 to threshold − 1 officines', function () {
    // Cotonou (5) est publiable ; Parakou (1) ne l'est pas. Non filtré moins
    // Cotonou rendrait Parakou, soit une officine : 4 210 000.
    declarePaidInCity($this->insurer, 'Cotonou', 5);
    declarePaidInCity($this->insurer, 'Parakou', 1, invoiced: 4_210_000);

    $period = [new Period(2026, 8), new Period(2026, 8)];

    $summary = $this->service->networkSummary(...$period);
    $amounts = $this->service->aggregatedAmounts(...$period);
    $row = $this->service->perInsurer(...$period)[$this->insurer->id];
    $amountRow = $this->service->aggregatedByInsurer(...$period)[$this->insurer->id];

    expect($summary['withheld'])->toBeTrue()
        ->and($summary['withheldReason'])->toBe('city-share')
        ->and($summary['declarations'])->toBeNull()
        ->and($amounts['withheld'])->toBeTrue()
        ->and($amounts['invoiced'])->toBeNull()
        ->and($row)->toBeInstanceOf(InsufficientData::class)
        ->and($row->cityShare)->toBeTrue()
        ->and($amountRow)->toBeInstanceOf(InsufficientData::class)
        // Filtré sur Cotonou, rien ne change : la ville se publie seule.
        ->and($this->service->aggregatedAmounts(...[...$period, 'Cotonou'])['invoiced'])->toBe(5_000_000);
});

test('an unfiltered aggregate is published when the unpublishable cities together hold the threshold', function () {
    // Contrôle : cinq villes d'une officine chacune. Chacune est retenue, mais
    // leur part commune, que la différence rendrait, repose sur 5 officines.
    declarePaidInCity($this->insurer, 'Cotonou', 5);

    foreach (['Porto-Novo', 'Parakou', 'Abomey-Calavi', 'Bohicon', 'Lokossa'] as $city) {
        declarePaidInCity($this->insurer, $city, 1);
    }

    $period = [new Period(2026, 8), new Period(2026, 8)];

    expect($this->service->networkSummary(...$period)['withheld'])->toBeFalse()
        ->and($this->service->aggregatedAmounts(...$period)['invoiced'])->toBe(10_000_000)
        ->and($this->service->perInsurer(...$period)[$this->insurer->id])->toBeInstanceOf(InsurerIndicators::class)
        ->and($this->service->aggregatedByInsurer(...$period)[$this->insurer->id])->toBeInstanceOf(InsurerAmounts::class);
});

test('an officine without a city always counts in the hidden share', function () {
    // Aucun filtre ne la publie jamais : non filtré moins toutes les villes
    // la rend toujours.
    declarePaidInCity($this->insurer, 'Cotonou', 5);
    declarePaidInCity($this->insurer, null, 1, invoiced: 4_210_000);

    $period = [new Period(2026, 8), new Period(2026, 8)];

    expect($this->service->networkSummary(...$period)['withheld'])->toBeTrue()
        ->and($this->service->aggregatedAmounts(...$period)['invoiced'])->toBeNull()
        ->and($this->service->perInsurer(...$period)[$this->insurer->id])->toBeInstanceOf(InsufficientData::class);
});

test('a delay curve point is withheld when its unpublishable cities hold too few officines', function () {
    // Juillet : Cotonou 5 et Parakou 5, tout est publiable. Août : Cotonou 5,
    // Parakou 1 — une des cinq de juillet, donc Parakou reste autorisée sur
    // la période. Le point d'août non filtré moins Cotonou rendrait Parakou.
    declarePaidInCity($this->insurer, 'Cotonou', 5, month: 7);
    $parakou = Pharmacy::factory()->count(5)->create(['city' => 'Parakou']);

    foreach ($parakou as $pharmacy) {
        Declaration::factory()->paid()->create(['pharmacy_id' => $pharmacy->id, 'insurer_id' => $this->insurer->id, 'period_year' => 2026, 'period_month' => 7, 'delay_days' => 30]);
    }

    Pharmacy::query()->where('city', 'Cotonou')->get()->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create(['pharmacy_id' => $pharmacy->id, 'insurer_id' => $this->insurer->id, 'period_year' => 2026, 'period_month' => 8, 'delay_days' => 30]),
    );
    Declaration::factory()->paid()->create(['pharmacy_id' => $parakou->first()->id, 'insurer_id' => $this->insurer->id, 'period_year' => 2026, 'period_month' => 8, 'delay_days' => 200]);

    $trend = $this->service->delayTrend(new Period(2026, 7), new Period(2026, 8));

    expect($trend['insurers'][$this->insurer->id]['points'])->toBe(['2026-07' => 30.0])
        ->and($trend['insurers'][$this->insurer->id]['withheld'])->toBe(['2026-08'])
        ->and($trend['network'])->toBe(['2026-07' => 30.0])
        ->and($trend['withheldMonths'])->toBe(['2026-08'])
        // Filtré sur Cotonou, août se publie.
        ->and($this->service->delayTrend(new Period(2026, 7), new Period(2026, 8), 'Cotonou')['network'])
        ->toBe(['2026-07' => 30.0, '2026-08' => 30.0]);
});
