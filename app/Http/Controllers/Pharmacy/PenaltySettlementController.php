<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Declarations\RecordDeclarationRevision;
use App\Actions\Declarations\SettlePenalty;
use App\Enums\PenaltySettlement;
use App\Http\Controllers\Controller;
use App\Models\Declaration;
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

        $settled = DB::transaction(function () use ($declaration, $outcome, $request): bool {
            if (! $this->settle->settle($declaration, $outcome, $request->user())) {
                return false;
            }

            $this->revisions->handle($declaration->load('payments'), $request->user());

            return true;
        });

        // Un double clic ne change rien : ne pas le célébrer comme un geste.
        Inertia::flash('toast', $settled
            ? $this->settle->settledNotice($declaration, $outcome)
            : $this->settle->alreadySettledNotice($declaration, $outcome));

        return back();
    }

    public function destroy(Request $request, Declaration $declaration): RedirectResponse
    {
        $this->ownedOrNotFound($request, $declaration);

        $reopened = DB::transaction(function () use ($declaration, $request): bool {
            if (! $this->settle->reopen($declaration)) {
                return false;
            }

            $this->revisions->handle($declaration->load('payments'), $request->user());

            return true;
        });

        Inertia::flash('toast', $reopened
            ? $this->settle->reopenedNotice($declaration)
            : $this->settle->notSettledNotice($declaration));

        return back();
    }

    protected function ownedOrNotFound(Request $request, Declaration $declaration): void
    {
        abort_unless($declaration->pharmacy_id === $request->user()->currentPharmacy?->id, 404);
    }
}
