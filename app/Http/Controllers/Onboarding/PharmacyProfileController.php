<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Pharmacies\CreatePharmacy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SavePharmacyProfileRequest;
use App\Models\Pharmacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 1 of the officine's own onboarding.
 *
 * Joomla owns account creation; what it cannot know is the officine itself —
 * its name, its ONPB licence, its city, its titulaire. A user arriving from a
 * Joomla ticket has no Pharmacy at all, so this step creates it.
 */
class PharmacyProfileController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->needsOnboarding()) {
            return to_route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]);
        }

        return Inertia::render('onboarding/Profile', [
            'pharmacy' => $user->currentPharmacy?->only(['name', 'onpb_license', 'city', 'owner_name']),
            'cities' => Pharmacy::query()
                ->whereNotNull('city')
                ->distinct()
                ->orderBy('city')
                ->pluck('city')
                ->all(),
        ]);
    }

    public function store(SavePharmacyProfileRequest $request, CreatePharmacy $createPharmacy): RedirectResponse
    {
        $user = $request->user();

        // Fall back to any officine the user already belongs to: losing the
        // current id must never produce a second officine.
        $pharmacy = $user->currentPharmacy ?? $user->fallbackPharmacy();

        if ($pharmacy === null) {
            $createPharmacy->handle($user, $request->validated());
        } else {
            $pharmacy->update($request->validated());
        }

        return to_route('onboarding.insurers');
    }
}
