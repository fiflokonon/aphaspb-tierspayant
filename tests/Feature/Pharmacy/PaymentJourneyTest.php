<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Support\Fcfa;
use Carbon\CarbonImmutable;
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
