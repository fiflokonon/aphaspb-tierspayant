<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SavePharmacyInsurersRequest;
use App\Models\Insurer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 2 of the onboarding: which insurers this officine works with.
 *
 * Asked once, then editable from the settings. The free-text entry creates an
 * inactive insurer: a name typed by one officine must be reviewed by the
 * APhaSPB before it starts appearing in network statistics.
 */
class PharmacyInsurersController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $pharmacy = $user->currentPharmacy;

        if ($pharmacy === null || ! $pharmacy->hasCompleteProfile()) {
            return to_route('onboarding.profile');
        }

        return Inertia::render('onboarding/Insurers', [
            'insurers' => Insurer::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'selected' => $pharmacy->insurers()->pluck('insurers.id')->all(),
        ]);
    }

    public function store(SavePharmacyInsurersRequest $request): RedirectResponse
    {
        $pharmacy = $request->user()->currentPharmacy;

        abort_if($pharmacy === null, 404);

        /** @var list<int> $ids */
        $ids = array_map('intval', $request->validated('insurers') ?? []);

        if ($other = trim((string) $request->validated('other'))) {
            $ids[] = Insurer::query()
                ->firstOrCreate(['name' => $other], ['is_active' => false])
                ->id;
        }

        $pharmacy->insurers()->sync(array_unique($ids));

        return to_route('dashboard', ['current_pharmacy' => $pharmacy->slug]);
    }
}
