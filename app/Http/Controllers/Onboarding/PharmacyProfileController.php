<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Pharmacies\CreatePharmacy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SavePharmacyProfileRequest;
use App\Models\City;
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
            'pharmacy' => $user->currentPharmacy?->only(['name', 'onpb_license', 'city']),
            'cities' => $this->citySuggestions(),
        ]);
    }

    /**
     * The communes offered under the city field.
     *
     * The seeded communes plus whatever officines already registered: a
     * locality outside the list, or a spelling the association has settled on,
     * has to keep being suggested rather than vanish the day reference data
     * arrives. The field itself stays free text — the list proposes.
     *
     * @return list<string>
     */
    protected function citySuggestions(): array
    {
        $suggestions = array_unique([
            ...City::names(),
            ...Pharmacy::filterableCities(),
        ]);

        // sort() reindexes, so what comes back is already a list.
        sort($suggestions, SORT_NATURAL | SORT_FLAG_CASE);

        return $suggestions;
    }

    public function store(SavePharmacyProfileRequest $request, CreatePharmacy $createPharmacy): RedirectResponse
    {
        $user = $request->user();

        // Fall back to any officine the user already belongs to: losing the
        // current id must never produce a second officine.
        $pharmacy = $user->currentPharmacy ?? $user->fallbackPharmacy();

        // The titulaire is the Joomla account holder, never something the
        // browser gets to say: the association attributes an officine's
        // declarations to the person it authenticated. Locking the field in
        // the form alone would leave the value a forged post away.
        $attributes = [...$request->validated(), 'owner_name' => $user->name];

        if ($pharmacy === null) {
            $createPharmacy->handle($user, $attributes);
        } else {
            $pharmacy->update($attributes);
        }

        return to_route('onboarding.insurers');
    }
}
