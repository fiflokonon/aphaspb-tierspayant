<?php

namespace App\Services\Network;

use App\Data\PenaltyLedgerMonth;
use App\Data\Period;
use App\Services\Settings\SettingsRepository;

/**
 * Le journal des pénalités du réseau, en lignes de tableur.
 *
 * Ne nomme aucune officine. Ne décide d'aucune rétention : les mois retenus
 * arrivent déjà vidés de NetworkPenaltyJournal, et gardent leur ligne avec
 * l'explication — une ligne absente se lirait « rien couru ».
 */
class NetworkPenaltyLedgerRows
{
    public const COLUMNS = ['mois', 'assureur', 'penalite_courue', 'cumul_couru', 'penalite_mois_declare', 'dont_payee', 'dont_annulee', 'reste_due', 'mois_en_cours', 'retenu'];

    public function __construct(
        protected NetworkPenaltyJournal $journal,
        protected SettingsRepository $settings,
    ) {
        //
    }

    /**
     * @return iterable<int, list<string|int|null>>
     */
    public function rows(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): iterable
    {
        $ledger = $this->journal->for($from, $to, $city, $insurerId);
        $reason = sprintf('moins de %d officines', $this->settings->anonymityMinPharmacies());

        // Rien sous convention dans le réseau : aucune ligne plutôt que des zéros.
        if ($ledger->insurers === [] && $ledger->maskedInsurers === 0) {
            return;
        }

        foreach ($ledger->total->months as $index => $total) {
            if ($total->future) {
                continue;
            }

            foreach ($ledger->insurers as $series) {
                yield $this->row($series->months[$index], $series->name, $reason);
            }

            // Filtrée, la ligne « Tous assureurs » est l'assureur choisi : on la
            // garde quand même, c'est la seule qui porte ses chiffres s'il est
            // masqué (et elle arrive alors retenue).
            if ($insurerId === null || $ledger->insurers === []) {
                yield $this->row($total, $ledger->total->name, $reason);
            }
        }
    }

    /**
     * @return list<string|int|null>
     */
    protected function row(PenaltyLedgerMonth $month, string $name, string $reason): array
    {
        return [
            $month->month,
            $name,
            $month->accrued,
            $month->accruedCumulative,
            $month->declared,
            $month->accruedPaid,
            $month->accruedWaived,
            $month->accruedDue,
            $month->current ? 'oui' : 'non',
            match (true) {
                $month->withheld => $reason,
                // Le cumul vide d'un mois publié vient d'un mois retenu plus tôt.
                $month->accruedCumulative === null => 'cumul interrompu par un mois retenu',
                default => null,
            },
        ];
    }
}
