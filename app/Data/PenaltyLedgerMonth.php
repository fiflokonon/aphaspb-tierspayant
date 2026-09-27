<?php

namespace App\Data;

/**
 * Un mois du journal des pénalités, pour un assureur ou pour le total.
 *
 * Null ne dit jamais zéro : il dit « pas encore » (mois futur) ou « retenu »
 * (sous le seuil d'anonymat), et `future` / `withheld` disent lequel.
 */
readonly class PenaltyLedgerMonth
{
    public function __construct(
        /** `AAAA-MM` */
        public string $month,
        /** « Août 26 » */
        public string $label,
        public bool $current,
        public bool $future,
        /** Ce qui a couru pendant ce mois calendaire. */
        public ?int $accrued,
        /** Le couru cumulé depuis le début de la période. */
        public ?int $accruedCumulative,
        /** La pénalité, à ce jour, des factures de ce mois déclaré. */
        public ?int $declared,
        public bool $withheld = false,
    ) {
        //
    }

    /**
     * @return array{month: string, label: string, current: bool, future: bool, accrued: int|null, accruedCumulative: int|null, declared: int|null, withheld: bool}
     */
    public function toArray(): array
    {
        return [
            'month' => $this->month,
            'label' => $this->label,
            'current' => $this->current,
            'future' => $this->future,
            'accrued' => $this->accrued,
            'accruedCumulative' => $this->accruedCumulative,
            'declared' => $this->declared,
            'withheld' => $this->withheld,
        ];
    }
}
