<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\PenaltyLedger;
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
 *   sans seuil, comme networkSummary(). Décision du 27/09/2026, risque nommé
 *   dans la spec : un seul assureur masqué se déduit par différence ;
 * - filtrée sur un assureur, la série totale devient ses chiffres : retenue en
 *   bloc s'il est masqué, sinon mois par mois comme sa série.
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

        return $tally->ledger(
            $authorized,
            function (?int $seriesInsurer, int $accruedPharmacies, int $declaredPharmacies) use ($insurerId, $filteredIsMasked, $belowMinimum): bool {
                if ($seriesInsurer === null && $insurerId === null) {
                    return false;
                }

                if ($seriesInsurer === null && $filteredIsMasked) {
                    return true;
                }

                return $belowMinimum($accruedPharmacies) || $belowMinimum($declaredPharmacies);
            },
            $masked,
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
