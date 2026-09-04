<?php

use App\Models\City;
use App\Models\Pharmacy;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

test('the seeder loads every commune of Benin and can be re-run', function () {
    $this->seed(CitySeeder::class);

    expect(City::query()->count())->toBe(77)
        ->and(City::query()->where('name', 'Cotonou')->value('department'))->toBe('Littoral');

    // Re-running on an installed database must not duplicate a single row:
    // this seeder is meant to be safe to call again after a deploy.
    $this->seed(CitySeeder::class);

    expect(City::query()->count())->toBe(77);
});

test('the onboarding offers the seeded communes', function () {
    Http::fake();
    $this->seed(CitySeeder::class);

    $this->actingAs(User::factory()->notOnboarded()->create())
        ->get(route('onboarding.profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('cities', 77)
            ->where('cities.0', 'Abomey')
            ->etc(),
        );
});

test('a locality an officine already uses is still offered when it is not a commune', function () {
    Http::fake();
    $this->seed(CitySeeder::class);

    // A hamlet, or a spelling the association settled on. Reference data
    // arriving must not make it disappear from under the field.
    Pharmacy::factory()->create(['city' => 'Ouassa-Péhunco']);

    $this->actingAs(User::factory()->notOnboarded()->create())
        ->get(route('onboarding.profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('cities', 78)
            ->etc(),
        );
});

test('the admin filters stay on the cities officines actually declare from', function () {
    Http::fake();
    $this->seed(CitySeeder::class);

    Pharmacy::factory()->create(['city' => 'Cotonou']);

    // Seeding must not leak into the filters: 77 options, 76 of them matching
    // no officine, would make the control useless.
    $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cities', ['Cotonou'])
            ->etc(),
        );
});
