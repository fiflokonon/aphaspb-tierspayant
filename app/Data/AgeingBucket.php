<?php

namespace App\Data;

/**
 * One age band of an officine's outstanding balance.
 *
 * Age is counted from the end of the declared month, not from an invoice date:
 * the CDC deliberately stores no invoice reference or date. The interface must
 * say so, or the figure reads as something it is not.
 */
readonly class AgeingBucket
{
    public function __construct(
        public string $label,
        public int $amount,
        public int $fromDays,
        public ?int $toDays,
    ) {
        //
    }
}
