<?php

namespace App\Data;

/**
 * One insurer's aggregated amounts across the network.
 *
 * Like InsurerIndicators, every figure here is a sum over at least the
 * anonymity threshold of officines. No property may identify one of them.
 */
readonly class InsurerAmounts
{
    public function __construct(
        public string $insurerName,
        public int $declaringPharmacies,
        public int $invoiced,
        public int $received,
        public int $outstanding,
        public ?float $recoveryRate,
    ) {
        //
    }
}
