<?php

namespace App\Data;

/**
 * One insurer's aggregated performance over a period.
 *
 * Every figure here is a sum or an average over at least the anonymity
 * threshold of pharmacies. No property may ever identify one of them.
 */
readonly class InsurerIndicators
{
    public function __construct(
        public string $insurerName,
        public int $declaringPharmacies,
        public int $declarations,
        public ?float $averageDelayDays,
        /** Weighted by the amounts actually received — what screen 3c states. */
        public ?float $weightedDelayDays,
        /** The delay the APhaSPB records for this insurer, in days. */
        public int $standardDelayDays,
        public ?float $withinThresholdShare,
        public ?float $rejectionRate,
        public ?float $unpaidRate,
        public int $amountInvoiced,
        public int $amountReceived,
        public int $amountOutstanding,
        public ?float $recoveryRate,
    ) {
        //
    }
}
