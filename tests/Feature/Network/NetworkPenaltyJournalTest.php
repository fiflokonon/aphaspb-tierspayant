<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkPenaltyJournal;
use App\Services\Network\NetworkPenaltyLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->journal = app(NetworkPenaltyJournal::class);
    $this->bounds = [new Period(2026, 3), new Period(2026, 9)];
});

/**
 * $count officines neuves, chacune une facture de 1 000 000 jamais réglée.
 *
 * @param  array<string, mixed>  $attributes
 */
function networkUnpaid(Insurer $insurer, int $count, int $month, string $depositedOn, array $attributes = [], array $pharmacy = []): void
{
    Pharmacy::factory()->count($count)->create($pharmacy)->each(fn (Pharmacy $pharmacy) => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => $month,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => $depositedOn,
        'paid_on' => null,
        'delay_days' => null,
        ...$attributes,
    ]));
}

test('an authorised insurer gets its monthly series', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);
    networkUnpaid($insurer, 5, 3, '2026-03-31');

    $series = $this->journal->for(...$this->bounds)->insurers[0];

    expect($series->name)->toBe('NSIA')
        ->and($series->month('2026-05')->accrued)->toBe(100_000)
        ->and($series->month('2026-03')->declared)->toBe(400_000);
});

test('a month resting on too few officines is withheld, with every later cumulative', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31');
    // Juin : une seule officine déclarante. Sa facture court en août (30/06 + 60).
    networkUnpaid($insurer, 1, 6, '2026-06-30');

    $series = $this->journal->for(...$this->bounds)->insurers[0];

    // Décor discriminant : sans rétention, juin vaudrait 100 000 couru et 0 déclaré,
    // et le cumul de juillet 300 000.
    expect($series->month('2026-06')->withheld)->toBeTrue()
        ->and($series->month('2026-06')->accrued)->toBeNull()
        ->and($series->month('2026-06')->declared)->toBeNull()
        ->and($series->month('2026-05')->accruedCumulative)->toBe(100_000)
        ->and($series->month('2026-07')->accrued)->toBe(100_000)
        ->and($series->month('2026-07')->accruedCumulative)->toBeNull()
        ->and($series->month('2026-08')->accrued)->toBe(120_000);
});

test('a withheld month blanks what was paid, waived and what remains due', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31');
    // Juin : une seule officine, soldée le 01/09 et close « payée » — une tranche
    // de 20 000 le 29/08. Sans rétention, juin publierait une due à zéro.
    Declaration::factory()
        ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-09-01']])
        ->penaltySettled(PenaltySettlement::Paid, 20_000)
        ->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 6,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-06-30',
        ]);

    $series = $this->journal->for(...$this->bounds)->insurers[0];
    $june = $series->month('2026-06');

    expect($june->withheld)->toBeTrue()
        ->and($june->accruedPaid)->toBeNull()
        ->and($june->accruedWaived)->toBeNull()
        ->and($june->accruedDue)->toBeNull()
        ->and($june->declaredDue)->toBeNull()
        ->and($series->month('2026-07')->accruedDue)->toBe(100_000)
        ->and($series->month('2026-07')->accruedPaid)->toBe(0)
        // Août : la tranche close de cette officine y tombe. Le mois est
        // publié (six officines), mais sa part payée ne repose que sur elle :
        // tout le découpage par statut est retenu avec elle.
        ->and($series->month('2026-08')->accrued)->toBe(120_000)
        ->and($series->month('2026-08')->accruedPaid)->toBeNull()
        ->and($series->month('2026-08')->accruedDue)->toBeNull();
});

/**
 * $count officines neuves, chacune une facture de mars soldée le 14/06 et
 * close : une tranche de 20 000 le 30/05, rien ensuite. Le montant clos égale
 * la courue, comme l'exige la réconciliation.
 */
function networkSettled(Insurer $insurer, int $count, PenaltySettlement $outcome): void
{
    foreach (range(1, $count) as $ignored) {
        Declaration::factory()
            ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-06-14']])
            ->penaltySettled($outcome, 20_000)
            ->create([
                'pharmacy_id' => Pharmacy::factory(),
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => 3,
                'amount_invoiced' => 1_000_000,
                'invoice_deposited_on' => '2026-03-31',
            ]);
    }
}

