<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Declarations\RecordNilDeclarations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\SaveNilDeclarationRequest;
use App\Models\NilDeclaration;
use App\Support\MonthLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * « Pas de facture ce mois pour cet assureur ».
 *
 * Without it a month with nothing to invoice could never be answered, and
 * stayed « non déclaré » on the dashboard and in the network's follow-up.
 */
class NilDeclarationController extends Controller
{
    public function store(SaveNilDeclarationRequest $request, RecordNilDeclarations $record): RedirectResponse
    {
        $year = $request->integer('period_year');
        $month = $request->integer('period_month');
        $insurerId = $request->integer('insurer_id');

        $count = $record->handle(
            $request->user()->currentPharmacy,
            $year,
            $month,
            $request->user(),
            $insurerId === 0 ? null : $insurerId,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%s : %s sans facture.',
                MonthLabel::short($month, $year),
                $count === 1 ? '1 assureur' : $count.' assureurs',
            ),
        ]);

        // From the round, carry on with the next insurer of the same month.
        if ($insurerId !== 0) {
            return to_route('pharmacy.declare', ['year' => $year, 'month' => $month]);
        }

        return back();
    }

    /**
     * Withdraw the answer: the insurer goes back to « à déclarer ».
     */
    public function destroy(Request $request, NilDeclaration $nilDeclaration): RedirectResponse
    {
        abort_unless($nilDeclaration->pharmacy_id === $request->user()->currentPharmacy?->id, 404);

        $nilDeclaration->delete();

        return to_route('pharmacy.declare', [
            'year' => $nilDeclaration->period_year,
            'month' => $nilDeclaration->period_month,
            'insurer' => $nilDeclaration->insurer_id,
        ]);
    }
}
