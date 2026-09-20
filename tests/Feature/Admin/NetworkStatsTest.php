<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkStatsService;
use Illuminate\Support\Facades\DB;
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

test('the monthly breakdown gives one row per month an insurer was declared to', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    foreach ([6, 7] as $month) {
        Pharmacy::factory()->count(2)->create()->each(
            fn (Pharmacy $pharmacy) => Declaration::factory()->create([
                'pharmacy_id' => $pharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => $month,
                'amount_invoiced' => 1_000_000,
                'amount_received' => 600_000,
                'delay_days' => 40,
            ]),
        );
    }

    // Un mois rejeté portant un délai : il compte dans les montants, jamais
    // dans la moyenne de délai — celle-ci ne parle que des mois réglés.
    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 0,
        'amount_received' => 0,
        'status' => DeclarationStatus::Rejected,
        'is_status_manual' => true,
        'delay_days' => 400,
    ]);

    $monthly = app(NetworkStatsService::class)->monthlyByInsurer(
        [$insurer->id],
        new Period(2026, 1),
        new Period(2026, 8),
    );

    expect($monthly[$insurer->id])->toHaveCount(2)
        // Le plus récent en tête : un rapport se lit du haut.
        ->and($monthly[$insurer->id][0]['month'])->toBe(7)
        ->and($monthly[$insurer->id][0]['declarations'])->toBe(3)
        ->and($monthly[$insurer->id][0]['invoiced'])->toBe(2_000_000)
        ->and($monthly[$insurer->id][0]['received'])->toBe(1_200_000)
        ->and($monthly[$insurer->id][0]['outstanding'])->toBe(800_000)
        ->and($monthly[$insurer->id][0]['averageDelayDays'])->toBe(40.0);
});

test('the monthly breakdown ignores insurers not asked for', function () {
    $asked = Insurer::factory()->create();
    $other = Insurer::factory()->create();

    foreach ([$asked, $other] as $insurer) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 7,
            'amount_invoiced' => 500_000,
            'amount_received' => 500_000,
            'delay_days' => 10,
        ]);
    }

    $monthly = app(NetworkStatsService::class)->monthlyByInsurer(
        [$asked->id],
        new Period(2026, 1),
        new Period(2026, 8),
    );

    expect($monthly)->toHaveKey($asked->id)
        ->and($monthly)->not->toHaveKey($other->id);
});

test('an empty list of insurers costs no query at all', function () {
    DB::enableQueryLog();

    expect(app(NetworkStatsService::class)->monthlyByInsurer([], new Period(2026, 1), new Period(2026, 8)))
        ->toBe([])
        ->and(DB::getQueryLog())->toBeEmpty();
});
