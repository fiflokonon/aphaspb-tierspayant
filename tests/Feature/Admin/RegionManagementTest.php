<?php

use App\Models\City;
use App\Models\Region;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->admin = User::factory()->networkAdmin()->notOnboarded()->create();
});

test('the screen lists the regions with their communes, and every commune with its region', function () {
    $region = Region::factory()->create(['name' => 'Sud']);
    $cotonou = City::factory()->create(['name' => 'Cotonou', 'region_id' => $region->id]);
    $parakou = City::factory()->create(['name' => 'Parakou']);

    $this->actingAs($this->admin)
        ->get(route('admin.regions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Regions')
            ->where('regions.0.name', 'Sud')
            ->where('regions.0.cityIds', [$cotonou->id])
            ->where('regions.0.cities', ['Cotonou'])
            ->where('cities', [
                ['id' => $cotonou->id, 'name' => 'Cotonou', 'regionId' => $region->id],
                ['id' => $parakou->id, 'name' => 'Parakou', 'regionId' => null],
            ]),
        );
});

test('a region is created with its communes', function () {
    $cities = City::factory()->count(2)->create();

    $this->actingAs($this->admin)
        ->post(route('admin.regions.store'), [
            'name' => 'Littoral-Atlantique',
            'city_ids' => $cities->pluck('id')->all(),
        ])
        ->assertRedirect(route('admin.regions'));

    $region = Region::query()->where('name', 'Littoral-Atlantique')->sole();

    expect($region->cities()->pluck('id')->sort()->values()->all())
        ->toBe($cities->pluck('id')->sort()->values()->all());
});

test('a commune already in another region cannot be taken', function () {
    $north = Region::factory()->create();
    $city = City::factory()->create(['region_id' => $north->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.regions.store'), ['name' => 'Sud', 'city_ids' => [$city->id]])
        ->assertSessionHasErrors('city_ids.0');

    expect(Region::query()->where('name', 'Sud')->exists())->toBeFalse()
        ->and($city->fresh()->region_id)->toBe($north->id);
});

test('updating a region keeps its own communes and releases the ones removed', function () {
    $region = Region::factory()->create(['name' => 'Centre']);
    $kept = City::factory()->create(['region_id' => $region->id]);
    $released = City::factory()->create(['region_id' => $region->id]);
    $added = City::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.regions.update', $region), [
            'name' => 'Centre-Zou',
            'city_ids' => [$kept->id, $added->id],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.regions'));

    expect($region->fresh()->name)->toBe('Centre-Zou')
        ->and($kept->fresh()->region_id)->toBe($region->id)
        ->and($added->fresh()->region_id)->toBe($region->id)
        ->and($released->fresh()->region_id)->toBeNull();
});

test('two regions cannot share a name', function () {
    Region::factory()->create(['name' => 'Nord']);

    $this->actingAs($this->admin)
        ->post(route('admin.regions.store'), ['name' => 'Nord', 'city_ids' => []])
        ->assertSessionHasErrors(['name' => 'Une région porte déjà ce nom.']);
});

test('deleting a region frees its communes without deleting them', function () {
    $region = Region::factory()->create();
    $city = City::factory()->create(['region_id' => $region->id]);

    $this->actingAs($this->admin)
        ->delete(route('admin.regions.destroy', $region))
        ->assertRedirect(route('admin.regions'));

    expect(Region::query()->count())->toBe(0)
        ->and($city->fresh())->not->toBeNull()
        ->and($city->fresh()->region_id)->toBeNull();
});

test('a pharmacy account cannot manage regions', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.regions.store'), ['name' => 'Nord', 'city_ids' => []])
        ->assertForbidden();
});
