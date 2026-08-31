<?php

use App\Enums\DeclarationStatus;
use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
});

/**
 * An officine working with the given insurers, and its titulaire.
 *
 * @param  list<Insurer>  $insurers
 */
function officineFor(array $insurers): User
{
    $user = User::factory()->notOnboarded()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->insurers()->attach(collect($insurers)->pluck('id'));
    $user->switchPharmacy($pharmacy);

    return $user->fresh();
}

test('the history only shows the current officine declarations', function () {
    $mine = Insurer::factory()->create(['name' => 'Mon assureur']);
    $user = officineFor([$mine]);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $mine->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $mine->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/History')
            ->has('declarations.data', 1),
        );
});

test('declarations are listed newest first', function () {
    $insurer = Insurer::factory()->create();
    $user = officineFor([$insurer]);

    foreach ([[2025, 12], [2026, 8], [2026, 3]] as [$year, $month]) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => $year,
            'period_month' => $month,
        ]);
    }

    $this->actingAs($user)
        ->get(route('pharmacy.history'))
        ->assertInertia(function (AssertableInertia $page) {
            $periods = collect($page->toArray()['props']['declarations']['data'])
                ->map(fn (array $row) => $row['year'].'-'.str_pad((string) $row['month'], 2, '0', STR_PAD_LEFT));

            expect($periods->all())->toBe(['2026-08', '2026-03', '2025-12']);
        });
});

test('the private note is present — this is the only screen where it may be', function () {
    $insurer = Insurer::factory()->create();
    $user = officineFor([$insurer]);

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 100,
        'amount_received' => 0,
        'delay_days' => null,
        'status' => DeclarationStatus::Rejected,
        'is_status_manual' => true,
        'private_note' => 'motif absence ordonnance',
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.history'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('declarations.data.0.privateNote', 'motif absence ordonnance'),
        );
});

test('the insurer filter narrows the list', function () {
    $kept = Insurer::factory()->create(['name' => 'Gardé']);
    $other = Insurer::factory()->create(['name' => 'Écarté']);
    $user = officineFor([$kept, $other]);

    foreach ([$kept, $other] as $insurer) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    $this->actingAs($user)
        ->get(route('pharmacy.history', ['insurer' => $kept->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('declarations.data', 1)
            ->where('declarations.data.0.insurerName', 'Gardé'),
        );
});

test('the year filter narrows the list', function () {
    $insurer = Insurer::factory()->create();
    $user = officineFor([$insurer]);

    foreach ([2025, 2026] as $year) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => $year,
            'period_month' => 8,
        ]);
    }

    $this->actingAs($user)
        ->get(route('pharmacy.history', ['year' => 2025]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('declarations.data', 1)
            ->where('declarations.data.0.year', 2025),
        );
});

test('filtering on an insurer the officine never ticked leaks nothing', function () {
    $mine = Insurer::factory()->create();
    $stranger = Insurer::factory()->create();
    $user = officineFor([$mine]);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $mine->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.history', ['insurer' => $stranger->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('declarations.data', 0));
});

test('each row carries the url that reopens it for correction', function () {
    $insurer = Insurer::factory()->create();
    $user = officineFor([$insurer]);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.history'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('declarations.data.0.editUrl', route('pharmacy.declare', [
                'insurer' => $insurer->id,
                'year' => 2026,
                'month' => 7,
            ], absolute: false)),
        );
});

test('an admin account cannot reach the officine history', function () {
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('pharmacy.history'))
        ->assertForbidden();
});

test('the register is paginated', function () {
    $insurer = Insurer::factory()->create();
    $user = officineFor([$insurer]);

    // Two years of monthly declarations: 24 rows, more than one page.
    foreach ([2025, 2026] as $year) {
        foreach (range(1, 12) as $month) {
            Declaration::factory()->paid()->create([
                'pharmacy_id' => $user->currentPharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => $year,
                'period_month' => $month,
            ]);
        }
    }

    $this->actingAs($user)
        ->get(route('pharmacy.history'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('declarations.data', 20)
            ->where('declarations.current_page', 1)
            ->where('declarations.last_page', 2)
            ->where('declarations.total', 24),
        );
});

test('the second page carries the rest, without repeating the first', function () {
    $insurer = Insurer::factory()->create();
    $user = officineFor([$insurer]);

    foreach ([2025, 2026] as $year) {
        foreach (range(1, 12) as $month) {
            Declaration::factory()->paid()->create([
                'pharmacy_id' => $user->currentPharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => $year,
                'period_month' => $month,
            ]);
        }
    }

    $ids = fn (string $url) => collect(
        $this->actingAs($user)->get($url)->viewData('page')['props']['declarations']['data'],
    )->pluck('id');

    $first = $ids(route('pharmacy.history'));
    $second = $ids(route('pharmacy.history', ['page' => 2]));

    expect($second)->toHaveCount(4)
        ->and($first->intersect($second))->toBeEmpty();
});

test('a filter survives pagination', function () {
    $kept = Insurer::factory()->create();
    $other = Insurer::factory()->create();
    $user = officineFor([$kept, $other]);

    foreach ([$kept, $other] as $insurer) {
        foreach (range(1, 12) as $month) {
            Declaration::factory()->paid()->create([
                'pharmacy_id' => $user->currentPharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => $month,
            ]);
        }
    }

    $this->actingAs($user)
        ->get(route('pharmacy.history', ['insurer' => $kept->id, 'page' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('declarations.total', 12)
            ->where('filters.insurer', $kept->id),
        );
});

test('a page beyond the last one comes back empty rather than erroring', function () {
    $insurer = Insurer::factory()->create();
    $user = officineFor([$insurer]);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.history', ['page' => 99]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('declarations.data', 0));
});

test('the register honours a whitelisted page size', function () {
    $insurer = Insurer::factory()->create();
    $user = officineFor([$insurer]);

    foreach (range(1, 12) as $month) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2025,
            'period_month' => $month,
        ]);
    }

    $this->actingAs($user)
        ->get(route('pharmacy.history', ['per_page' => 10]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('declarations.data', 10)
            ->where('declarations.last_page', 2)
            ->where('filters.perPage', 10),
        );
});

test('a page size outside the whitelist falls back to the default', function () {
    $user = officineFor([Insurer::factory()->create()]);

    $this->actingAs($user)
        ->get(route('pharmacy.history', ['per_page' => 5000]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.perPage', 20));
});
