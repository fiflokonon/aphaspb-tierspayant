<?php

use App\Data\Period;
use App\Enums\PenaltySettlement;
use App\Services\Declarations\PenaltyCalculator;
use App\Services\Declarations\PenaltyTally;
use App\Support\DayNumber;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

/**
 * Une facture de 1 000 000 déposée le $depositedOn, jamais réglée, sous une
 * clause 60 jours / 2 % : une tranche de 20 000 tous les trente jours.
 */
function tallyUnpaid(PenaltyTally $tally, int $insurerId, int $pharmacyId, int $month, string $depositedOn): void
{
    $tally->add(
        insurerId: $insurerId,
        pharmacyId: $pharmacyId,
        periodYear: 2026,
        periodMonth: $month,
        amountInvoiced: 1_000_000,
        amountReceived: 0,
        depositedDay: DayNumber::fromDate($depositedOn),
        paidDay: null,
        triggerDays: 60,
        rateBp: 200,
        payments: [],
    );
}

test('accrued goes to the month each tranche falls in, declared to the invoice month', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 3), new Period(2026, 9));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');

    $series = $tally->ledger([1 => 'NSIA'])->insurers[0];

    expect($series->month('2026-03')->declared)->toBe(80_000)
        ->and($series->month('2026-03')->accrued)->toBe(0)
        ->and($series->month('2026-05')->accrued)->toBe(20_000)
        ->and($series->month('2026-08')->accrued)->toBe(20_000)
        ->and($series->month('2026-08')->accruedCumulative)->toBe(80_000)
        ->and($series->month('2026-06')->declared)->toBe(0)
        ->and($series->month('2026-09')->current)->toBeTrue();
});

test('a tranche outside the period is ignored, but the invoice still counts for its month', function () {
    // Période mai–juin seulement : les tranches de juillet et août sortent.
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 6));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');

    $series = $tally->ledger([1 => 'NSIA'])->insurers[0];

    expect(array_map(fn ($month) => $month->month, $series->months))->toBe(['2026-05', '2026-06'])
        ->and($series->month('2026-06')->accruedCumulative)->toBe(40_000)
        // Mars n'est pas dans la période : la vue « mois déclaré » ne le voit pas.
        ->and(array_sum(array_map(fn ($month) => $month->declared, $series->months)))->toBe(0);
});

test('a tranche on the last day of a month stays in that month', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 6));
    // Déposée le 01/04 : première tranche le 31/05.
    tallyUnpaid($tally, 1, 10, 3, '2026-04-01');

    $series = $tally->ledger([1 => 'NSIA'])->insurers[0];

    expect($series->month('2026-05')->accrued)->toBe(20_000)
        ->and($series->month('2026-06')->accrued)->toBe(20_000);
});

test('a period across the new year lists its months in order', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2025, 11), new Period(2026, 2));

    expect(array_map(fn ($month) => $month->month, $tally->ledger([])->total->months))
        ->toBe(['2025-11', '2025-12', '2026-01', '2026-02']);
});

test('future months are null, not zero', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 1), new Period(2026, 12));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');

    $october = $tally->ledger([1 => 'NSIA'])->insurers[0]->month('2026-10');

    expect($october->future)->toBeTrue()
        ->and($october->accrued)->toBeNull()
        ->and($october->accruedCumulative)->toBeNull()
        ->and($october->declared)->toBeNull();
});

test('an insurer named without any declaration reads zeros, not an absence', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 6));

    $series = $tally->ledger([7 => 'Sans rien couru'])->insurers[0];

    expect($series->name)->toBe('Sans rien couru')
        ->and($series->month('2026-05')->accrued)->toBe(0);
});

test('the total sums every insurer added, named or not', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 5));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');
    tallyUnpaid($tally, 2, 11, 3, '2026-03-31');

    $ledger = $tally->ledger([1 => 'NSIA']);

    expect($ledger->insurers)->toHaveCount(1)
        ->and($ledger->total->month('2026-05')->accrued)->toBe(40_000);
});

test('the withholding callback sees how many officines each month rests on', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 3), new Period(2026, 5));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');
    tallyUnpaid($tally, 1, 11, 3, '2026-03-31');

    $seen = [];
    $tally->ledger([1 => 'NSIA'], function (?int $insurerId, int $accrued, int $declared) use (&$seen): bool {
        $seen[] = [$insurerId, $accrued, $declared];

        return false;
    });

    // mars (déclaré par deux officines), avril (rien), mai (deux tranches) — pour l'assureur puis le total.
    expect($seen)->toBe([
        [1, 0, 2], [1, 0, 0], [1, 2, 0],
        [null, 0, 2], [null, 0, 0], [null, 2, 0],
    ]);
});

