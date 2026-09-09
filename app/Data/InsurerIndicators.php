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
        /**
         * Share of the invoiced amount actually paid within this insurer's own
         * standard delay, in percent.
         *
         * Money-based, where withinThresholdShare counts declarations: a month
         * settled in two transfers contributes only the part that arrived in
         * time. Measured against what was invoiced, so what an insurer never
         * paid at all weighs against it exactly like what it paid late.
         */
        public ?float $recoveredWithinDelayShare,
        /** How many transfers this insurer's settled months took, in total. */
        public int $instalments,
        /** Transfers per settled month: 1.0 means it always pays in one go. */
        public ?float $instalmentsPerDeclaration,
        /** Share of settled months that took more than one transfer, in percent. */
        public ?float $multiInstalmentShare,
        /** Days to the **first** transfer, where averageDelayDays counts to the last. */
        public ?float $averageFirstInstalmentDelayDays,
    ) {
        //
    }
}
