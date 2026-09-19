<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Support\Fcfa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
});

function dashboardUrlFor(User $user): string
{
    return route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]);
}

test('the officine sees its own payment KPIs', function () {
    $user = User::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => Insurer::factory(),
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 730_000,
        'delay_days' => 41,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Dashboard')
            ->where('summary.invoiced', 1_000_000)
            ->where('summary.recoveryRate', 73)
            ->where('summary.weightedDelayDays', 41)
            ->where('summary.outstanding', 270_000)
            ->has('ageing', 4)
            ->has('owed'),
        );
});

test('the dashboard carries the recovery rate of each insurer', function () {
    $user = User::factory()->create();

    // The factory ticks exactly one insurer, so the table holds one row.
    $insurer = $user->currentPharmacy->insurers()->sole();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 480_000,
        'delay_days' => 50,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recovery', 1)
            ->where('recovery.0.insurerName', $insurer->name)
            ->where('recovery.0.invoiced', 1_000_000)
            ->where('recovery.0.received', 480_000)
            ->where('recovery.0.outstanding', 520_000)
            ->where('recovery.0.recoveryRate', 48),
        );
});

test('the payment journey is a deferred prop', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('journey'));
});

// The journey's content is covered directly by PharmacyStatsTest; replaying
// Inertia's partial-request protocol here would only test the protocol.

test('an officine never sees another officine figures', function () {
    $user = User::factory()->create();
    $other = Pharmacy::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $other->id,
        'insurer_id' => Insurer::factory(),
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 9_000_000,
        'amount_received' => 0,
        'delay_days' => null,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.invoiced', 0));
});

test('the sidebar notice carries the outstanding to chase', function () {
    $user = User::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => Insurer::factory(),
        'period_year' => 2026,
        'period_month' => 4,
        'amount_invoiced' => 800_000,
        'amount_received' => 0,
        'delay_days' => null,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(function (AssertableInertia $page) {
            $notices = collect($page->toArray()['props']['console']['notices']);

            expect($notices)->toHaveCount(1)
                ->and($notices[0]['title'])->toBe('Encours à relancer')
                ->and($notices[0]['body'])->toContain(Fcfa::format(800_000));
        });
});

test('an officine with nothing outstanding gets no chase notice', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('console.notices', 0));
});

test('an admin account cannot reach the officine dashboard', function () {
    $admin = User::factory()->networkAdmin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard', ['current_pharmacy' => $admin->currentPharmacy->slug]))
        ->assertForbidden();
});

test('the dashboard carries the insurer the journey is narrowed to', function () {
    $user = User::factory()->create();
    $insurer = $user->currentPharmacy->insurers()->sole();

    $this->actingAs($user)
        ->get(dashboardUrlFor($user).'?insurer='.$insurer->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.insurer', $insurer->id));
});

test('an insurer the officine has nothing to do with is ignored', function () {
    $user = User::factory()->create();
    $stranger = Insurer::factory()->create();

    $this->actingAs($user)
        ->get(dashboardUrlFor($user).'?insurer='.$stranger->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.insurer', null));
});

/** Une facture déposée il y a $daysAgo jours et jamais réglée. */
function overdueOn(User $user, Insurer $insurer, int $daysAgo, int $invoiced = 1_000_000): Declaration
{
    $deposited = CarbonImmutable::create(2026, 8, 15)->subDays($daysAgo);

    return Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => $deposited->year,
        'period_month' => $deposited->month,
        'amount_invoiced' => $invoiced,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => $deposited,
        'paid_on' => null,
        'delay_days' => null,
    ]);
}

test('a dashboard with nothing overdue shows no banner', function () {
    $user = User::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => Insurer::factory()->create(['standard_delay_days' => 30]),
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'delay_days' => 10,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overdueSummary', null)
            ->has('overdue', 0),
        );
});

test('the banner sums the overdue invoices and names the worst', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'Mutuelle Bénin', 'standard_delay_days' => 30]);

    // Déposée il y a 120 jours : 90 jours de dépassement, trois tranches.
    overdueOn($user, $insurer, 120);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overdueSummary.count', 1)
            ->where('overdueSummary.outstanding', 1_000_000)
            ->where('overdueSummary.penalty', 60_000)
            ->where('overdueSummary.worst.insurerName', 'Mutuelle Bénin')
            ->where('overdueSummary.worst.overdueDays', 90)
            ->where('overdueSummary.hidden', 0)
            ->has('overdue', 1)
            ->where('overdue.0.penalty', 60_000)
            ->where('overdue.0.overdueDays', 90)
            ->where('overdue.0.insurerId', $insurer->id),
        );
});

test('an insurer without a clause leaves the penalty empty', function () {
    $user = User::factory()->create();

    overdueOn($user, Insurer::factory()->create(['standard_delay_days' => 30]), 120);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overdueSummary.penalty', null)
            ->where('overdue.0.penalty', null),
        );
});

test('the table shows the eight worst and says how many it hides', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    // Espacées de 35 jours : la clé unique porte sur le mois déclaré.
    foreach (range(0, 10) as $step) {
        overdueOn($user, $insurer, 60 + $step * 35, 100_000);
    }

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('overdue', 8)
            ->where('overdueSummary.count', 11)
            ->where('overdueSummary.hidden', 3)
            // Les huit **pires**, pas les huit premières venues : la plus
            // ancienne est en tête, et celle qu'on garde en dernier dépasse
            // encore les trois qu'on cache.
            ->where('overdue.0.overdueDays', 60 + 10 * 35 - 30)
            ->where('overdue.7.overdueDays', 60 + 3 * 35 - 30),
        );
});

test('the dashboard query count does not grow with the overdue invoices', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    overdueOn($user, $insurer, 100);

    DB::enableQueryLog();
    $this->actingAs($user)->get(dashboardUrlFor($user))->assertOk();
    $withOne = count(DB::getQueryLog());
    DB::flushQueryLog();

    // Espacées de 35 jours : la clé unique porte sur le mois déclaré.
    foreach (range(1, 6) as $step) {
        overdueOn($user, $insurer, 100 + $step * 35);
    }

    DB::flushQueryLog();
    $this->actingAs($user)->get(dashboardUrlFor($user))->assertOk();
    $withSeven = count(DB::getQueryLog());

    // Le chargement des versements est groupé : sept fois plus de lignes en
    // retard ne doit pas coûter une requête de plus. Un N+1 est la première
    // cause de lenteur perçue de cette application.
    expect($withSeven)->toBe($withOne);
});
