<?php

namespace App\Http\Controllers\Pharmacies;

use App\Actions\Pharmacies\CreatePharmacy;
use App\Enums\PharmacyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacies\DeletePharmacyRequest;
use App\Http\Requests\Pharmacies\SavePharmacyRequest;
use App\Models\Membership;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PharmacyController extends Controller
{
    /**
     * Display a listing of the user's pharmacies.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('pharmacies/Index', [
            'pharmacies' => $user->toUserPharmacies(includeCurrent: true),
        ]);
    }

    /**
     * Store a newly created pharmacy.
     */
    public function store(SavePharmacyRequest $request, CreatePharmacy $createPharmacy): RedirectResponse
    {
        $pharmacy = $createPharmacy->handle($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pharmacy created.')]);

        return to_route('pharmacies.edit', ['pharmacy' => $pharmacy->slug]);
    }

    /**
     * Show the pharmacy edit page.
     */
    public function edit(Request $request, Pharmacy $pharmacy): Response
    {
        $user = $request->user();

        return Inertia::render('pharmacies/Edit', [
            'pharmacy' => [
                'id' => $pharmacy->id,
                'name' => $pharmacy->name,
                'slug' => $pharmacy->slug,
            ],
            'members' => $pharmacy->members()->get()->map(function (User $member) {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                ];
            }),
            'invitations' => $pharmacy->invitations()
                ->whereNull('accepted_at')
                ->get()
                ->map(fn ($invitation) => [
                    'code' => $invitation->code,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at->toISOString(),
                ]),
            'permissions' => $user->toPharmacyPermissions($pharmacy),
            'availableRoles' => PharmacyRole::assignable(),
        ]);
    }

    /**
     * Update the specified pharmacy.
     */
    public function update(SavePharmacyRequest $request, Pharmacy $pharmacy): RedirectResponse
    {
        Gate::authorize('update', $pharmacy);

        $pharmacy = DB::transaction(function () use ($request, $pharmacy) {
            $pharmacy = Pharmacy::whereKey($pharmacy->id)->lockForUpdate()->firstOrFail();

            $pharmacy->update(['name' => $request->validated('name')]);

            return $pharmacy;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pharmacy updated.')]);

        return to_route('pharmacies.edit', ['pharmacy' => $pharmacy->slug]);
    }

    /**
     * Switch the user's current pharmacy.
     */
    public function switch(Request $request, Pharmacy $pharmacy): RedirectResponse
    {
        abort_unless($request->user()->belongsToPharmacy($pharmacy), 403);

        $request->user()->switchPharmacy($pharmacy);

        return back();
    }

    /**
     * Leave the specified pharmacy.
     */
    public function leave(Request $request, Pharmacy $pharmacy): RedirectResponse
    {
        Gate::authorize('leave', $pharmacy);

        $user = $request->user();

        $wasCurrent = $user->isCurrentPharmacy($pharmacy);

        $pharmacy->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($wasCurrent) {
            $user->moveToFallbackPharmacy($pharmacy);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the pharmacy ":name"', ['name' => $pharmacy->name])]);

        return to_route('pharmacies.index');
    }

    /**
     * Delete the specified pharmacy.
     */
    public function destroy(DeletePharmacyRequest $request, Pharmacy $pharmacy): RedirectResponse
    {
        $user = $request->user();
        $wasCurrent = $user->isCurrentPharmacy($pharmacy);

        DB::transaction(function () use ($user, $pharmacy) {
            User::where('current_pharmacy_id', $pharmacy->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->moveToFallbackPharmacy($pharmacy));

            $pharmacy->invitations()->delete();
            $pharmacy->memberships()->delete();
            $pharmacy->delete();
        });

        if ($wasCurrent) {
            $user->moveToFallbackPharmacy($pharmacy);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pharmacy deleted.')]);

        return to_route('pharmacies.index');
    }
}
