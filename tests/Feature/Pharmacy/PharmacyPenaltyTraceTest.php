<?php

use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\User;
use App\Services\Pharmacy\PharmacyPenaltyTrace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

/**
 * Un titulaire et un assureur à 60 jours et 2 % par tranche de 30 jours.
 *
 * @return array{0: User, 1: Insurer}
 */
function traceOfficine(string $insurerName = 'NSIA'): array
{
    $user = User::factory()->create();
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => $insurerName]);

    return [$user, $insurer];
}

/**
 * Une facture de mars d'un million, déposée le 31/03 : déclenchement le 30/05,
 * puis une tranche de 20 000 les 30/05, 29/06, 29/07 et 28/08.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<array{amount: int, paid_on: string}>  $instalments
 */
function traceInvoice(User $user, Insurer $insurer, array $attributes = [], array $instalments = []): Declaration
{
    return Declaration::factory()->instalments($instalments)->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'invoice_deposited_on' => '2026-03-31',
        'paid_on' => null,
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function traceOf(User $user, array $query = []): array
{
    $trace = null;

    test()->actingAs($user)
        ->get(route('pharmacy.penalty-ledger', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$trace) {
            $page->missing('penaltyTrace')
                ->loadDeferredProps(function (AssertableInertia $reload) use (&$trace) {
                    $trace = $reload->toArray()['props']['penaltyTrace'];
                });
        });

    return $trace;
}

test('an unpaid invoice shows each tranche on the invoiced amount, up to today', function () {
    [$user, $insurer] = traceOfficine();
    traceInvoice($user, $insurer);

    $trace = traceOf($user);
    $invoice = $trace['insurers'][0]['invoices'][0];

    expect($trace['due'])->toBe(80_000)
        ->and($trace['insurers'][0])->toMatchArray(['name' => 'NSIA', 'triggerDays' => 60, 'ratePercent' => 2, 'due' => 80_000])
        ->and($invoice)->toMatchArray([
            'periodLabel' => 'Mars 26',
            'trancheAmount' => 20_000,
            'tranches' => 4,
            'accrued' => 80_000,
            'due' => 80_000,
            'running' => true,
        ])
        ->and($invoice['nextTrancheOn'])->not->toBeNull()
        ->and(array_column($invoice['events'], 'kind'))
        ->toBe(['deposit', 'trigger', 'tranche', 'tranche', 'tranche', 'tranche'])
        ->and(array_column($invoice['events'], 'cumulative'))
        ->toBe([null, null, 20_000, 40_000, 60_000, 80_000]);
});

test('an instalment appears in the trace without lightening the tranches', function () {
    [$user, $insurer] = traceOfficine();
    traceInvoice($user, $insurer, instalments: [['amount' => 400_000, 'paid_on' => '2026-06-15']]);

    $invoice = traceOf($user)['insurers'][0]['invoices'][0];
    $payment = collect($invoice['events'])->firstWhere('kind', 'payment');

    expect($payment)->toMatchArray(['amount' => 400_000, 'remaining' => 600_000])
        ->and(array_column($invoice['events'], 'kind'))
        ->toBe(['deposit', 'trigger', 'tranche', 'payment', 'tranche', 'tranche', 'tranche'])
        ->and(collect($invoice['events'])->where('kind', 'tranche')->pluck('amount')->unique()->all())
        ->toBe([20_000])
        ->and($invoice['due'])->toBe(80_000);
});

test('a paid-off invoice stops accruing on the day it was settled', function () {
    [$user, $insurer] = traceOfficine();
    traceInvoice($user, $insurer, instalments: [['amount' => 1_000_000, 'paid_on' => '2026-07-10']]);

    $invoice = traceOf($user)['insurers'][0]['invoices'][0];

    expect($invoice)->toMatchArray(['tranches' => 2, 'accrued' => 40_000, 'due' => 40_000, 'running' => false, 'nextTrancheOn' => null])
        ->and(array_column($invoice['events'], 'kind'))
        ->toBe(['deposit', 'trigger', 'tranche', 'tranche', 'payment', 'paidOff']);
});

test('an overpayment shows in the trace but the penalty stops on the day the invoice was settled', function () {
    [$user, $insurer] = traceOfficine();
    traceInvoice($user, $insurer, instalments: [
        ['amount' => 1_000_000, 'paid_on' => '2026-07-10'],
        ['amount' => 50_000, 'paid_on' => '2026-09-01'],
    ]);

    $invoice = traceOf($user)['insurers'][0]['invoices'][0];

    expect($invoice)->toMatchArray(['tranches' => 2, 'accrued' => 40_000, 'running' => false])
        ->and(array_column($invoice['events'], 'kind'))
        ->toBe(['deposit', 'trigger', 'tranche', 'tranche', 'payment', 'paidOff', 'payment']);
});

test('a closed penalty owes nothing more and comes after the due ones', function () {
    [$user, $insurer] = traceOfficine();
    $closed = traceInvoice($user, $insurer, instalments: [['amount' => 1_000_000, 'paid_on' => '2026-07-10']]);
    $closed->settlePenalty(PenaltySettlement::Paid, 40_000, $user);
    traceInvoice($user, $insurer, ['period_month' => 4, 'invoice_deposited_on' => '2026-04-30']);

    $insurerTrace = traceOf($user)['insurers'][0];
    [$open, $settled] = $insurerTrace['invoices'];

    expect($open['periodLabel'])->toBe('Avr. 26')
        ->and($settled['due'])->toBe(0)
        ->and($settled['settlement'])->toMatchArray(['label' => 'Payée', 'amount' => 40_000])
        ->and(collect($settled['events'])->last()['kind'])->toBe('settled')
        ->and($insurerTrace['accrued'])->toBe(40_000 + $open['accrued'])
        ->and($insurerTrace['due'])->toBe($open['accrued']);
});

test('the trace leaves out rejected invoices and other officines', function () {
    [$user, $insurer] = traceOfficine();
    traceInvoice($user, $insurer, ['status' => DeclarationStatus::Rejected, 'is_status_manual' => true]);
    traceInvoice(User::factory()->create(), $insurer);

    expect(traceOf($user))->toMatchArray(['due' => 0, 'insurers' => []]);
});

test('the insurer filter narrows the trace', function () {
    [$user, $nsia] = traceOfficine('NSIA');
    $sunu = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'SUNU']);
    traceInvoice($user, $nsia);
    traceInvoice($user, $sunu);

    $trace = traceOf($user, ['insurer' => $sunu->id]);

    expect(array_column($trace['insurers'], 'name'))->toBe(['SUNU']);
});

test('the trace costs three queries whatever the number of invoices', function () {
    [$user, $insurer] = traceOfficine();

    foreach (range(1, 6) as $month) {
        traceInvoice($user, $insurer, ['period_month' => $month, 'invoice_deposited_on' => sprintf('2026-%02d-28', $month)]);
    }

    DB::enableQueryLog();
    app(PharmacyPenaltyTrace::class)->for($user->currentPharmacy);

    expect(DB::getQueryLog())->toHaveCount(3);
});
