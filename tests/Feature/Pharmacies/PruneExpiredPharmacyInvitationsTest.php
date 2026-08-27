<?php

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\PharmacyInvitation;
use App\Models\User;

test('expired invitations are deleted by the scheduled cleanup', function () {
    $this->travelTo(now()->startOfDay());

    $owner = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);

    $expiredInvitation = PharmacyInvitation::factory()->expired()->create([
        'pharmacy_id' => $pharmacy->id,
        'invited_by' => $owner->id,
    ]);

    $unexpiredInvitation = PharmacyInvitation::factory()->expiresIn(1)->create([
        'pharmacy_id' => $pharmacy->id,
        'invited_by' => $owner->id,
    ]);

    $invitationWithoutExpiration = PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'invited_by' => $owner->id,
    ]);

    $this->artisan('schedule:run')->assertSuccessful();

    $this->assertDatabaseMissing('pharmacy_invitations', [
        'id' => $expiredInvitation->id,
    ]);

    $this->assertDatabaseHas('pharmacy_invitations', [
        'id' => $unexpiredInvitation->id,
    ]);

    $this->assertDatabaseHas('pharmacy_invitations', [
        'id' => $invitationWithoutExpiration->id,
    ]);
});