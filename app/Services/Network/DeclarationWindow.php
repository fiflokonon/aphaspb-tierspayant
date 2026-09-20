<?php

namespace App\Services\Network;

use App\Data\Period;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Quelles déclarations un agrégat réseau a le droit de voir.
 *
 * Extrait de NetworkStatsService parce que plusieurs requêtes en ont besoin —
 * dont une qui part de `declaration_payments` et joint `declarations`. Deux
 * copies du filtre qui décide quelles officines entrent dans un agrégat
 * finiraient par diverger, et l'écart ne se verrait que le jour où deux écrans
 * afficheraient deux chiffres.
 *
 * Les colonnes sont qualifiées de leur table : ce même filtre se pose sur des
 * requêtes jointes, où `period_year` seul serait ambigu.
 *
 * L'assureur est un filtre de fenêtre au même titre que la ville, et non un
 * tri appliqué à la sortie : posé ici, il resserre d'un coup les neuf agrégats
 * qui passent par `NetworkStatsService::baseQuery()` — résumé compris, ce que
 * filtrer après coup ne saurait pas faire.
 */
class DeclarationWindow
{
    /**
     * Les déclarations de la période, éventuellement d'une seule ville et d'un
     * seul assureur.
     */
    public function query(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): Builder
    {
        return $this->apply(DB::table('declarations'), $from, $to, $city, $insurerId);
    }

    /**
     * Le même filtre, posé sur une requête qui joint déjà `declarations`.
     */
    public function apply(Builder $query, Period $from, Period $to, ?string $city = null, ?int $insurerId = null): Builder
    {
        return $query
            ->whereRaw(
                '(declarations.period_year * 12 + declarations.period_month) BETWEEN ? AND ?',
                [$from->toOrdinal(), $to->toOrdinal()],
            )
            ->when($city, fn (Builder $inner, string $filtered) => $inner->whereExists(
                fn (Builder $sub) => $sub->from('pharmacies')
                    ->whereColumn('pharmacies.id', 'declarations.pharmacy_id')
                    ->where('pharmacies.city', $filtered),
            ))
            ->when($insurerId, fn (Builder $inner, int $filtered) => $inner->where(
                'declarations.insurer_id',
                $filtered,
            ));
    }
}
