<?php

namespace App\Services\Network;

use App\Enums\EvaluationCriterion;
use App\Models\EvaluationCampaign;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Ce que le réseau pense de chaque assureur, campagne par campagne.
 *
 * Le seul lecteur des évaluations hors de l'officine qui les a écrites, et
 * le seul point où se décide ce qui en sort :
 *
 * - rien tant que la campagne est ouverte : une moyenne qui bouge en direct
 *   rend, par différence, les notes de l'officine qui vient de répondre ;
 * - par assureur, une moyenne n'est publiée qu'au-dessus du seuil d'anonymat
 *   des statistiques réseau, et sous le seuil le nombre exact d'évaluations
 *   ne sort pas non plus (même règle que `InsufficientData`) ;
 * - aucune moyenne tous assureurs confondus : moins les lignes publiées,
 *   elle rendrait celles des assureurs retenus.
 *
 * Lecture en query builder, en une requête : un agrégat n'est pas une
 * évaluation, et aucune ligne nominative n'est chargée.
 */
class InsurerEvaluationResults
{
    /**
     * Une moyenne par colonne d'EvaluationCriterion, alias = nom de colonne.
     *
     * Écrite en toutes lettres plutôt que bâtie sur l'enum : du SQL brut ne se
     * compose pas par interpolation. Un critère oublié ici fait échouer la
     * lecture de `$row->{$column}` plus bas, donc les tests de résultats.
     */
    protected const CRITERION_AVERAGES = 'AVG(insurer_evaluations.communication) as communication, '
        .'AVG(insurer_evaluations.product_lists) as product_lists, '
        .'AVG(insurer_evaluations.online_platform) as online_platform, '
        .'AVG(insurer_evaluations.medical_advisers) as medical_advisers, '
        .'AVG(insurer_evaluations.settlement_notice) as settlement_notice, '
        .'AVG(insurer_evaluations.payment_delay) as payment_delay, '
        .'AVG(insurer_evaluations.rejection_reasons) as rejection_reasons, '
        .'AVG(insurer_evaluations.claims_handling) as claims_handling';

    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    /**
     * Les moyennes par assureur, ou null tant que la campagne n'est pas close.
     *
     * @return list<array{insurerId: int, insurerName: string, withheld: bool, evaluations: int|null, total: float|null, criteria: array<string, float>|null}>|null
     */
    public function for(EvaluationCampaign $campaign): ?array
    {
        if (! $campaign->isClosed()) {
            return null;
        }

        $minimum = $this->settings->anonymityMinPharmacies();

        $rows = DB::table('insurer_evaluations')
            ->join('insurers', 'insurers.id', '=', 'insurer_evaluations.insurer_id')
            ->where('insurer_evaluations.evaluation_campaign_id', $campaign->id)
            ->select('insurers.id as insurer_id', 'insurers.name as insurer_name')
            ->selectRaw('COUNT(DISTINCT insurer_evaluations.pharmacy_id) as evaluations')
            ->selectRaw(self::CRITERION_AVERAGES)
            ->groupBy('insurers.id', 'insurers.name')
            ->orderBy('insurers.name')
            ->get();

        return array_values($rows->map(function (object $row) use ($minimum): array {
            $evaluations = (int) $row->evaluations;

            if ($evaluations < $minimum) {
                return [
                    'insurerId' => (int) $row->insurer_id,
                    'insurerName' => (string) $row->insurer_name,
                    'withheld' => true,
                    'evaluations' => null,
                    'total' => null,
                    'criteria' => null,
                ];
            }

            $criteria = [];

            foreach (EvaluationCriterion::columns() as $column) {
                $criteria[$column] = round((float) $row->{$column}, 1);
            }

            return [
                'insurerId' => (int) $row->insurer_id,
                'insurerName' => (string) $row->insurer_name,
                'withheld' => false,
                'evaluations' => $evaluations,
                // Sommé sur les moyennes brutes, arrondi une fois : la somme
                // des critères arrondis s'écarterait du total d'un dixième.
                'total' => round(array_sum(array_map(
                    fn (string $column): float => (float) $row->{$column},
                    EvaluationCriterion::columns(),
                )), 1),
                'criteria' => $criteria,
            ];
        })->all());
    }
}
