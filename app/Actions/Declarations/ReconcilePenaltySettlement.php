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
 * peut donc pas recalculer la pénalité. Et depuis
 * InsurerManagementController::update() quand la clause change : le montant
 * clos ne correspond alors plus au calcul, sans qu'aucun mois soit réenregistré.
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
        return $this->reconcile($declaration->load(['insurer', 'payments']));
    }

    /**
     * La même levée, sur une déclaration dont `insurer` et `payments` sont
     * déjà à jour — un lot chargé d'avance, comme les mois clos d'un assureur
     * dont la clause vient de changer.
     */
    public function reconcile(Declaration $declaration): ?PenaltyReopened
    {
        if (! $declaration->isPenaltySettled()) {
            return null;
        }

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
