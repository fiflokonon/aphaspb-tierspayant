<?php

namespace App\Data;

/**
 * Stand-in for an insurer's figures when too few pharmacies declared.
 *
 * It deliberately holds no amount, rate or delay. It still carries the real
 * count, for the services that reason on it — but that count **never leaves**:
 * no prop, export, PDF or notification may print it. Under the threshold, the
 * exact number is itself a figure (« 1 officine déclarante », next to the
 * declaration follow-up that names who declared, tells which one). Outputs
 * say « moins de {required} officines déclarantes » instead.
 */
readonly class InsufficientData
{
    public function __construct(
        public int $declaringPharmacies,
        public int $required,
        /**
         * Retenu par la règle de partition par ville, non par son propre
         * compte : non filtré, ce chiffre moins les villes publiées rendrait
         * une part qui repose sur 1 à seuil − 1 officines (CityPartition).
         */
        public bool $cityShare = false,
    ) {
        //
    }
}
