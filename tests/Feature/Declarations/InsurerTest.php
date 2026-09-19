<?php

use App\Models\Insurer;
use App\Models\Pharmacy;
use Database\Seeders\InsurerSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

test('an insurer has no penalty clause by default', function () {
    $insurer = Insurer::factory()->create();

    expect($insurer->penalty_trigger_days)->toBeNull()
        ->and($insurer->penalty_rate_bp)->toBeNull()
        ->and($insurer->hasPenaltyClause())->toBeFalse()
        ->and($insurer->penalty_rate_percent)->toBeNull();
});

test('a penalty clause is read back as a percentage', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 90, ratePercent: 2.5)->create();

    expect($insurer->penalty_trigger_days)->toBe(90)
        ->and($insurer->penalty_rate_bp)->toBe(250)
        ->and($insurer->hasPenaltyClause())->toBeTrue()
        ->and($insurer->penalty_rate_percent)->toBe(2.5);
});

test('a clause needs both halves to count as one', function () {
    $trigger = Insurer::factory()->create(['penalty_trigger_days' => 90]);
    $rate = Insurer::factory()->create(['penalty_rate_bp' => 250]);

    expect($trigger->hasPenaltyClause())->toBeFalse()
        ->and($rate->hasPenaltyClause())->toBeFalse();
});

test('the tranche is thirty days', function () {
    expect(Insurer::PENALTY_TRANCHE_DAYS)->toBe(30);
});

test('the migration leaves every existing insurer without a clause', function () {
    // La migration ne sème rien, à rebours de celle de standard_delay_days qui
    // devait préserver l'ancien seuil global : ici il n'y a pas d'ancienne
    // valeur, et un défaut ferait apparaître des pénalités le jour du
    // déploiement sur des conventions qui n'en prévoient aucune.
    DB::table('insurers')->insert([
        'name' => 'Assureur historique',
        'is_active' => true,
        'standard_delay_days' => 45,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $insurer = Insurer::query()->where('name', 'Assureur historique')->sole();

    expect($insurer->penalty_trigger_days)->toBeNull()
        ->and($insurer->penalty_rate_bp)->toBeNull();
});
