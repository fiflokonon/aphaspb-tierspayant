<?php

use App\Models\Insurer;
use App\Models\Pharmacy;
use Database\Seeders\InsurerSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

test('an insurer name is unique', function () {
    Insurer::factory()->create(['name' => 'SUNU Assurances']);

    expect(fn () => Insurer::factory()->create(['name' => 'SUNU Assurances']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an insurer is active by default', function () {
    expect(Insurer::factory()->create()->is_active)->toBeTrue()
        ->and(Insurer::factory()->inactive()->create()->is_active)->toBeFalse();
});

test('the active scope hides deactivated insurers', function () {
    Insurer::factory()->count(3)->create();
    Insurer::factory()->inactive()->count(2)->create();

    expect(Insurer::query()->active()->count())->toBe(3);
});

test('a pharmacy ticks the insurers it works with', function () {
    $pharmacy = Pharmacy::factory()->create();
    $insurers = Insurer::factory()->count(3)->create();

    $pharmacy->insurers()->attach($insurers);

    expect($pharmacy->insurers)->toHaveCount(3)
        ->and($insurers->first()->pharmacies)->toHaveCount(1);
});

test('a pharmacy cannot tick the same insurer twice', function () {
    $pharmacy = Pharmacy::factory()->create();
    $insurer = Insurer::factory()->create();

    $pharmacy->insurers()->attach($insurer);

    expect(fn () => $pharmacy->insurers()->attach($insurer))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the seeder loads the Benin insurers and can run twice', function () {
    $this->seed(InsurerSeeder::class);
    $first = Insurer::query()->count();

    $this->seed(InsurerSeeder::class);

    expect(Insurer::query()->count())->toBe($first)
        ->and($first)->toBeGreaterThanOrEqual(6)
        ->and(Insurer::query()->where('name', 'NSIA Assurances')->exists())->toBeTrue();
});
