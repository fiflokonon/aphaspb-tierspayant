<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SavePharmacyInsurersRequest;
use App\Models\Declaration;
use App\Models\Insurer;
use Illuminate\Database\Eloquent\Builder;
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
        $linked = $pharmacy->insurers()->pluck('insurers.id');

        // One aggregate rather than a count per row: seven insurers today, but
        // the admin adds them freely and an N+1 here would be free to write.
        $declarations = Declaration::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->selectRaw('insurer_id, COUNT(*) as total')
            ->groupBy('insurer_id')
            ->pluck('total', 'insurer_id');

        return Inertia::render('pharmacy/Insurers', [
            // Retired insurers this officine still works with belong here too.
            // Filtering on active alone made them invisible yet counted: the
            // officine could neither see them nor untie them, while the monthly
            // round kept offering them.
            'insurers' => Insurer::query()
                ->where(fn (Builder $query) => $query->active()->orWhereIn('id', $linked))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (Insurer $insurer): array => [
                    'id' => $insurer->id,
                    'name' => $insurer->name,
                    'isActive' => $insurer->is_active,
                    'declarations' => (int) ($declarations[$insurer->id] ?? 0),
                ])
                ->all(),
            'selected' => $linked->all(),
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vos assureurs sont enregistrés.')]);

        return to_route('pharmacy.insurers');
    }
}
