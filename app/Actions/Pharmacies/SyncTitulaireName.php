<?php

namespace App\Actions\Pharmacies;

use App\Models\Pharmacy;
use App\Models\User;

/**
 * Carry a titulaire's Joomla name onto the officines they hold.
 *
 * The name is captured once, when the officine is created, and Joomla stays
 * free to change it afterwards — a correction, a marriage, a spelling fixed a
 * year later. Nothing else could repair it: the onboarding form is the only
 * writer of owner_name, and it closes as soon as the profile is complete.
 *
 * The same pass repairs officines created while the field was still typed by
 * hand, the first time their titulaire signs in.
 *
 * Only officines where this user is the titulaire are touched. A pharmacist
 * who merely works at an officine does not rename its holder by logging in.
 */
class SyncTitulaireName
{
    public function handle(User $user): void
    {
        $user->ownedPharmacies()
            ->get()
            ->each(function (Pharmacy $pharmacy) use ($user): void {
                if ($pharmacy->owner_name !== $user->name) {
                    $pharmacy->update(['owner_name' => $user->name]);
                }
            });
    }
}
