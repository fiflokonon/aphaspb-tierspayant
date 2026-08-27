<?php

use App\Models\Pharmacy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

test('a pharmacy carries its own identifying fields', function () {
    expect(Schema::hasColumns('pharmacies', ['onpb_license', 'city', 'owner_name']))->toBeTrue();
});

test('a pharmacy is never a personal space', function () {
    expect(Schema::hasColumn('pharmacies', 'is_personal'))->toBeFalse();
});

test('the ONPB licence is unique when given', function () {
    Pharmacy::factory()->create(['onpb_license' => 'ONPB-001']);

    expect(fn () => Pharmacy::factory()->create(['onpb_license' => 'ONPB-001']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the ONPB licence may be left out entirely', function () {
    Pharmacy::factory()->count(2)->create(['onpb_license' => null]);

    expect(Pharmacy::query()->whereNull('onpb_license')->count())->toBe(2);
});

test('a profile is complete once the city and the owner are known', function () {
    expect(Pharmacy::factory()->create()->hasCompleteProfile())->toBeTrue()
        ->and(Pharmacy::factory()->create(['city' => null])->hasCompleteProfile())->toBeFalse()
        ->and(Pharmacy::factory()->create(['owner_name' => null])->hasCompleteProfile())->toBeFalse()
        ->and(Pharmacy::factory()->create(['city' => ''])->hasCompleteProfile())->toBeFalse();
});

test('a pharmacy born from a Joomla ticket alone has no profile yet', function () {
    $bare = Pharmacy::factory()->create(['city' => null, 'owner_name' => null]);

    expect($bare->hasCompleteProfile())->toBeFalse();
});
