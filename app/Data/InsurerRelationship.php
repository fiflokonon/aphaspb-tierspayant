<?php

namespace App\Data;

/**
 * Ce qu'une officine a vécu avec un assureur, sur une période.
 *
 * Le pendant nominatif d'InsurerIndicators : celui-là agrège le réseau sous
 * seuil d'anonymat, celui-ci ne lit qu'une officine et ne cache rien — c'est
 * son propre dossier qui lui revient.
 */
readonly class InsurerRelationship
{
    public function __construct(
        public int $insurerId,
        public string $insurerName,
        /** Le délai de remboursement convenu, en jours. */
        public int $standardDelayDays,
        /** Le jour où la pénalité mord, ou null faute de clause. */
        public ?int $penaltyTriggerDays,
        /** Le taux par tranche de 30 jours, en pourcent, ou null. */
        public ?float $penaltyRatePercent,
        public int $declarations,
        public int $invoiced,
        public int $received,
        public int $outstanding,
        public ?float $recoveryRate,
        /** Pondéré par les montants reçus, comme partout ailleurs. */
        public ?float $weightedDelayDays,
        /**
         * Le pire retard constaté, soldé ou non.
         *
         * Voir LongestDelay : un mois réglé avec 40 jours de délai et une
         * facture ouverte depuis 200 jours donnent 200.
         */
        public ?int $longestDelayDays,
        /**
         * La pénalité réclamable sur la période, mois soldés tardivement
         * compris — là où le bandeau du tableau de bord ne compte que les
         * factures encore en retard.
         */
        public ?int $penalty,
    ) {
        //
    }
}
