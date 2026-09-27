<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

/**
 * Un titulaire et une facture de mars jamais réglée chez un assureur sous convention.
 *
 * @return array{0: User, 1: Insurer}
 */
function ledgerOfficine(string $insurerName = 'NSIA'): array
{
    $user = User::factory()->create();
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => $insurerName]);

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-03-31',
        'paid_on' => null,
        'delay_days' => null,
    ]);

    return [$user, $insurer];
}

test('the officine reads its own penalty ledger', function () {
    [$user, $insurer] = ledgerOfficine();

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/PenaltyLedger')
            ->where('insurers.0.id', $insurer->id)
            ->missing('penaltyTrend')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('penaltyTrend.total.months.7.month', '2026-05')
                ->where('penaltyTrend.total.months.7.accrued', 20_000)));
});

test('another officine\'s invoices never reach the ledger', function () {
    ledgerOfficine();
    [$other] = ledgerOfficine('SUNU Assurances');

    $this->actingAs($other)
        ->get(route('pharmacy.penalty-ledger'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('penaltyTrend.total.months.7.accrued', 20_000)));
});

test('a forged insurer id is ignored', function () {
    [$user] = ledgerOfficine();
    $foreign = Insurer::factory()->withPenalty()->create();

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger', ['insurer' => $foreign->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('insurer', null)
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('penaltyTrend.insurers', 1)));
});

test('the ledger is not open to a network admin without an officine', function () {
    $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('pharmacy.penalty-ledger'))
        ->assertForbidden();
});
