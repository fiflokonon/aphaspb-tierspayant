<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Models\Insurer;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * La pénalité exigible à ce jour, assureur par assureur : de quoi négocier.
 *
 * Le journal dit ce qui a couru mois par mois ; ce relevé dit ce que chaque
 * assureur doit encore au réseau aujourd'hui, ce qu'il a déjà payé, ce que les
 * officines ont abandonné, et depuis combien de temps traîne sa pire facture.
 *
 * **Aucune décision d'anonymat ici.** Les chiffres sont ceux de l'export
 * réseau du même périmètre (`penalite_potentielle_fcfa`, `_recouvree_`,
 * `_abandonnee_`, `delai_le_plus_long_jours`), lus par
 * NetworkExportRows::byInsurer() avec ses rétentions : assureur sous le seuil,
 * règle de partition du découpage, mois retenu du journal, part des villes.
 * Une seconde décision serait un second endroit où se tromper, et l'écran
 * publierait ce que le fichier retient.
 *
 * Pour la même raison, pas de total réseau qui inclurait les assureurs
 * retenus : total moins lignes publiées les rendrait. Le seul total est celui
 * des lignes publiées, qui ne dit rien de plus qu'elles.
 *
 * @phpstan-type ClaimRow array{id: int, name: string, triggerDays: int|null, ratePercent: float|null, withheld: 'insurer'|'split'|null, note: string|null, due: int|null, recovered: int|null, waived: int|null, longestDelayDays: int|null}
 */
class NetworkPenaltyClaims
{
    public function __construct(
        protected NetworkExportRows $rows,
        protected NetworkPenaltyJournal $journal,
        protected SettingsRepository $settings,
    ) {
        //
    }

    /**
     * @return array{asOf: string, threshold: int, publishedDue: int, insurers: list<ClaimRow>}
     */
    public function for(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): array
    {
        $clauses = Insurer::query()
            ->whereIn('id', $this->journal->clauseInsurers()->pluck('id'))
            ->get(['id', 'name', 'penalty_trigger_days', 'penalty_rate_bp'])
            ->keyBy('id');

        $column = array_flip(NetworkExportRows::COLUMNS);
        $threshold = $this->settings->anonymityMinPharmacies();
        $claims = [];

        foreach ($this->rows->byInsurer($from, $to, $city, $insurerId) as $id => $row) {
            $insurer = $clauses->get($id);

            if ($insurer === null) {
                continue;
            }

            // Une ligne retenue porte « moins de N » (ou « retenu ») là où
            // une ligne publiée porte un compte d'officines.
            $insurerWithheld = ! is_int($row[$column['officines_declarantes']]);
            $due = $insurerWithheld ? null : $row[$column['penalite_potentielle_fcfa']];

            $claims[] = [
                'id' => (int) $id,
                'name' => $insurer->name,
                'triggerDays' => $insurer->penalty_trigger_days,
                'ratePercent' => $insurer->penaltyRatePercent(),
                'withheld' => match (true) {
                    $insurerWithheld => 'insurer',
                    $due === null => 'split',
                    default => null,
                },
                'note' => match (true) {
                    $insurerWithheld && $row[$column['officines_declarantes']] === 'retenu' => "Retenu hors filtre ville : les villes non publiées y pèsent moins de {$threshold} officines.",
                    $insurerWithheld => "Retenu : moins de {$threshold} officines déclarantes sur ce périmètre.",
                    $due === null => 'Montants retenus : une part repose sur trop peu d\'officines, ou le journal retient un mois du périmètre.',
                    default => null,
                },
                'due' => $due === null ? null : (int) $due,
                'recovered' => $this->amount($insurerWithheld ? null : $row[$column['penalite_recouvree_fcfa']]),
                'waived' => $this->amount($insurerWithheld ? null : $row[$column['penalite_abandonnee_fcfa']]),
                'longestDelayDays' => $this->amount($insurerWithheld ? null : $row[$column['delai_le_plus_long_jours']]),
            ];
        }

        // Ce qui est exigible d'abord, les retenus en dernier.
        usort($claims, fn (array $a, array $b): int => [$b['due'] ?? -1, $a['name']] <=> [$a['due'] ?? -1, $b['name']]);

        return [
            'asOf' => CarbonImmutable::today()->translatedFormat('j M Y'),
            'threshold' => $threshold,
            'publishedDue' => array_sum(array_map(fn (array $claim): int => $claim['due'] ?? 0, $claims)),
            'insurers' => $claims,
        ];
    }

    protected function amount(string|int|float|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
