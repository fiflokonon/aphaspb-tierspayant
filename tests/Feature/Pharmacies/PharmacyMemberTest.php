<?php

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\User;

test('pharmacy member roles can be updated by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('pharmacies.members.update', [$pharmacy, $member]), [
            'role' => PharmacyRole::Admin->value,
        ]);

    $response->assertRedirect(route('pharmacies.edit', $pharmacy));

    expect($pharmacy->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(PharmacyRole::Admin->value);
});

test('pharmacy member roles cannot be updated by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($admin, ['role' => PharmacyRole::Admin->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->patch(route('pharmacies.members.update', [$pharmacy, $member]), [
            'role' => PharmacyRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('pharmacy members can be removed by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('pharmacies.members.destroy', [$pharmacy, $member]));

    $response->assertRedirect(route('pharmacies.edit', $pharmacy));

    expect($member->fresh()->belongsToPharmacy($pharmacy))->toBeFalse();
});

test('pharmacy members cannot be removed by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($admin, ['role' => PharmacyRole::Admin->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->delete(route('pharmacies.members.destroy', [$pharmacy, $member]));

    $response->assertForbidden();
});

test('pharmacy owner cannot be removed', function () {
    $owner = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('pharmacies.members.destroy', [$pharmacy, $owner]));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToPharmacy($pharmacy))->toBeTrue();
});

test('pharmacy member role cannot be set to owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('pharmacies.members.update', [$pharmacy, $member]), [
            'role' => PharmacyRole::Owner->value,
        ]);

    $response->assertSessionHasErrors('role');

    expect($pharmacy->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(PharmacyRole::Member->value);
});

test('a removed member falls back to their remaining pharmacy', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $memberPharmacy = $member->currentPharmacy;
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $member->update(['current_pharmacy_id' => $pharmacy->id]);

    $this
        ->actingAs($owner)
        ->delete(route('pharmacies.members.destroy', [$pharmacy, $member]));

    expect($member->fresh()->current_pharmacy_id)->toEqual($memberPharmacy->id);
});
