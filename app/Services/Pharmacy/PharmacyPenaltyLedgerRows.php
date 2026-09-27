<?php

namespace App\Services\Pharmacy;

use App\Data\PenaltyLedgerMonth;
use App\Data\Period;
use App\Models\Pharmacy;

/**
 * Le journal des pénalités d'une officine, en lignes de tableur.
 *
 * Classe sœur de NetworkPenaltyLedgerRows, jamais fusionnée avec elle : celle-ci
 * nomme l'officine et ne retient rien (règle exports.md).
 *
 * Une ligne par (mois, assureur), puis la ligne « Tous assureurs » du mois —
 * sauf filtre sur un assureur, où elle répéterait la précédente. Les mois
 * futurs de la période n'ont pas de ligne : il n'y a rien à y écrire.
 */
class PharmacyPenaltyLedgerRows
{
    public const COLUMNS = ['mois', 'assureur', 'penalite_courue', 'cumul_couru', 'penalite_mois_declare', 'dont_payee', 'dont_annulee', 'reste_due', 'mois_en_cours'];

    public function __construct(protected PharmacyPenaltyLedger $ledger)
    {
        //
    }

    /**
     * @return iterable<int, list<string|int|null>>
     */
    public function rows(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId = null): iterable
    {
        $ledger = $this->ledger->for($pharmacy, $from, $to, $insurerId);

        // Sans assureur sous convention, une ligne « Tous assureurs » à zéro se
        // lirait « convention respectée » : le fichier reste vide.
        if ($ledger->insurers === []) {
            return;
        }

        foreach ($ledger->total->months as $index => $total) {
            if ($total->future) {
                continue;
            }

            foreach ($ledger->insurers as $series) {
                yield $this->row($series->months[$index], $series->name);
            }

            if ($insurerId === null) {
                yield $this->row($total, $ledger->total->name);
            }
        }
    }

    /**
     * @return list<string|int|null>
     */
    protected function row(PenaltyLedgerMonth $month, string $name): array
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
        ];
    }
}
