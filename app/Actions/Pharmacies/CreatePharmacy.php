<?php

namespace App\Actions\Pharmacies;

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePharmacy
{
    /**
     * Create a new pharmacy and add the user as owner.
     */
    public function handle(User $user, string $name): Pharmacy
    {
        return DB::transaction(function () use ($user, $name) {
            $pharmacy = Pharmacy::create([
                'name' => $name,
            ]);

            $membership = $pharmacy->memberships()->create([
                'user_id' => $user->id,
                'role' => PharmacyRole::Owner,
            ]);

            $user->switchPharmacy($pharmacy);

            return $pharmacy;
        });
    }
}
