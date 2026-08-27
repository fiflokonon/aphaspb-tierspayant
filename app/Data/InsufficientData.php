<?php

namespace App\Data;

/**
 * Stand-in for an insurer's figures when too few pharmacies declared.
 *
 * Carries the real count so the interface can explain the state — « 3 officines
 * déclarantes, les montants s'agrègent à partir de 5 » — rather than showing an
 * error. It deliberately holds no amount, rate or delay.
 */
readonly class InsufficientData
{
    public function __construct(
        public int $declaringPharmacies,
        public int $required,
    ) {
        //
    }
}
