<?php

namespace App\Actions\Pharmacies;

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePharmacy
{
    /**
     * Create a new pharmacy, add the user as owner, and switch them onto it.
     *
     * @param  array<string, mixed>  $attributes  at least a name; the onboarding
     *                                            also passes city and owner_name
     */
    public function handle(User $user, array $attributes): Pharmacy
    {
        return DB::transaction(function () use ($user, $attributes) {
            $pharmacy = Pharmacy::create($attributes);

            $pharmacy->memberships()->create([
                'user_id' => $user->id,
                'role' => PharmacyRole::Owner,
            ]);

            $user->switchPharmacy($pharmacy);

            return $pharmacy;
        });
    }
}
