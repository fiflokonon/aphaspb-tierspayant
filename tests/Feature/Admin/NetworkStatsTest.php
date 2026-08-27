<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

/**
 * Give an insurer declarations from $count distinct pharmacies this month.
 *
 * @param  array<string, mixed>  $attributes
 */
function declareThisMonth(Insurer $insurer, int $count, array $attributes = []): void
{
    Pharmacy::factory()->count($count)->create()->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
            ...$attributes,
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
        ]),
    );
}

test('a network admin sees the per-insurer indicators', function () {
    $insurer = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    declareThisMonth($insurer, 5, ['delay_days' => 29]);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Network')
            ->has('indicators', 1)
            ->where('indicators.0.insurerName', 'NSIA Assurances')
            ->where('indicators.0.declaringPharmacies', 5)
            ->where('indicators.0.sufficient', true)
            ->where('indicators.0.averageDelayDays', 29),
        );
});

test('an insurer under the threshold is rendered as an explained state', function () {
    $insurer = Insurer::factory()->create(['name' => 'Atlantique Assurances']);
    declareThisMonth($insurer, 3);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('indicators.0.sufficient', false)
            ->where('indicators.0.insurerName', 'Atlantique Assurances')
            ->where('indicators.0.declaringPharmacies', 3)
            ->where('indicators.0.required', 5)
            ->where('indicators.0.averageDelayDays', null),
        );
});

test('sufficient insurers come first, sorted by rising delay', function () {
    $fast = Insurer::factory()->create(['name' => 'Rapide']);
    $slow = Insurer::factory()->create(['name' => 'Lente']);
    $hidden = Insurer::factory()->create(['name' => 'Masquée']);

    declareThisMonth($fast, 5, ['delay_days' => 20]);
    declareThisMonth($slow, 5, ['delay_days' => 70]);
    declareThisMonth($hidden, 2);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertInertia(function (AssertableInertia $page) {
            $names = collect($page->toArray()['props']['indicators'])->pluck('insurerName');

            expect($names->all())->toBe(['Rapide', 'Lente', 'Masquée']);
        });
});

test('the network KPIs summarise the whole period', function () {
    declareThisMonth(Insurer::factory()->create(), 5, ['delay_days' => 20]);
    declareThisMonth(Insurer::factory()->create(), 5, ['delay_days' => 60]);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.declaringPharmacies', 10)
            ->where('summary.averageDelayDays', 40)
            ->where('summary.withinThresholdShare', 50)
            // Each row carries the delay agreed with its own insurer: the
            // screen has no single network-wide threshold to state any more.
            ->where('indicators.0.standardDelayDays', 30)
            ->missing('threshold'),
        );
});

test('a pharmacy account cannot reach the network screen', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.network'))
        ->assertForbidden();
});

test('a guest is sent to Joomla to log in', function () {
    $this->get(route('admin.network'))->assertRedirect(route('login'));
});

test('the network screen never exposes a private note or a pharmacy identity', function () {
    $insurer = Insurer::factory()->create();
    $pharmacies = Pharmacy::factory()->count(5)->create();

    foreach ($pharmacies as $pharmacy) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'private_note' => 'note privée à ne jamais divulguer',
        ]);
    }

    // Asserted on the decoded props: the raw body json-escapes every accent,
    // so searching it for an accented officine name or note passes to no
    // effect. tests/Pest.php explains the trap in full.
    $props = inertiaPropsJson(
        $this->actingAs(User::factory()->networkAdmin()->create())
            ->get(route('admin.network')),
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
