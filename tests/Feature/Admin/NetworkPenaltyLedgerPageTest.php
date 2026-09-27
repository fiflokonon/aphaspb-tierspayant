<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

function networkLedgerDeclare(Insurer $insurer, int $count): void
{
    Pharmacy::factory()->count($count)->create()->each(fn (Pharmacy $pharmacy) => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
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
    ]));
}

test('the network admin reads the network penalty ledger', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkLedgerDeclare($insurer, 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/PenaltyLedger')
            ->missing('penaltyTrend')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('penaltyTrend.insurers', 1)
                ->where('penaltyTrend.total.months.7.accrued', 100_000)));
});

test('an officine user cannot open the network ledger', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.penalty-ledger'))
        ->assertForbidden();
});

test('an unknown insurer id falls back to every insurer', function () {
    $insurer = Insurer::factory()->withPenalty()->create();
    networkLedgerDeclare($insurer, 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger', ['insurer' => 999_999]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('insurer', null));
});
