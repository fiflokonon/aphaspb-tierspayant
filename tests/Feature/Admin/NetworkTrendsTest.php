<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function networkDeclare(Insurer $insurer, int $pharmacies, array $attributes = [], int $month = 8): void
{
    Pharmacy::factory()->count($pharmacies)->create()->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->create([
            'amount_invoiced' => 1_000_000,
            'amount_received' => 700_000,
            'delay_days' => 40,
            ...$attributes,
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => $month,
        ]),
    );
}

test('the four network KPIs are rendered', function () {
    networkDeclare(Insurer::factory()->create(), 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.trends'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Trends')
            ->where('summary.invoiced', 5_000_000)
            ->where('summary.received', 3_500_000)
            ->where('summary.outstanding', 1_500_000)
            ->where('summary.recoveryRate', 70)
            ->where('summary.declaringPharmacies', 5)
            ->where('summary.weightedDelayDays', 40)
            ->where('threshold', 30),
        );
});

test('the delay trend is a deferred prop', function () {
    networkDeclare(Insurer::factory()->create(), 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.trends'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('trend'));
});

test('the aggregated table renders sufficient and insufficient insurers alike', function () {
    $shown = Insurer::factory()->create(['name' => 'Assez de declarants']);
    $hidden = Insurer::factory()->create(['name' => 'Trop peu']);

    networkDeclare($shown, 5);
    networkDeclare($hidden, 2);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.trends'))
        ->assertInertia(function (AssertableInertia $page) {
            $rows = collect($page->toArray()['props']['amounts']);

            $sufficient = $rows->firstWhere('sufficient', true);
            $insufficient = $rows->firstWhere('sufficient', false);

            expect($sufficient['insurerName'])->toBe('Assez de declarants')
                ->and($sufficient['invoiced'])->toBe(5_000_000)
                ->and($insufficient['insurerName'])->toBe('Trop peu')
                ->and($insufficient['declaringPharmacies'])->toBe(2)
                ->and($insufficient['invoiced'])->toBeNull();
        });
});

test('the threshold comes from the settings, not a constant', function () {
    app(SettingsRepository::class)->set('payment_delay_threshold_days', 45);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.trends'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('threshold', 45));
});

test('the trends screen exposes no private note and no officine identity', function () {
    $insurer = Insurer::factory()->create();
    $pharmacies = Pharmacy::factory()->count(5)->create();

    foreach ($pharmacies as $pharmacy) {
        Declaration::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 500_000,
            'delay_days' => 50,
            'private_note' => 'note privée à ne jamais divulguer',
        ]);
    }

    $props = inertiaPropsJson(
        $this->actingAs(User::factory()->networkAdmin()->create())
            ->get(route('admin.trends')),
    );

    expect($props)->not->toContain('note privée à ne jamais divulguer')
        ->and($props)->not->toContain('private_note')
        ->and($props)->not->toContain('privateNote')
        // The quoted key, not the bare word: « current_pharmacy_id » is a
        // legitimate field of the signed-in user and contains the substring.
        ->and($props)->not->toContain('"pharmacy_id"');

    foreach ($pharmacies as $pharmacy) {
        expect($props)->not->toContain($pharmacy->name);
    }
});

test('a pharmacy account cannot reach the trends screen', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.trends'))
        ->assertForbidden();
});

test('the trends entry appears in the admin navigation', function () {
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.trends'))
        ->assertInertia(function (AssertableInertia $page) {
            $nav = collect($page->toArray()['props']['console']['nav']);

            expect($nav->pluck('label'))->toContain('Évolution')
                ->and($nav->where('active', true)->pluck('label')->all())->toBe(['Évolution']);
        });
});
