<?php

namespace App\Policies;

use App\Enums\PharmacyPermission;
use App\Models\Pharmacy;
use App\Models\User;

class PharmacyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Pharmacy $pharmacy): bool
    {
        return $user->belongsToPharmacy($pharmacy);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Pharmacy $pharmacy): bool
    {
        return $user->hasPharmacyPermission($pharmacy, PharmacyPermission::UpdatePharmacy);
    }

    /**
     * Determine whether the user can leave the pharmacy.
     */
    public function leave(User $user, Pharmacy $pharmacy): bool
    {
        return $user->belongsToPharmacy($pharmacy)
            && ! $user->ownsPharmacy($pharmacy);
    }

    /**
     * Determine whether the user can add a member to the pharmacy.
     */
    public function addMember(User $user, Pharmacy $pharmacy): bool
    {
        return $user->hasPharmacyPermission($pharmacy, PharmacyPermission::AddMember);
    }

    /**
     * Determine whether the user can update a member's role in the pharmacy.
     */
    public function updateMember(User $user, Pharmacy $pharmacy): bool
    {
        return $user->hasPharmacyPermission($pharmacy, PharmacyPermission::UpdateMember);
    }

    /**
     * Determine whether the user can remove a member from the pharmacy.
     */
    public function removeMember(User $user, Pharmacy $pharmacy): bool
    {
        return $user->hasPharmacyPermission($pharmacy, PharmacyPermission::RemoveMember);
    }

    /**
     * Determine whether the user can invite members to the pharmacy.
     */
    public function inviteMember(User $user, Pharmacy $pharmacy): bool
    {
        return $user->hasPharmacyPermission($pharmacy, PharmacyPermission::CreateInvitation);
    }

    /**
     * Determine whether the user can cancel invitations.
     */
    public function cancelInvitation(User $user, Pharmacy $pharmacy): bool
    {
        return $user->hasPharmacyPermission($pharmacy, PharmacyPermission::CancelInvitation);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Pharmacy $pharmacy): bool
    {
        return $user->hasPharmacyPermission($pharmacy, PharmacyPermission::DeletePharmacy);
    }
}
