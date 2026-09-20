<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\DeclarationPayment;
use App\Models\Insurer;
use App\Services\Declarations\PenaltyCalculator;
use App\Support\DayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->calculator = new PenaltyCalculator;
});

/**
 * Un assureur en mémoire.
 *
 * Construit à la main plutôt que par la fabrique : ces tests vivent dans
 * tests/Unit, qui n'a pas RefreshDatabase, et une fabrique finirait par
 * écrire dans la base de développement.
 */
function insurerWith(?int $triggerDays, ?int $rateBp): Insurer
{
    return (new Insurer)->forceFill([
        'id' => 1,
        'name' => 'Assureur de test',
        'standard_delay_days' => 30,
        'penalty_trigger_days' => $triggerDays,
        'penalty_rate_bp' => $rateBp,
    ]);
}

/**
 * Une déclaration en mémoire, avec son assureur et ses versements attachés.
 *
 * @param  list<array{amount: int, paid_on: string}>  $payments
 */
function declarationFor(
    Insurer $insurer,
    int $invoiced,
    string $depositedOn,
    array $payments = [],
    DeclarationStatus $status = DeclarationStatus::Unpaid,
): Declaration {
    $received = array_sum(array_column($payments, 'amount'));

    $declaration = (new Declaration)->forceFill([
        'id' => 1,
        'amount_invoiced' => $invoiced,
        'amount_received' => $received,
        'status' => $status,
        'is_status_manual' => true,
        'invoice_deposited_on' => $depositedOn,
        'paid_on' => $payments === [] ? null : end($payments)['paid_on'],
        'delay_days' => null,
    ]);

    $declaration->setRelation('insurer', $insurer);
    $declaration->setRelation('payments', new Collection(array_map(
        fn (array $payment): DeclarationPayment => (new DeclarationPayment)->forceFill([
            'amount' => $payment['amount'],
            'paid_on' => $payment['paid_on'],
        ]),
        $payments,
    )));

    return $declaration;
}

test('an insurer without a clause accrues nothing', function () {
    $declaration = declarationFor(insurerWith(null, null), 1_000_000, '2026-01-01');

    expect($this->calculator->for($declaration))->toBeNull();
});

test('half a clause is no clause', function () {
    expect($this->calculator->for(declarationFor(insurerWith(60, null), 1_000_000, '2026-01-01')))->toBeNull()
        ->and($this->calculator->for(declarationFor(insurerWith(null, 200), 1_000_000, '2026-01-01')))->toBeNull();
});

test('a declaration with no deposit date accrues nothing', function () {
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-01-01');
    $declaration->invoice_deposited_on = null;

    expect($this->calculator->for($declaration))->toBeNull();
});

test('a rejected month accrues nothing', function () {
    $declaration = declarationFor(
        insurerWith(60, 200),
        1_000_000,
        '2026-01-01',
        status: DeclarationStatus::Rejected,
    );

    expect($this->calculator->for($declaration))->toBeNull();
});

test('the day before the trigger accrues nothing', function () {
    // Déposée il y a 59 jours : la pénalité mord au 60e.
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-07-22');

    expect($this->calculator->for($declaration))->toBe(0);
});

test('the trigger day itself accrues exactly one tranche', function () {
    // Déposée il y a exactement 60 jours.
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-07-21');

    expect($this->calculator->for($declaration))->toBe(20_000);
});

test('an unpaid month accrues one tranche every thirty days', function () {
    // Déposée il y a 120 jours : tranches au 60e, 90e et 120e jour.
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-05-22');

    expect($this->calculator->for($declaration))->toBe(60_000);
});

test('each instalment shrinks the base of the tranches that follow', function () {
    // Déposée le 2026-05-22 : tranches les 2026-07-21, 2026-08-20, 2026-09-19.
    // 400 000 arrivent le 2026-08-01, donc entre la première et la deuxième.
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-05-22', [
        ['amount' => 400_000, 'paid_on' => '2026-08-01'],
    ]);

    // 20 000 sur 1 000 000, puis deux fois 12 000 sur 600 000.
    expect($this->calculator->for($declaration))->toBe(44_000);
});

test('money arriving on the tranche day itself lightens that tranche', function () {
    // Versement le 2026-07-21, jour de la première tranche.
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-05-22', [
        ['amount' => 400_000, 'paid_on' => '2026-07-21'],
    ]);

    // Les trois tranches mordent déjà sur 600 000.
    expect($this->calculator->for($declaration))->toBe(36_000);
});

test('a month settled late keeps the penalty it had accrued', function () {
    // Déposée le 2026-05-22, soldée le 2026-08-25 : tranches les 2026-07-21
    // et 2026-08-20, la seconde sur la base encore entière.
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-05-22', [
        ['amount' => 1_000_000, 'paid_on' => '2026-08-25'],
    ]);

    expect($this->calculator->for($declaration))->toBe(40_000);
});

test('a settled month stops accruing as time passes', function () {
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-05-22', [
        ['amount' => 1_000_000, 'paid_on' => '2026-08-25'],
    ]);

    $before = $this->calculator->for($declaration);

    $this->travelTo(CarbonImmutable::create(2027, 3, 1));

    expect($this->calculator->for($declaration))->toBe($before);
});

