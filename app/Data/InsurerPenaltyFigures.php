<?php

namespace App\Data;

/**
 * Ce qu'un assureur doit au réseau en plus, et depuis combien de temps.
 *
 * Aucune propriété n'identifie une officine : ces chiffres agrègent au moins
 * le seuil d'anonymat d'entre elles, parce que l'agrégateur ne reçoit que des
 * assureurs déjà autorisés par NetworkStatsService::perInsurer().
 */
readonly class InsurerPenaltyFigures
{
    public function __construct(
        /** Ce qui reste à réclamer, close déduite ; null faute de convention. */
        public ?int $penalty,
        /** Le pire retard constaté, soldé ou non ; null si rien n'a été déclaré. */
        public ?int $longestDelayDays,
        /** Payée par l'assureur ; même règle null / zéro que penalty. */
        public ?int $recovered = null,
        /** Abandonnée par l'officine ; même règle null / zéro que penalty. */
        public ?int $waived = null,
    ) {
        //
    }
}
