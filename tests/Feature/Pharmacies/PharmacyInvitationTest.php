<?php

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\PharmacyInvitation;
use App\Models\User;
use App\Notifications\Pharmacies\PharmacyInvitation as PharmacyInvitationNotification;
use Illuminate\Support\Facades\Notification;

test('pharmacy invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('pharmacies.invitations.store', $pharmacy), [
            'email' => 'invited@example.com',
            'role' => PharmacyRole::Member->value,
        ]);

    $response->assertRedirect(route('pharmacies.edit', $pharmacy));

    $this->assertDatabaseHas('pharmacy_invitations', [
        'pharmacy_id' => $pharmacy->id,
        'email' => 'invited@example.com',
        'role' => PharmacyRole::Member->value,
    ]);
});

test('invitation email for existing users uses login route', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => $invitedUser->email,
        'invited_by' => $owner->id,
    ]);

    $mail = (new PharmacyInvitationNotification($invitation))->toMail($invitedUser);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('dashboard', implode(' ', $mail->introLines));
});

test('invitation email for unknown users uses login route', function () {
    $owner = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => 'unknown@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new PharmacyInvitationNotification($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('log in', strtolower(implode(' ', $mail->introLines)));
});

test('pharmacy invitations can be created by admins', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($admin, ['role' => PharmacyRole::Admin->value]);

    $response = $this
        ->actingAs($admin)
        ->post(route('pharmacies.invitations.store', $pharmacy), [
            'email' => 'invited@example.com',
            'role' => PharmacyRole::Member->value,
        ]);

    $response->assertRedirect(route('pharmacies.edit', $pharmacy));
});

test('existing pharmacy members cannot be invited', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('pharmacies.invitations.store', $pharmacy), [
            'email' => 'member@example.com',
            'role' => PharmacyRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();
    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->post(route('pharmacies.invitations.store', $pharmacy), [
            'email' => 'invited@example.com',
            'role' => PharmacyRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('pharmacy invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->post(route('pharmacies.invitations.store', $pharmacy), [
            'email' => 'invited@example.com',
            'role' => PharmacyRole::Member->value,
        ]);

    $response->assertForbidden();
});

test('pharmacy invitations can be cancelled by owners', function () {
    $owner = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('pharmacies.invitations.destroy', [$pharmacy, $invitation]));

    $response->assertRedirect(route('pharmacies.edit', $pharmacy));

    $this->assertDatabaseMissing('pharmacy_invitations', [
        'id' => $invitation->id,
    ]);
});

test('pharmacy invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => 'invited@example.com',
        'role' => PharmacyRole::Member,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

    expect($invitedUser->fresh()->belongsToPharmacy($pharmacy))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('pharmacy invitations can be declined by the invited user', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('pharmacy_invitations', [
        'id' => $invitation->id,
    ]);
});

test('pharmacy invitations cannot be declined by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('pharmacy_invitations', [
        'id' => $invitation->id,
    ]);
});

test('accepted pharmacy invitations cannot be declined', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->accepted()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('pharmacy_invitations', [
        'id' => $invitation->id,
    ]);
});

test('pharmacy invitations cannot be accepted by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($uninvitedUser->fresh()->belongsToPharmacy($pharmacy))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $invitation = PharmacyInvitation::factory()->expired()->create([
        'pharmacy_id' => $pharmacy->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($invitedUser->fresh()->belongsToPharmacy($pharmacy))->toBeFalse();
});