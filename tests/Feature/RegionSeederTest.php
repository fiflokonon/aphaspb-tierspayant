<?php

use App\Models\City;
use App\Models\Region;
use Database\Seeders\CitySeeder;
use Database\Seeders\RegionSeeder;

test('the seeder groups every commune into the seven pharmaceutical regions', function () {
    $this->seed([CitySeeder::class, RegionSeeder::class]);

    $regionOf = fn (string $city): ?string => City::query()->where('name', $city)->first()->region?->name;

    expect(Region::query()->count())->toBe(7)
        ->and(City::query()->whereNull('region_id')->count())->toBe(0)
        ->and($regionOf('Cotonou'))->toBe('Région 1')
        ->and($regionOf('Abomey-Calavi'))->toBe('Région 2')
        ->and($regionOf('Pobè'))->toBe('Région 3')
        ->and($regionOf('Aplahoué'))->toBe('Région 4')
        ->and($regionOf('Savalou'))->toBe('Région 5')
        ->and($regionOf('Parakou'))->toBe('Région 6')
        ->and($regionOf('Djougou'))->toBe('Région 7');
});

test('re-running the seeder keeps a commune the association moved by hand', function () {
    $this->seed([CitySeeder::class, RegionSeeder::class]);

    $elsewhere = Region::query()->where('name', 'Région 2')->value('id');
    City::query()->where('name', 'Cotonou')->update(['region_id' => $elsewhere]);

    $this->seed(RegionSeeder::class);

    expect(Region::query()->count())->toBe(7)
        ->and(City::query()->where('name', 'Cotonou')->value('region_id'))->toBe($elsewhere);
});
