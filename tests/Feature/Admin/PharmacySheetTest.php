<?php

use App\Models\City;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\Region;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->admin = User::factory()->networkAdmin()->notOnboarded()->create();
});

test('the sheet shows the officine, its region and its insurers with who still owes it', function () {
    $region = Region::factory()->create(['name' => 'Sud']);
    City::factory()->create(['name' => 'Cotonou', 'region_id' => $region->id]);

    $pharmacy = Pharmacy::factory()->create(['name' => 'Pharmacie Témoin', 'city' => 'Cotonou']);
    $owing = Insurer::factory()->create(['name' => 'Assur Lente']);
    $settled = Insurer::factory()->create(['name' => 'Assur Rapide']);
    $unchecked = Insurer::factory()->create(['name' => 'Assur Décochée']);
    $pharmacy->insurers()->attach([$owing->id, $settled->id]);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id, 'insurer_id' => $owing->id,
        'amount_invoiced' => 987_654, 'amount_received' => 0,
    ]);
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id, 'insurer_id' => $settled->id,
        'amount_invoiced' => 50_000, 'amount_received' => 50_000,
    ]);
    // Décoché depuis, mais doit encore : il reste sur la fiche.
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id, 'insurer_id' => $unchecked->id,
        'amount_invoiced' => 40_000, 'amount_received' => 10_000,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.pharmacies.show', $pharmacy))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($owing, $settled, $unchecked) {
            $page->component('admin/PharmacySheet')
                ->where('pharmacy.name', 'Pharmacie Témoin')
                ->where('pharmacy.region', 'Sud')
                ->where('insurers', [
                    ['id' => $unchecked->id, 'name' => 'Assur Décochée', 'owesMoney' => true],
                    ['id' => $owing->id, 'name' => 'Assur Lente', 'owesMoney' => true],
                    ['id' => $settled->id, 'name' => 'Assur Rapide', 'owesMoney' => false],
                ]);

            $serialised = json_encode($page->toArray()['props'], JSON_THROW_ON_ERROR);

            foreach (['987654', '40000', 'amount_invoiced', 'amount_received', 'private_note'] as $forbidden) {
                expect($serialised)->not->toContain($forbidden);
            }
        });
});

test('an officine whose city is in no region has no region', function () {
    $pharmacy = Pharmacy::factory()->create(['city' => 'Hameau']);

    $this->actingAs($this->admin)
        ->get(route('admin.pharmacies.show', $pharmacy))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pharmacy.region', null));
});

test('the registered list links each officine to its sheet and shows its region', function () {
    $region = Region::factory()->create(['name' => 'Nord']);
    City::factory()->create(['name' => 'Parakou', 'region_id' => $region->id]);
    $pharmacy = Pharmacy::factory()->create(['city' => 'Parakou']);

    $this->actingAs($this->admin)
        ->get(route('admin.pharmacies'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pharmacies.data.0.slug', $pharmacy->slug)
            ->where('pharmacies.data.0.region', 'Nord'),
        );
});

test('a pharmacy account cannot open an officine sheet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.pharmacies.show', Pharmacy::factory()->create()))
        ->assertForbidden();
});
