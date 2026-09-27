<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Pharmacy\PharmacyPenaltyLedger;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->ledger = app(PharmacyPenaltyLedger::class);
    $this->pharmacy = Pharmacy::factory()->create();
});

/**
 * Une facture de 1 000 000 de l'officine, jamais réglée.
 *
 * @param  array<string, mixed>  $attributes
 */
function ledgerUnpaid(Pharmacy $pharmacy, Insurer $insurer, int $month, string $depositedOn, array $attributes = []): Declaration
{
    return Declaration::factory()->create([
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
    ]);
}

test('a March invoice still unpaid accrues in May to August and is charged to March', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);
    ledgerUnpaid($this->pharmacy, $insurer, 3, '2026-03-31');

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9));
    $series = $ledger->insurers[0];

    expect($series->name)->toBe('NSIA')
        ->and($series->month('2026-03')->declared)->toBe(80_000)
        ->and($series->month('2026-05')->accrued)->toBe(20_000)
        ->and($series->month('2026-08')->accruedCumulative)->toBe(80_000)
        ->and($ledger->total->month('2026-08')->accruedCumulative)->toBe(80_000);
});

test('an invoice declared before the period still accrues inside it', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    ledgerUnpaid($this->pharmacy, $insurer, 3, '2026-03-31');

    $series = $this->ledger->for($this->pharmacy, new Period(2026, 6), new Period(2026, 9))->insurers[0];

    expect($series->month('2026-06')->accrued)->toBe(20_000)
        ->and($series->month('2026-08')->accruedCumulative)->toBe(60_000);
});

test('a month settled during the period still counts the tranches before its payment', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    // Déposée le 01/04 : tranches le 31/05 et le 30/06, soldée le 01/07.
    // Déclarée en mars, hors période : seule la branche « pas soldée avant le
    // début de la période » (paid_on >= 01/06) la fait entrer.
    Declaration::factory()
        ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-07-01']])
        ->create([
            'pharmacy_id' => $this->pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 3,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-04-01',
        ]);

    $series = $this->ledger->for($this->pharmacy, new Period(2026, 6), new Period(2026, 7))->insurers[0];

    expect($series->month('2026-06')->accrued)->toBe(20_000)
        ->and($series->month('2026-07')->accrued)->toBe(0);
});

test('an insurer under a clause with nothing accrued reads zero, one without a clause is absent', function () {
    $clause = Insurer::factory()->withPenalty(triggerDays: 90, ratePercent: 2.0)->create(['name' => 'Sous convention']);
    $none = Insurer::factory()->create(['name' => 'Sans convention']);
    ledgerUnpaid($this->pharmacy, $clause, 9, '2026-09-10');
    ledgerUnpaid($this->pharmacy, $none, 3, '2026-03-31');

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9));

    expect(array_map(fn ($series) => $series->name, $ledger->insurers))->toBe(['Sous convention'])
        ->and($ledger->insurers[0]->month('2026-09')->declared)->toBe(0)
        ->and($ledger->total->month('2026-05')->accrued)->toBe(0);
});

test('a rejected invoice does not accrue', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    ledgerUnpaid($this->pharmacy, $insurer, 3, '2026-03-31', ['status' => DeclarationStatus::Rejected]);

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9));

    expect($ledger->total->month('2026-05')->accrued)->toBe(0)
        ->and($ledger->total->month('2026-03')->declared)->toBe(0);
});

test('another officine never enters the ledger', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    ledgerUnpaid($this->pharmacy, $insurer, 3, '2026-03-31');
    ledgerUnpaid(Pharmacy::factory()->create(), $insurer, 3, '2026-03-31');

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9));

    expect($ledger->total->month('2026-05')->accrued)->toBe(20_000);
});

test('the insurer filter narrows series and total alike', function () {
    $kept = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    $other = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    ledgerUnpaid($this->pharmacy, $kept, 3, '2026-03-31');
    ledgerUnpaid($this->pharmacy, $other, 3, '2026-03-31');

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9), $kept->id);

    expect($ledger->insurers)->toHaveCount(1)
        ->and($ledger->insurers[0]->insurerId)->toBe($kept->id)
        ->and($ledger->total->month('2026-05')->accrued)->toBe(20_000);
});

test('a penalty closed as paid is read from the declaration and leaves nothing due', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    // Déposée le 31/03, soldée le 01/09 : quatre tranches de mai à août, 80 000 clos « payée ».
    Declaration::factory()
        ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-09-01']])
        ->penaltySettled(PenaltySettlement::Paid, 80_000)
        ->create([
            'pharmacy_id' => $this->pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 3,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-03-31',
        ]);
    ledgerUnpaid($this->pharmacy, $insurer, 4, '2026-04-30');

    $series = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9))->insurers[0];

    // Juillet : 20 000 de la facture close, 20 000 de celle d'avril encore due.
    expect($series->month('2026-07')->accrued)->toBe(40_000)
        ->and($series->month('2026-07')->accruedPaid)->toBe(20_000)
        ->and($series->month('2026-07')->accruedDue)->toBe(20_000)
        ->and($series->month('2026-03')->declared)->toBe(80_000);
});
