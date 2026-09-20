<?php

namespace App\Data;

/**
 * Ce qu'un assureur doit au réseau en plus, et depuis combien de temps.
 *
 * Aucune propriété n'identifie une officine : ces deux chiffres agrègent au
 * moins le seuil d'anonymat d'entre elles, parce que l'agrégateur ne reçoit que
 * des assureurs déjà autorisés par NetworkStatsService::perInsurer().
 */
readonly class InsurerPenaltyFigures
{
    public function __construct(
        /** Null faute de convention ; zéro quand la convention n'a rien produit. */
        public ?int $penalty,
        /** Le pire retard constaté, soldé ou non ; null si rien n'a été déclaré. */
        public ?int $longestDelayDays,
    ) {
        //
    }
}