test('a month whose waived part rests on one officine withholds its whole status split', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    // Cinq officines en mai : quatre dues, une qui annule.
    networkUnpaid($insurer, 4, 3, '2026-03-31');
    networkSettled($insurer, 1, PenaltySettlement::Waived);

    $ledger = $this->journal->for(...$this->bounds);
    $may = $ledger->insurers[0]->month('2026-05');
    $totalMay = $ledger->total->month('2026-05');

    // Sans la règle : annulée 20 000, l'exacte pénalité d'une officine.
    expect($may->withheld)->toBeFalse()
        ->and($may->accrued)->toBe(100_000)
        ->and($may->accruedCumulative)->toBe(100_000)
        ->and($may->accruedWaived)->toBeNull()
        ->and($may->accruedPaid)->toBeNull()
        ->and($may->accruedDue)->toBeNull()
        ->and($totalMay->accrued)->toBe(100_000)
        ->and($totalMay->accruedWaived)->toBeNull()
        ->and($totalMay->accruedDue)->toBeNull()
        // Juin ne porte que la due de quatre officines, sans rien de clos :
        // une part sous le seuil reste une part sous le seuil.
        ->and($ledger->insurers[0]->month('2026-06')->accruedDue)->toBeNull();
});

test('a status split whose parts all rest on enough officines is published', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31');
    networkSettled($insurer, 5, PenaltySettlement::Paid);

    $ledger = $this->journal->for(...$this->bounds);
    $may = $ledger->insurers[0]->month('2026-05');

    expect($may->accrued)->toBe(200_000)
        ->and($may->accruedPaid)->toBe(100_000)
        ->and($may->accruedWaived)->toBe(0)
        ->and($may->accruedDue)->toBe(100_000)
        ->and($ledger->total->month('2026-05')->accruedPaid)->toBe(100_000);
});

test('the unfiltered total withholds its status split when a withheld split of a published insurer would be its hidden part', function () {
    $first = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    $second = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($first, 5, 3, '2026-03-31');
    networkSettled($first, 5, PenaltySettlement::Waived);
    // Le second : quatre dues, une annulée — son découpage est retenu.
    networkUnpaid($second, 4, 3, '2026-03-31');
    networkSettled($second, 1, PenaltySettlement::Waived);

    $ledger = $this->journal->for(...$this->bounds);
    $totalMay = $ledger->total->month('2026-05');

    // Le total repose sur six annulations : sa propre part passe le seuil.
    // Mais total − premier assureur rendrait l'annulation de la seule
    // officine du second.
    expect($totalMay->accrued)->toBe(300_000)
        ->and($totalMay->accruedWaived)->toBeNull()
        ->and($totalMay->accruedPaid)->toBeNull()
        ->and($totalMay->accruedDue)->toBeNull();
});

test('a masked insurer has no series but still counts in the unfiltered total', function () {
    $shown = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    $masked = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($shown, 5, 3, '2026-03-31');
    networkUnpaid($masked, 2, 3, '2026-03-31');

    $ledger = $this->journal->for(...$this->bounds);

    // Décision du 27/09/2026 : le total couvre les masqués. Ce test la verrouille.
    expect(array_map(fn ($series) => $series->insurerId, $ledger->insurers))->toBe([$shown->id])
        ->and($ledger->total->month('2026-05')->accrued)->toBe(140_000)
        ->and($ledger->maskedInsurers)->toBe(1);
});

test('the unfiltered total is published even when it rests on fewer officines than the threshold', function () {
    // Décor discriminant : deux officines seulement, sous le seuil de 5. Un
    // seuil appliqué au total retiendrait mai ; la décision du 27/09/2026 dit
    // que le total non filtré n'en a pas.
    $masked = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($masked, 2, 3, '2026-03-31');

    $may = $this->journal->for(...$this->bounds)->total->month('2026-05');

    expect($may->withheld)->toBeFalse()
        ->and($may->accrued)->toBe(40_000);
});

test('only insurers that fed the total count as masked', function () {
    $shown = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($shown, 5, 3, '2026-03-31');
    // Deux masqués sans convention : ils ne pèsent rien dans le total. Deux et
    // non un, pour que l'erreur inverse (l'oubli ci-dessous) ne la compense pas.
    networkUnpaid(Insurer::factory()->create(), 1, 3, '2026-03-31');
    networkUnpaid(Insurer::factory()->create(), 1, 3, '2026-03-31');
    // Sous convention, sans déclaration dans la période, mais une facture de
    // 2025 court encore : absent de perInsurer(), présent dans le total.
    $old = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $old->id,
        'period_year' => 2025,
        'period_month' => 12,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2025-12-31',
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $ledger = $this->journal->for(...$this->bounds);

    expect($ledger->maskedInsurers)->toBe(1)
        ->and($ledger->total->month('2026-05')->accrued)->toBe(120_000);
});

