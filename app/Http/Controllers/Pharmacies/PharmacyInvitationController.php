<?php

namespace App\Http\Controllers\Pharmacies;

use App\Enums\PharmacyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacies\CreatePharmacyInvitationRequest;
use App\Http\Requests\Pharmacies\RespondToPharmacyInvitationRequest;
use App\Models\Pharmacy;
use App\Models\PharmacyInvitation;
use App\Notifications\Pharmacies\PharmacyInvitation as PharmacyInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

class PharmacyInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreatePharmacyInvitationRequest $request, Pharmacy $pharmacy): RedirectResponse
    {
        Gate::authorize('inviteMember', $pharmacy);

        $invitation = $pharmacy->invitations()->create([
            'email' => $request->validated('email'),
            'role' => PharmacyRole::from($request->validated('role')),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new PharmacyInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('pharmacies.edit', ['pharmacy' => $pharmacy->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Pharmacy $pharmacy, PharmacyInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->pharmacy_id === $pharmacy->id, 404);

        Gate::authorize('cancelInvitation', $pharmacy);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('pharmacies.edit', ['pharmacy' => $pharmacy->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToPharmacyInvitationRequest $request, PharmacyInvitation $invitation): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $invitation) {
            $pharmacy = $invitation->pharmacy;

            $pharmacy->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            $user->switchPharmacy($pharmacy);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToPharmacyInvitationRequest $request, PharmacyInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('dashboard');
    }
}
