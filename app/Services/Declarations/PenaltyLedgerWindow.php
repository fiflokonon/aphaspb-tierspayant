<?php

namespace App\Services\Declarations;

use App\Data\Period;
use App\Enums\DeclarationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Quelles déclarations un journal des pénalités doit lire.
 *
 * Pas le filtre habituel sur le mois déclaré : une facture de janvier encore
 * impayée court encore en septembre, et la vue « couru » doit la voir. On lit
 * donc, en plus des mois de la période, toute facture déposée avant sa fin qui
 * n'était pas soldée avant son début. Ce qui tombe hors période est calculé
 * puis écarté par PenaltyTally.
 *
 * Les bornes se comparent en `<` au premier jour du mois suivant et en `>=` au
 * premier jour : les dates remontent avec une heure (`2026-09-30 00:00:00`),
 * et `<= '2026-09-30'` exclurait le dernier jour en comparaison de chaînes.
 */
class PenaltyLedgerWindow
{
    public function apply(Builder $query, Period $from, Period $to): Builder
    {
        $firstDay = sprintf('%04d-%02d-01', $from->year, $from->month);
        $dayAfter = CarbonImmutable::create($to->year, $to->month, 1)->addMonth()->format('Y-m-d');

        return $query
            ->where('declarations.status', '!=', DeclarationStatus::Rejected->value)
            ->whereNotNull('declarations.invoice_deposited_on')
            ->where(fn (Builder $scope) => $scope
                ->whereRaw(
                    '(declarations.period_year * 12 + declarations.period_month) BETWEEN ? AND ?',
                    [$from->toOrdinal(), $to->toOrdinal()],
                )
                ->orWhere(fn (Builder $accruing) => $accruing
                    ->where('declarations.invoice_deposited_on', '<', $dayAfter)
                    ->where(fn (Builder $open) => $open
                        ->whereColumn('declarations.amount_received', '<', 'declarations.amount_invoiced')
                        ->orWhere('declarations.paid_on', '>=', $firstDay))));
    }
}