test('an unpaid month keeps accruing as time passes', function () {
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-05-22');

    $before = $this->calculator->for($declaration);

    $this->travelTo(CarbonImmutable::create(2026, 10, 19));

    expect($this->calculator->for($declaration))->toBe($before + 20_000);
});

test('a month settled between two tranches is charged only for the first', function () {
    // Soldée le 2026-07-25, donc après la première tranche et avant la seconde.
    $declaration = declarationFor(insurerWith(60, 200), 1_000_000, '2026-05-22', [
        ['amount' => 1_000_000, 'paid_on' => '2026-07-25'],
    ]);

    expect($this->calculator->for($declaration))->toBe(20_000);
});

test('a base emptied while tranches remain charges nothing more', function () {
    // Déposée le 2026-05-01, déclenchement à 30 : tranches les 05-31, 06-30,
    // 07-30 et 08-29. La dette est éteinte dès le 06-15, mais un reliquat
    // encaissé le 09-01 tient l'horloge ouverte jusque-là — ce qui fait passer
    // la boucle par la branche « base à zéro » avec trois tranches devant elle.
    $declaration = declarationFor(insurerWith(30, 200), 1_000_000, '2026-05-01', [
        ['amount' => 1_000_000, 'paid_on' => '2026-06-15'],
        ['amount' => 50_000, 'paid_on' => '2026-09-01'],
    ]);

    // Seule la tranche du 05-31 mord, sur la base encore entière.
    expect($this->calculator->for($declaration))->toBe(20_000);
});

test('the total is null when not one declaration carries a clause', function () {
    $insurer = insurerWith(null, null);

    expect($this->calculator->total([
        declarationFor($insurer, 1_000_000, '2026-01-01'),
        declarationFor($insurer, 2_000_000, '2026-02-01'),
    ]))->toBeNull();
});

test('the total sums what carries a clause and ignores what does not', function () {
    expect($this->calculator->total([
        declarationFor(insurerWith(60, 200), 1_000_000, '2026-07-21'),
        declarationFor(insurerWith(null, null), 9_000_000, '2026-01-01'),
    ]))->toBe(20_000);
});

test('a clause with only a rejected month totals zero, not null', function () {
    $declaration = declarationFor(
        insurerWith(60, 200),
        1_000_000,
        '2026-01-01',
        status: DeclarationStatus::Rejected,
    );

    // for() rend null — rien n'a couru — mais la convention existe, et null
    // se rend par un tiret qui se lirait « pas de clause ».
    expect($this->calculator->for($declaration))->toBeNull()
        ->and($this->calculator->total([$declaration]))->toBe(0);
});

test('both entry points agree on every case the Carbon one covers', function () {
    $cases = [
        // [facturé, encaissé, dépôt, versements [montant, date]]
        [1_000_000, 0, '2026-05-22', []],
        [1_000_000, 400_000, '2026-05-22', [[400_000, '2026-08-01']]],
        [1_000_000, 1_000_000, '2026-05-22', [[1_000_000, '2026-08-25']]],
        [1_000_000, 1_050_000, '2026-05-01', [[1_000_000, '2026-06-15'], [50_000, '2026-09-01']]],
        [500_000, 500_000, '2026-09-19', [[500_000, '2026-09-19']]],
    ];

    foreach ($cases as [$invoiced, $received, $deposited, $payments]) {
        $paidOn = $payments === [] ? null : CarbonImmutable::parse(end($payments)[1]);

        $viaCarbon = $this->calculator->accrued(
            amountInvoiced: $invoiced,
            amountReceived: $received,
            depositedOn: CarbonImmutable::parse($deposited),
            paidOn: $paidOn,
            triggerDays: 60,
            rateBp: 200,
            payments: array_map(
                fn (array $payment): array => [
                    'amount' => $payment[0],
                    'paid_on' => CarbonImmutable::parse($payment[1]),
                ],
                $payments,
            ),
        );

        $viaDays = $this->calculator->accruedInDays(
            amountInvoiced: $invoiced,
            amountReceived: $received,
            depositedDay: DayNumber::fromDate($deposited),
            paidDay: $paidOn === null ? null : DayNumber::fromCarbon($paidOn),
            triggerDays: 60,
            rateBp: 200,
            payments: array_map(
                fn (array $payment): array => [$payment[0], DayNumber::fromDate($payment[1])],
                $payments,
            ),
        );

        expect($viaDays)->toBe($viaCarbon);
    }
});

test('a hoisted today gives the same answer as a resolved one', function () {
    $today = DayNumber::fromDate('2026-09-19');

    $resolved = $this->calculator->accruedInDays(
        amountInvoiced: 1_000_000,
        amountReceived: 0,
        depositedDay: DayNumber::fromDate('2026-05-22'),
        paidDay: null,
        triggerDays: 60,
        rateBp: 200,
        payments: [],
    );

    $hoisted = $this->calculator->accruedInDays(
        amountInvoiced: 1_000_000,
        amountReceived: 0,
        depositedDay: DayNumber::fromDate('2026-05-22'),
        paidDay: null,
        triggerDays: 60,
        rateBp: 200,
        payments: [],
        today: $today,
    );

    expect($hoisted)->toBe($resolved)->and($hoisted)->toBe(60_000);
});
