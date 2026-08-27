<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SavePharmacyInsurersRequest;
use App\Models\Declaration;
use App\Models\Insurer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The insurers an officine works with, editable after the onboarding.
 *
 * Untying an insurer removes it from the monthly round and nothing else: past
 * declarations are a register, not a consequence of the current selection, and
 * they keep counting in the network statistics.
 */
class PharmacyInsurersController extends Controller
{
    public function edit(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;

        return Inertia::render('pharmacy/Insurers', [
            'insurers' => Insurer::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'selected' => $pharmacy->insurers()->pluck('insurers.id')->all(),

            // So the page can warn only where untying actually loses something.
            'withDeclarations' => Declaration::query()
                ->where('pharmacy_id', $pharmacy->id)
                ->distinct()
                ->orderBy('insurer_id')
                ->pluck('insurer_id')
                ->all(),
        ]);
    }

    public function update(SavePharmacyInsurersRequest $request): RedirectResponse
    {
        $pharmacy = $request->user()->currentPharmacy;

        /** @var list<int> $ids */
        $ids = array_map('intval', $request->validated('insurers') ?? []);

        if ($other = trim((string) $request->validated('other'))) {
            $ids[] = Insurer::query()
                ->firstOrCreate(['name' => $other], ['is_active' => false])
                ->id;
        }

        $pharmacy->insurers()->sync(array_unique($ids));

        return to_route('pharmacy.insurers');
    }
}
