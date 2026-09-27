<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Declarations\RecordDeclarationRevision;
use App\Actions\Declarations\SettlePenalty;
use App\Enums\PenaltySettlement;
use App\Http\Controllers\Controller;
use App\Models\Declaration;
use App\Support\MonthLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Clore une pénalité depuis l'écran assureur, ou la remettre en dû.
 *
 * La déclaration d'une autre officine rend 404 et non 403 : répondre
 * « interdit » confirmerait qu'elle existe.
 */
class PenaltySettlementController extends Controller
{
    public function __construct(
        protected SettlePenalty $settle,
        protected RecordDeclarationRevision $revisions,
    ) {
        //
    }

    public function store(Request $request, Declaration $declaration): RedirectResponse
    {
        $this->ownedOrNotFound($request, $declaration);

        $outcome = PenaltySettlement::from($request->validate([
            'outcome' => ['required', Rule::enum(PenaltySettlement::class)],
        ])['outcome']);

        $refusal = $this->settle->refusal($declaration);

        if ($refusal !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusal]);

            return back();
        }

        DB::transaction(function () use ($declaration, $outcome, $request) {
            if ($this->settle->settle($declaration, $outcome, $request->user())) {
                $this->revisions->handle($declaration->load('payments'), $request->user());
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Pénalité de %s marquée %s.',
            MonthLabel::short($declaration->period_month, $declaration->period_year),
            mb_strtolower($outcome->label()),
        )]);

        return back();
    }

    public function destroy(Request $request, Declaration $declaration): RedirectResponse
    {
        $this->ownedOrNotFound($request, $declaration);

        DB::transaction(function () use ($declaration, $request) {
            if ($this->settle->reopen($declaration)) {
                $this->revisions->handle($declaration->load('payments'), $request->user());
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Pénalité de %s remise en dû.',
            MonthLabel::short($declaration->period_month, $declaration->period_year),
        )]);

        return back();
    }

    protected function ownedOrNotFound(Request $request, Declaration $declaration): void
    {
        abort_unless($declaration->pharmacy_id === $request->user()->currentPharmacy?->id, 404);
    }
}