test('the unfiltered total is withheld when a withheld month of a published insurer would be its only hidden part', function () {
    $first = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    $second = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($first, 5, 3, '2026-03-31');
    networkUnpaid($second, 5, 3, '2026-03-31');
    // Juin : une seule officine chez le premier assureur, facture de 3 000 000.
    networkUnpaid($first, 1, 6, '2026-06-30', ['amount_invoiced' => 3_000_000]);

    $ledger = $this->journal->for(...$this->bounds);
    $june = $ledger->total->month('2026-06');

    // Sans rétention : total − second = la pénalité de cette seule officine.
    expect($june->withheld)->toBeTrue()
        ->and($june->declared)->toBeNull()
        ->and($ledger->total->month('2026-07')->accruedCumulative)->toBeNull()
        // Mai ne cache rien : il reste publié.
        ->and($ledger->total->month('2026-05')->accrued)->toBe(200_000);
});

test('filtered on a city, the total needs the threshold too', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31', pharmacy: ['city' => 'Cotonou']);
    networkUnpaid($insurer, 1, 3, '2026-03-31', ['amount_invoiced' => 7_000_000], pharmacy: ['city' => 'Kandi']);

    $kandi = $this->journal->for(...$this->bounds, city: 'Kandi')->total;
    $cotonou = $this->journal->for(...$this->bounds, city: 'Cotonou')->total;

    // Une seule officine à Kandi : son total serait sa pénalité, ville nommée.
    expect($kandi->month('2026-05')->withheld)->toBeTrue()
        ->and($kandi->month('2026-05')->accrued)->toBeNull()
        // Un mois sans aucune contribution ne cache personne : zéro publié.
        ->and($kandi->month('2026-04')->withheld)->toBeFalse()
        ->and($kandi->month('2026-04')->accrued)->toBe(0)
        ->and($cotonou->month('2026-05')->accrued)->toBe(100_000);
});

test('filtered on a masked insurer, the total is withheld too', function () {
    $masked = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($masked, 2, 3, '2026-03-31');

    $ledger = $this->journal->for(...$this->bounds, insurerId: $masked->id);

    expect($ledger->insurers)->toBe([])
        ->and(collect($ledger->total->months)->reject(fn ($month) => $month->future)->every(fn ($month) => $month->withheld))->toBeTrue();
});

test('filtered on an authorised insurer, the total follows its month-by-month withholding', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31');
    networkUnpaid($insurer, 1, 6, '2026-06-30');

    $total = $this->journal->for(...$this->bounds, insurerId: $insurer->id)->total;

    expect($total->month('2026-05')->accrued)->toBe(100_000)
        ->and($total->month('2026-06')->withheld)->toBeTrue();
});

test('an insurer without a clause appears nowhere', function () {
    $none = Insurer::factory()->create();
    networkUnpaid($none, 5, 3, '2026-03-31');

    $ledger = $this->journal->for(...$this->bounds);

    expect($ledger->insurers)->toBe([])
        ->and($ledger->total->month('2026-05')->accrued)->toBe(0);
});

test('the city filter narrows the ledger like every other network read', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31', pharmacy: ['city' => 'Cotonou']);
    networkUnpaid($insurer, 5, 3, '2026-03-31', pharmacy: ['city' => 'Parakou']);

    $total = $this->journal->for(...$this->bounds, city: 'Cotonou')->total;

    expect($total->month('2026-05')->accrued)->toBe(100_000);
});

test('the raw tally reads the same number of queries however many declarations there are', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 1, 3, '2026-03-31');

    DB::enableQueryLog();
    app(NetworkPenaltyLedger::class)->tally(...$this->bounds);
    $withOne = count(DB::getQueryLog());

    networkUnpaid($insurer, 12, 4, '2026-04-30', ['amount_received' => 0]);
    DB::flushQueryLog();
    app(NetworkPenaltyLedger::class)->tally(...$this->bounds);

    expect(count(DB::getQueryLog()))->toBe($withOne)
        ->and($withOne)->toBe(3);
});
