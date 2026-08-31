<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Une facture qui a dépassé le délai convenu avec son assureur.
 *
 * L'âge se compte depuis le dépôt, pas depuis la fin du mois déclaré : c'est
 * l'horloge de `delay_days` et de `NetworkStatsService::WITHIN_STANDARD_DELAY_SUM`,
 * et deux horloges feraient dire à l'e-mail et à l'écran réseau deux choses
 * différentes de la même facture.
 */
readonly class OverdueLine
{
    public function __construct(
        public int $declarationId,
        public string $insurerName,
        public int $periodYear,
        public int $periodMonth,
        public CarbonImmutable $invoiceDepositedOn,
        public int $ageDays,
        public int $standardDelayDays,
        public int $outstanding,
    ) {
        //
    }
}
