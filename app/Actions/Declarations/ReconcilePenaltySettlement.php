<?php

namespace App\Actions\Declarations;

use App\Data\PenaltyReopened;
use App\Models\Declaration;
use App\Services\Declarations\PenaltyCalculator;

/**
 * Lever d'elle-même une clôture que le mois ne justifie plus (spec §5.3).
 *
 * Appelée à la fin de RecordPaymentInstalments, par où passe tout
 * enregistrement : le hook `saving` n'a ni les versements ni l'assureur, et ne
 * peut donc pas recalculer la pénalité.
 */
class ReconcilePenaltySettlement
{
    public function __construct(protected PenaltyCalculator $penalties)
    {
        //
    }

    public function handle(Declaration $declaration): ?PenaltyReopened
    {
        if (! $declaration->isPenaltySettled()) {
            return null;
        }

        // Relus, jamais pris en mémoire : les versements viennent d'être réécrits.
        $declaration->load(['insurer', 'payments']);
        $previous = (int) $declaration->penalty_settled_amount;

        if (! $declaration->isFullyCovered()) {
            $declaration->clearPenaltySettlement();

            return new PenaltyReopened('uncovered', $previous, null);
        }

        $current = $this->penalties->for($declaration);

        if (($current ?? 0) !== $previous) {
            $declaration->clearPenaltySettlement();

            return new PenaltyReopened('amountChanged', $previous, $current);
        }

        return null;
    }
}
