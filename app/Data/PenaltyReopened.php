<?php

namespace App\Data;

use App\Support\Fcfa;

/**
 * Pourquoi une clôture de pénalité vient de tomber d'elle-même.
 */
readonly class PenaltyReopened
{
    public function __construct(
        /** 'uncovered' (cas A) ou 'amountChanged' (cas B) */
        public string $reason,
        public int $previousAmount,
        public ?int $currentAmount,
    ) {
        //
    }

    public function message(string $monthLabel, string $insurerName): string
    {
        $subject = "La pénalité de {$monthLabel} ({$insurerName}) n'est plus close";

        if ($this->reason === 'uncovered') {
            return "{$subject} : le mois n'est plus entièrement réglé.";
        }

        $current = $this->currentAmount === null || $this->currentAmount === 0
            ? 'aucune pénalité'
            : Fcfa::format($this->currentAmount).' F';

        return "{$subject} : son montant a changé ({$current} au lieu de ".Fcfa::format($this->previousAmount).' F).';
    }
}