test('a withheld month blanks every later cumulative', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 7));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');

    $series = $tally->ledger([1 => 'NSIA'], function (?int $insurerId, int $accrued, int $declared) use (&$calls): bool {
        $calls = ($calls ?? 0) + 1;

        return $calls === 2; // juin, deuxième mois de la série de l'assureur
    })->insurers[0];

    expect($series->month('2026-05')->accruedCumulative)->toBe(20_000)
        ->and($series->month('2026-06')->withheld)->toBeTrue()
        ->and($series->month('2026-06')->accrued)->toBeNull()
        ->and($series->month('2026-07')->accrued)->toBe(20_000)
        ->and($series->month('2026-07')->accruedCumulative)->toBeNull();
});

/**
 * La même facture, soldée le 01/09 : ses quatre tranches (mai à août) lui
 * restent acquises, puis la pénalité est close avec l'issue donnée.
 */
function tallySettled(PenaltyTally $tally, ?PenaltySettlement $settlement): void
{
    $tally->add(
        insurerId: 1,
        pharmacyId: 10,
        periodYear: 2026,
        periodMonth: 3,
        amountInvoiced: 1_000_000,
        amountReceived: 1_000_000,
        depositedDay: DayNumber::fromDate('2026-03-31'),
        paidDay: DayNumber::fromDate('2026-09-01'),
        triggerDays: 60,
        rateBp: 200,
        payments: [[1_000_000, DayNumber::fromDate('2026-09-01')]],
        settlement: $settlement,
    );
}

test('a paid penalty goes to the month of each tranche, and to its declared month in full', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 3), new Period(2026, 9));
    tallySettled($tally, PenaltySettlement::Paid);

    $ledger = $tally->ledger([1 => 'NSIA']);

    foreach ([$ledger->insurers[0], $ledger->total] as $series) {
        foreach (['2026-05', '2026-06', '2026-07', '2026-08'] as $key) {
            expect($series->month($key)->accrued)->toBe(20_000)
                ->and($series->month($key)->accruedPaid)->toBe(20_000)
                ->and($series->month($key)->accruedWaived)->toBe(0)
                ->and($series->month($key)->accruedDue)->toBe(0);
        }

        expect($series->month('2026-03')->declared)->toBe(80_000)
            // Le cumul reste celui du couru : la clôture ne le réécrit pas.
            ->and($series->month('2026-08')->accruedCumulative)->toBe(80_000);
    }
});

test('a waived penalty goes to the waived bucket, not the paid one', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 3), new Period(2026, 9));
    tallySettled($tally, PenaltySettlement::Waived);

    $may = $tally->ledger([1 => 'NSIA'])->insurers[0]->month('2026-05');

    expect($may->accruedWaived)->toBe(20_000)
        ->and($may->accruedPaid)->toBe(0)
        ->and($may->accruedDue)->toBe(0);
});

test('an unsettled penalty stays due in full', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 3), new Period(2026, 9));
    tallySettled($tally, null);
    tallyUnpaid($tally, 1, 11, 3, '2026-03-31');

    $series = $tally->ledger([1 => 'NSIA'])->insurers[0];

    expect($series->month('2026-05')->accruedDue)->toBe($series->month('2026-05')->accrued)
        ->and($series->month('2026-05')->accruedDue)->toBe(40_000);
});

test('a future or withheld month has no settled or due figure either', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 10));
    tallySettled($tally, PenaltySettlement::Paid);

    $series = $tally->ledger([1 => 'NSIA'], fn (?int $insurerId, int $accrued): bool => $insurerId !== null && $accrued === 1)->insurers[0];

    foreach (['2026-05', '2026-10'] as $key) {
        expect($series->month($key)->accruedPaid)->toBeNull()
            ->and($series->month($key)->accruedWaived)->toBeNull()
            ->and($series->month($key)->accruedDue)->toBeNull();
    }

    expect($series->month('2026-05')->withheld)->toBeTrue()
        ->and($series->month('2026-10')->future)->toBeTrue();
});
