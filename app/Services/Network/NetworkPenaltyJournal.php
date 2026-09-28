<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\PenaltyLedger;
use App\Data\PenaltySplitPharmacies;
use App\Data\Period;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Le journal des pénalités tel que le réseau peut le publier.
 *
 * Seul point de décision du seuil pour ce journal — écrans et exports passent
 * tous par ici :
 *
 * - une série par assureur **autorisé** par perInsurer() sur la période, puis
 *   réévaluée **mois par mois** (règle « seuil à chaque granularité ») ;
 * - la série totale sans filtre couvre **tous** les assureurs sous convention,
 *   masqués compris, comme networkSummary() : un seul assureur masqué se
 *   déduit par différence, risque accepté le 27/09/2026. Mais un mois est
 *   retenu quand sa **part cachée** (les mois retenus des séries publiées)
 *   ne repose que sur quelques officines — décision du même jour, prise
 *   après revue —, et quand le total lui-même n'y repose que sur 1 à
 *   seuil − 1 officines, avec ou sans filtre ville (28/09/2026 : un réseau
 *   d'une seule déclarante, que le suivi des déclarations nomme, rendait
 *   sa pénalité exacte) ;
 * - filtrée sur un assureur, la série totale devient ses chiffres : retenue en
 *   bloc s'il est masqué, sinon mois par mois comme sa série ;
 * - le découpage par statut d'un mois publié (payée, annulée, due) est une
 *   granularité de plus : ses trois parts sont retenues ensemble dès qu'une
 *   part non vide repose sur 1 à seuil − 1 officines, séries et total
 *   compris (règle de partition, 27/09/2026).
 */
class NetworkPenaltyJournal
{
    public function __construct(
        protected NetworkStatsService $stats,
        protected NetworkPenaltyLedger $ledger,
        protected SettingsRepository $settings,
    ) {
        //
    }

    public function for(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): PenaltyLedger
    {
        $minimum = $this->settings->anonymityMinPharmacies();
        $indicators = $this->stats->perInsurer($from, $to, $city, $insurerId);

        $authorized = [];

        foreach ($indicators as $id => $entry) {
            if ($entry instanceof InsufficientData) {
                continue;
            }

            if ($entry->penaltyTriggerDays !== null && $entry->penaltyRatePercent !== null) {
                $authorized[$id] = $entry->insurerName;
            }
        }

        asort($authorized);

        $filteredIsMasked = $insurerId !== null && ($indicators[$insurerId] ?? null) instanceof InsufficientData;
        $belowMinimum = fn (int $count): bool => $count > 0 && $count < $minimum;

        $tally = $this->ledger->tally($from, $to, $city, $insurerId);

        // « Masqués » au sens de ce journal : sous convention, entrés dans le
        // total, sans série publiée. Ni un assureur sans convention (il ne
        // pèse rien ici), ni seulement ceux que perInsurer() retient : un
        // assureur sans déclaration dans la période mais dont une facture
        // ancienne court encore nourrit le total sans y figurer.
        $masked = count(array_diff($tally->insurerIds(), array_keys($authorized)));

        // Règle de partition par ville (28/09/2026) : non filtré, un mois
        // moins le même mois publié ville par ville rendrait les villes
        // retenues et les officines sans ville. Séries et total, couru et
        // déclaré, puis découpage.
        $partitionWithholds = fn (?int $seriesInsurer, string $month): bool => $city === null && (
            CityPartition::withholds($tally->cityCounts($seriesInsurer, $month)['accrued'], $minimum)
            || CityPartition::withholds($tally->cityCounts($seriesInsurer, $month)['declared'], $minimum)
        );

        return $tally->ledger(
            $authorized,
            function (?int $seriesInsurer, int $accruedPharmacies, int $declaredPharmacies, int $hiddenAccrued, int $hiddenDeclared, string $month = '') use ($insurerId, $filteredIsMasked, $belowMinimum, $partitionWithholds): bool {
                if ($partitionWithholds($seriesInsurer, $month)) {
                    return true;
                }

                if ($seriesInsurer === null && $insurerId === null) {
                    // Total moins séries visibles = part cachée : un mois
                    // retenu d'un assureur publié, s'il est seul caché, se
                    // lirait par soustraction (décision du 27/09/2026).
                    if ($belowMinimum($hiddenAccrued) || $belowMinimum($hiddenDeclared)) {
                        return true;
                    }

                    // Le total lui-même, avec ou sans ville : reposant sur 1 à
                    // seuil − 1 officines, il serait leur pénalité exacte, et
                    // le suivi des déclarations dit lesquelles ont déclaré
                    // (28/09/2026). Les assureurs masqués restent comptés
                    // dedans : risque « par différence » accepté le 27/09/2026.
                    return $belowMinimum($accruedPharmacies) || $belowMinimum($declaredPharmacies);
                }

                if ($seriesInsurer === null && $filteredIsMasked) {
                    return true;
                }

                return $belowMinimum($accruedPharmacies) || $belowMinimum($declaredPharmacies);
            },
            $masked,
            // Règle de partition : payée, annulée et due tombent ensemble dès
            // qu'une part non vide repose sur trop peu d'officines. Pour le
            // total, aussi quand les parts cachées des séries publiées le
            // feraient : total − séries visibles les rendrait.
            fn (?int $seriesInsurer, PenaltySplitPharmacies $own, PenaltySplitPharmacies $hidden, string $month = ''): bool => $own->restsOnFewerThan($minimum)
                || $hidden->restsOnFewerThan($minimum)
                || ($city === null && CityPartition::withholdsSplit($tally->cityCounts($seriesInsurer, $month)['split'], $minimum)),
        );
    }

    /**
     * Les assureurs sous convention qui ont au moins une déclaration, par nom.
     *
     * Pas restreints aux autorisés : la liste resterait sinon muette sur un
     * assureur masqué, que le filtre doit pouvoir nommer pour dire « retenu ».
     *
     * @return Collection<int, stdClass>
     */
    public function clauseInsurers(): Collection
    {
        return DB::table('insurers')
            ->whereNotNull('penalty_trigger_days')
            ->whereNotNull('penalty_rate_bp')
            ->whereIn('id', DB::table('declarations')->distinct()->select('insurer_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
