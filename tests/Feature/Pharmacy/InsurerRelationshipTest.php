<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Pharmacy\InsurerRelationshipReport;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->report = app(InsurerRelationshipReport::class);
    $this->pharmacy = Pharmacy::factory()->create();
    $this->bounds = [new Period(2025, 10), new Period(2026, 9)];
});

/** Un mois soldé dans les temps. */
function settledMonth(Pharmacy $pharmacy, Insurer $insurer, int $month, int $invoiced, int $delayDays): Declaration
{
    return Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => $month,
        'amount_invoiced' => $invoiced,
        'amount_received' => $invoiced,
        'delay_days' => $delayDays,
    ]);
}

/** Un mois encore dû, déposé il y a $ageDays jours. */
function openMonth(Pharmacy $pharmacy, Insurer $insurer, int $month, int $invoiced, int $ageDays): Declaration
{
    return Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => $month,
        'amount_invoiced' => $invoiced,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 9, 19)->subDays($ageDays),
        'paid_on' => null,
        'delay_days' => null,
    ]);
}

test('nothing declared yields empty figures', function () {
    $insurer = Insurer::factory()->create();

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect($built['summary']->declarations)->toBe(0)
        ->and($built['summary']->invoiced)->toBe(0)
        ->and($built['summary']->recoveryRate)->toBeNull()
        ->and($built['summary']->longestDelayDays)->toBeNull()
        ->and($built['summary']->penalty)->toBeNull()
        ->and($built['months'])->toBe([]);
});

test('the longest delay can come from an open invoice rather than a settled month', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    settledMonth($this->pharmacy, $insurer, 7, 500_000, 40);
    openMonth($this->pharmacy, $insurer, 1, 300_000, 200);

    expect($this->report->build($this->pharmacy, $insurer, ...$this->bounds)['summary']->longestDelayDays)
        ->toBe(200);
});

test('the longest delay can come from a settled month rather than an open one', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    settledMonth($this->pharmacy, $insurer, 7, 500_000, 300);
    openMonth($this->pharmacy, $insurer, 1, 300_000, 20);

    expect($this->report->build($this->pharmacy, $insurer, ...$this->bounds)['summary']->longestDelayDays)
        ->toBe(300);
});

test('a month left half paid weighs its whole age, not its instalment delay', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    // Déposée le 2026-01-01, 100 000 encaissés le 2026-01-10, le solde jamais
    // versé. Le hook `saving` en tire delay_days = 9, mais la dette a 261 jours
    // au 2026-09-19 — et c'est elle que l'écran doit montrer.
    Declaration::factory()->instalments([
        ['amount' => 100_000, 'paid_on' => '2026-01-10'],
    ])->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 1,
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-01-01',
    ]);

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect($built['months'][0]['delayDays'])->toBe(9)
        ->and($built['summary']->longestDelayDays)->toBe(261);
});

test('a clause with nothing yet to claim reads zero, never a dash', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 90, ratePercent: 2.5)
        ->create(['standard_delay_days' => 30]);

    // Rejetée : PenaltyCalculator::for() rend null, mais la convention existe
    // et l'écran l'affiche deux centimètres plus haut. « — » s'y lirait
    // « pas de clause », ce qui est faux.
    Declaration::factory()->rejected()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 500_000,
        'invoice_deposited_on' => '2026-08-01',
    ]);

    expect($this->report->build($this->pharmacy, $insurer, ...$this->bounds)['summary']->penalty)
        ->toBe(0);
});

test('the claimable penalty includes a month settled late', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    // Déposée le 2026-03-01, soldée le 2026-06-10 : tranches les 2026-04-30 et
    // 2026-05-30, toutes deux sur la base entière.
    Declaration::factory()->instalments([
        ['amount' => 1_000_000, 'paid_on' => '2026-06-10'],
    ])->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 2,
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-03-01',
    ]);

    expect($this->report->build($this->pharmacy, $insurer, ...$this->bounds)['summary']->penalty)
        ->toBe(40_000);
});

test('an insurer with no clause has no penalty at all', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    openMonth($this->pharmacy, $insurer, 7, 500_000, 250);

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect($built['summary']->penalty)->toBeNull()
        ->and($built['months'][0]['penalty'])->toBeNull();
});

test('a narrower period drops what falls outside it', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    settledMonth($this->pharmacy, $insurer, 1, 700_000, 12);

    $built = $this->report->build($this->pharmacy, $insurer, new Period(2026, 6), new Period(2026, 9));

    expect($built['summary']->declarations)->toBe(0)
        ->and($built['summary']->invoiced)->toBe(0);
});

test('the report never carries the private note', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    Declaration::factory()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 500_000,
        'amount_received' => 500_000,
        'delay_days' => 12,
        'private_note' => 'Relancer la comptabilite',
    ]);

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect(json_encode($built['months'], JSON_THROW_ON_ERROR))
        ->not->toContain('Relancer la comptabilite');
});

test('the weighted delay leans on the amounts actually received', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    settledMonth($this->pharmacy, $insurer, 7, 900_000, 10);
    settledMonth($this->pharmacy, $insurer, 8, 100_000, 50);

    // (10 × 900 000 + 50 × 100 000) / 1 000 000 = 14
    expect($this->report->build($this->pharmacy, $insurer, ...$this->bounds)['summary']->weightedDelayDays)
        ->toBe(14.0);
});

test('the screen refuses an insurer this officine never touched', function () {
    $user = User::factory()->create();
    $stranger = Insurer::factory()->create();

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $stranger))
        ->assertNotFound();
});

test('the screen opens on a ticked insurer with nothing declared', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();
    $user->currentPharmacy->insurers()->attach($insurer);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Insurer')
            ->where('relationship.declarations', 0)
            ->has('months', 0),
        );
});

test('the screen opens on an insurer declared to but no longer ticked', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();

    settledMonth($user->currentPharmacy, $insurer, 8, 400_000, 15);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationship.declarations', 1)
            ->has('months', 1),
        );
});

test('the screen restates the convention', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 90, ratePercent: 2.5)
        ->create(['standard_delay_days' => 45]);
    $user->currentPharmacy->insurers()->attach($insurer);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationship.standardDelayDays', 45)
            ->where('relationship.penaltyTriggerDays', 90)
            ->where('relationship.penaltyRatePercent', 2.5),
        );
});

test('a period in the query string narrows the figures', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();
    $user->currentPharmacy->insurers()->attach($insurer);

    settledMonth($user->currentPharmacy, $insurer, 1, 400_000, 15);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer).'?period=current-quarter')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('period', 'current-quarter')
            ->where('relationship.declarations', 0),
        );
});

test('the screen never carries the private note', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 400_000,
        'amount_received' => 400_000,
        'delay_days' => 15,
        'private_note' => 'Relancer la comptabilite',
    ]);

    $response = $this->actingAs($user)->get(route('pharmacy.insurers.show', $insurer));

    expect(inertiaPropsJson($response))->not->toContain('Relancer la comptabilite');
});

test('a ticked insurer with nothing declared offers no export link', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();
    $user->currentPharmacy->insurers()->attach($insurer);

    // PharmacyExportController::insurerId() ne retient le filtre que pour un
    // assureur déclaré : le bouton rendrait sinon le fichier de toute
    // l'officine sans le dire.
    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('exportUrl', null));
});

test('an insurer with a history offers an export filtered on it', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();

    settledMonth($user->currentPharmacy, $insurer, 8, 400_000, 15);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer))
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'exportUrl',
            route('pharmacy.data-exports.download', [
                'insurer' => $insurer->id,
                'period' => 'last-12-months',
            ], absolute: false),
        ));
});
