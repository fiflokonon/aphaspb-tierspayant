<?php

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the pharmacies index page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('pharmacies.index'));

    $response->assertOk();
});

test('pharmacies can be created', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('pharmacies.store'), [
            'name' => 'Test Pharmacy',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('pharmacies', [
        'name' => 'Test Pharmacy',
    ]);
});

test('pharmacy slug uses next available suffix', function () {
    $user = User::factory()->create();

    Pharmacy::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Pharmacy::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Pharmacy::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this
        ->actingAs($user)
        ->post(route('pharmacies.store'), [
            'name' => 'Acme',
        ]);

    $this->assertDatabaseHas('pharmacies', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('the pharmacy edit page can be rendered', function () {
    $user = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->get(route('pharmacies.edit', $pharmacy));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pharmacies/Edit')
            ->where('members.0.role', PharmacyRole::Owner->value)
            ->where('members.0.role_label', PharmacyRole::Owner->label()),
        );
});

test('pharmacies can be updated by owners', function () {
    $user = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create(['name' => 'Original Name']);

    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->patch(route('pharmacies.update', $pharmacy), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('pharmacies.edit', $pharmacy->fresh()));

    $this->assertDatabaseHas('pharmacies', [
        'id' => $pharmacy->id,
        'name' => 'Updated Name',
    ]);
});

test('pharmacies cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->patch(route('pharmacies.update', $pharmacy), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('pharmacies can be deleted by owners', function () {
    $user = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('pharmacies.destroy', $pharmacy), [
            'name' => $pharmacy->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('pharmacies', [
        'id' => $pharmacy->id,
    ]);
});

test('pharmacy deletion requires name confirmation', function () {
    $user = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('pharmacies.destroy', $pharmacy), [
            'name' => 'Wrong Name',
        ]);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('pharmacies', [
        'id' => $pharmacy->id,
        'deleted_at' => null,
    ]);
});

test('deleting current pharmacy switches to alphabetically first remaining pharmacy', function () {
    $user = User::factory()->create(['name' => 'Mike']);

    $zuluPharmacy = Pharmacy::factory()->create(['name' => 'Zulu Pharmacy']);
    $zuluPharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $alphaPharmacy = Pharmacy::factory()->create(['name' => 'Alpha Pharmacy']);
    $alphaPharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $betaPharmacy = Pharmacy::factory()->create(['name' => 'Beta Pharmacy']);
    $betaPharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $user->update(['current_pharmacy_id' => $zuluPharmacy->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('pharmacies.destroy', $zuluPharmacy), [
            'name' => $zuluPharmacy->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('pharmacies', [
        'id' => $zuluPharmacy->id,
    ]);

    expect($user->fresh()->current_pharmacy_id)->toEqual($alphaPharmacy->id);
});

test('deleting the current pharmacy falls back to the alphabetically first remaining one', function () {
    $user = User::factory()->create();
    $remaining = $user->currentPharmacy;
    $pharmacy = Pharmacy::factory()->create(['name' => 'Zulu Pharmacy']);
    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $user->update(['current_pharmacy_id' => $pharmacy->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('pharmacies.destroy', $pharmacy), [
            'name' => $pharmacy->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('pharmacies', [
        'id' => $pharmacy->id,
    ]);

    expect($user->fresh()->current_pharmacy_id)->toEqual($remaining->id);
});

test('deleting a non current pharmacy leaves the current one unchanged', function () {
    $user = User::factory()->create();
    $remaining = $user->currentPharmacy;
    $pharmacy = Pharmacy::factory()->create();
    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);

    $user->update(['current_pharmacy_id' => $remaining->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('pharmacies.destroy', $pharmacy), [
            'name' => $pharmacy->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('pharmacies', [
        'id' => $pharmacy->id,
    ]);

    expect($user->fresh()->current_pharmacy_id)->toEqual($remaining->id);
});

test('members can leave non personal pharmacies', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('pharmacies.leave', $pharmacy));

    $response->assertRedirect(route('pharmacies.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "You left the pharmacy \"{$pharmacy->name}\""]);

    expect($member->fresh()->belongsToPharmacy($pharmacy))->toBeFalse();
});

test('leaving current pharmacy switches to alphabetically first remaining pharmacy', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Mike']);

    $zuluPharmacy = Pharmacy::factory()->create(['name' => 'Zulu Pharmacy']);
    $zuluPharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $zuluPharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $alphaPharmacy = Pharmacy::factory()->create(['name' => 'Alpha Pharmacy']);
    $alphaPharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $betaPharmacy = Pharmacy::factory()->create(['name' => 'Beta Pharmacy']);
    $betaPharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $member->update(['current_pharmacy_id' => $zuluPharmacy->id]);

    $response = $this
        ->actingAs($member)
        ->delete(route('pharmacies.leave', $zuluPharmacy));

    $response->assertRedirect(route('pharmacies.index'));

    expect($member->fresh()->belongsToPharmacy($zuluPharmacy))->toBeFalse();
    expect($member->fresh()->current_pharmacy_id)->toEqual($alphaPharmacy->id);
});

test('pharmacy owners cannot leave their pharmacy', function () {
    $owner = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('pharmacies.leave', $pharmacy));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToPharmacy($pharmacy))->toBeTrue();
});

test('users cannot leave pharmacies they dont belong to', function () {
    $user = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('pharmacies.leave', $pharmacy));

    $response->assertForbidden();
});

test('deleting a pharmacy moves the other affected members onto their remaining one', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $memberPharmacy = $member->currentPharmacy;

    $pharmacy = Pharmacy::factory()->create();
    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $owner->update(['current_pharmacy_id' => $pharmacy->id]);
    $member->update(['current_pharmacy_id' => $pharmacy->id]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('pharmacies.destroy', $pharmacy), [
            'name' => $pharmacy->name,
        ]);

    $response->assertRedirect();

    expect($member->fresh()->current_pharmacy_id)->toEqual($memberPharmacy->id);
});

test('an owner deleting their only pharmacy is left without a current one', function () {
    $user = User::factory()->create();
    $onlyPharmacy = $user->currentPharmacy;

    $response = $this
        ->actingAs($user)
        ->delete(route('pharmacies.destroy', $onlyPharmacy), [
            'name' => $onlyPharmacy->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('pharmacies', [
        'id' => $onlyPharmacy->id,
    ]);

    expect($user->fresh()->current_pharmacy_id)->toBeNull();
});

test('pharmacies cannot be deleted by non owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('pharmacies.destroy', $pharmacy), [
            'name' => $pharmacy->name,
        ]);

    $response->assertForbidden();
});

test('users can switch pharmacies', function () {
    $user = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($user)
        ->post(route('pharmacies.switch', $pharmacy));

    $response->assertRedirect();

    expect($user->fresh()->current_pharmacy_id)->toEqual($pharmacy->id);
});

test('users cannot switch to pharmacy they dont belong to', function () {
    $user = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('pharmacies.switch', $pharmacy));

    $response->assertForbidden();
});

test('guests cannot access pharmacies', function () {
    $response = $this->get(route('pharmacies.index'));

    $response->assertRedirect(route('login'));
});
