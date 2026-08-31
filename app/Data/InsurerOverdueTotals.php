<?php

namespace App\Data;

/**
 * Ce qu'un assureur doit au réseau, tous retards confondus.
 *
 * Aucune propriété ne peut identifier une officine : cet objet ne franchit le
 * seuil d'anonymat que parce qu'il n'agrège rien de nominatif.
 */
readonly class InsurerOverdueTotals
{
    public function __construct(
        public int $insurerId,
        public string $insurerName,
        public int $standardDelayDays,
        public int $declarations,
        public int $pharmacies,
        public int $outstanding,
    ) {
        //
    }
}
