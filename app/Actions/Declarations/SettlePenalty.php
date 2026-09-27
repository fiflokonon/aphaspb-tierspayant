<?php

namespace App\Actions\Declarations;

use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\User;
use App\Services\Declarations\PenaltyCalculator;

/**
 * Clore une pénalité (payée, annulée) ou la remettre en dû.
 *
 * N'écrit pas de révision : ses deux appelants le font après coup, comme
 * DeclarationController::store() le fait après les versements.
 */
class SettlePenalty
{
    public function __construct(protected PenaltyCalculator $penalties)
    {
        //
    }

    /**
     * Pourquoi le geste est refusé, ou null s'il est possible (spec §5.1).
     */
    public function refusal(Declaration $declaration): ?string
    {
        $declaration->loadMissing(['insurer', 'payments']);

        if (! $declaration->insurer->hasPenaltyClause()) {
            return "Cet assureur n'a pas de clause de pénalité.";
        }

        if (! $declaration->isFullyCovered()) {
            return 'Le mois doit être entièrement réglé avant de clore sa pénalité.';
        }

        if (($this->penalties->for($declaration) ?? 0) === 0) {
            return "Aucune pénalité n'a couru sur ce mois.";
        }

        return null;
    }

    public function settle(Declaration $declaration, PenaltySettlement $outcome, User $by): bool
    {
        if ($this->refusal($declaration) !== null) {
            return false;
        }

        // Un double clic ne doit pas réécrire la date du geste.
        if ($declaration->penalty_settlement === $outcome) {
            return false;
        }

        $declaration->settlePenalty($outcome, (int) $this->penalties->for($declaration), $by);

        return true;
    }

    public function reopen(Declaration $declaration): bool
    {
        if (! $declaration->isPenaltySettled()) {
            return false;
        }

        $declaration->clearPenaltySettlement();

        return true;
    }
}
