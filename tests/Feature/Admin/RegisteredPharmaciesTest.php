<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

test('the list carries the identity the CDC allows', function () {
    Pharmacy::factory()->create([
        'name' => 'Pharmacie Le Bon Secours',
        'city' => 'Cotonou',
        'onpb_license' => 'ONPB-4212',
    ]);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.pharmacies'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $rows = collect($page->toArray()['props']['pharmacies']);
            $row = $rows->firstWhere('name', 'Pharmacie Le Bon Secours');

            expect($page->toArray()['component'])->toBe('admin/Pharmacies')
                ->and($row['city'])->toBe('Cotonou')
                ->and($row['onpbLicense'])->toBe('ONPB-4212')
                ->and($row)->toHaveKey('registeredAt');
        });
});

test('the list exposes no declaration data whatsoever', function () {
    $pharmacy = Pharmacy::factory()->create(['name' => 'Pharmacie Témoin']);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => Insurer::factory(),
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_234_567,
        'amount_received' => 89_012,
        'delay_days' => 77,
        'private_note' => 'note privée à ne jamais divulguer',
    ]);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.pharmacies'))
        ->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];

            // Asserted on the decoded props, not the raw HTML: Inertia
            // json_encodes them, which escapes every accent, so a body search
            // for « Témoin » or « privée » silently never matches.
            $rows = collect($props['pharmacies']);
            $row = $rows->firstWhere('name', 'Pharmacie Témoin');

            expect($row)->not->toBeNull()
                ->and(array_keys($row))
                ->toBe(['id', 'name', 'city', 'onpbLicense', 'registeredAt']);

            $serialised = json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            // Keys and seeded values, not French words: the sidebar's
            // compliance card legitimately says « aucune note privée, aucune
            // déclaration individuelle », so forbidding those words would fail
            // on the very sentence that promises they are absent.
            foreach ([
                'note privée à ne jamais divulguer',
                'private_note',
                'privateNote',
                'amount_invoiced',
                'amount_received',
                'delay_days',
                'period_year',
                '1234567',
                '89012',
            ] as $forbidden) {
                expect($serialised)->not->toContain($forbidden);
            }
        });
});

test('the city filter narrows the list', function () {
    Pharmacy::factory()->create(['city' => 'Cotonou', 'name' => 'Ici']);
    Pharmacy::factory()->create(['city' => 'Parakou', 'name' => 'Ailleurs']);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.pharmacies', ['city' => 'Cotonou']))
        ->assertInertia(function (AssertableInertia $page) {
            $names = collect($page->toArray()['props']['pharmacies'])->pluck('name');

            expect($names)->toContain('Ici')->and($names)->not->toContain('Ailleurs');
        });
});

test('the search narrows the list by name', function () {
    Pharmacy::factory()->create(['name' => 'Pharmacie du Port']);
    Pharmacy::factory()->create(['name' => 'Pharmacie des Collines']);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.pharmacies', ['search' => 'Port']))
        ->assertInertia(function (AssertableInertia $page) {
            $names = collect($page->toArray()['props']['pharmacies'])->pluck('name');

            expect($names)->toContain('Pharmacie du Port')
                ->and($names)->not->toContain('Pharmacie des Collines');
        });
});

test('deleted officines do not appear', function () {
    Pharmacy::factory()->create(['name' => 'Encore là']);
    Pharmacy::factory()->trashed()->create(['name' => 'Partie']);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.pharmacies'))
        ->assertInertia(function (AssertableInertia $page) {
            $names = collect($page->toArray()['props']['pharmacies'])->pluck('name');

            expect($names)->toContain('Encore là')->and($names)->not->toContain('Partie');
        });
});

test('the totals match what screen 2a reports', function () {
    Pharmacy::factory()->count(4)->create(['city' => 'Cotonou']);
    Pharmacy::factory()->count(2)->create(['city' => 'Parakou']);

    // notOnboarded: an APhaSPB administrator owns no officine, and the factory
    // would otherwise create one and inflate the count by a phantom row.
    $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('admin.pharmacies'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('total', 6)
            ->has('cities', 2),
        );
});

test('a pharmacy account cannot reach the registered list', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.pharmacies'))
        ->assertForbidden();
});
