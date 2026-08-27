<?php

namespace App\Http\Controllers\Pharmacies;

use App\Enums\PharmacyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacies\UpdatePharmacyMemberRequest;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PharmacyMemberController extends Controller
{
    /**
     * Update the specified pharmacy member's role.
     */
    public function update(UpdatePharmacyMemberRequest $request, Pharmacy $pharmacy, User $user): RedirectResponse
    {
        Gate::authorize('updateMember', $pharmacy);

        $newRole = PharmacyRole::from($request->validated('role'));

        $pharmacy->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role' => $newRole]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('pharmacies.edit', ['pharmacy' => $pharmacy->slug]);
    }

    /**
     * Remove the specified pharmacy member.
     */
    public function destroy(Pharmacy $pharmacy, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $pharmacy);

        abort_if($pharmacy->owner()?->is($user), 403, __('The pharmacy owner cannot be removed.'));

        $pharmacy->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentPharmacy($pharmacy)) {
            $user->switchPharmacy($user->personalPharmacy());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('pharmacies.edit', ['pharmacy' => $pharmacy->slug]);
    }
}
