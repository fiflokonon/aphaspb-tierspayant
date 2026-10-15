<?php

use App\Models\Pharmacy;
use Illuminate\Support\Facades\Schema;

test('a pharmacy carries its own identifying fields', function () {
    expect(Schema::hasColumns('pharmacies', ['city', 'owner_name']))->toBeTrue();
});

test('a pharmacy no longer carries an ONPB licence', function () {
    expect(Schema::hasColumn('pharmacies', 'onpb_license'))->toBeFalse();
});

test('a pharmacy is never a personal space', function () {
    expect(Schema::hasColumn('pharmacies', 'is_personal'))->toBeFalse();
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
