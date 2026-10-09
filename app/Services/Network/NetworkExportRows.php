<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\InsurerAmounts;
use App\Data\InsurerIndicators;
use App\Data\InsurerPenaltyFigures;
use App\Data\Period;
use App\Models\Insurer;
use App\Services\Settings\SettingsRepository;

/**
 * The network statistics as rows, whatever file they end up in.
 *
 * An export is the worst possible place for a leak: it leaves the application
 * and lands in mailboxes. Insurers below the anonymity threshold therefore
 * produce a row that says so and carries no figure at all — not a row that is
 * quietly dropped, because a missing line reads as « no data » rather than as
 * « withheld to protect an officine ».
 *
 * The same holds one level down for the penalty split by settlement status —
 * due, recovered, waived. An authorised insurer may still have a single
 * officine behind one of those parts, whose exact amount it would then name.
 * The three columns are emptied together (« partition rule »): any one of
 * them, next to another published figure, would give a hidden one back.
 *
 * One more way back: the network penalty journal of the same scope publishes
 * the split month by month. When it withholds a month of this insurer — whole,
 * or its split only —, the period figures minus the published months would
 * give that month back. The three columns are then emptied as well.
 *
 * That rule lives here, once, rather than in each writer: two export formats
 * that could disagree on who gets figures would be the leak itself.
 *
 * Values are left in their own type — an int stays an int — so a spreadsheet
 * writer can produce a numeric cell. Rendering them as text is the CSV's job.
 *
 * @phpstan-type ExportRow list<string|int|float|null>
 */
class NetworkExportRows
{
    /** @var list<string> */
    public const COLUMNS = [
        'assureur',
        'officines_declarantes',
        'declarations',
        'delai_moyen_jours',
        'delai_moyen_pondere_jours',
        'delai_le_plus_long_jours',
        'delai_standard_jours',
        'delai_declenchement_penalite_jours',
        'taux_penalite_pct',
        'part_sous_seuil_pct',
        'recouvre_dans_delai_pct',
        'versements',
        'versements_par_declaration',
        'part_reglements_fractionnes_pct',
        'delai_premier_versement_jours',
        'taux_rejet_pct',
        'taux_non_paiement_pct',
        'facture_fcfa',
        'encaisse_fcfa',
        'encours_fcfa',
        'taux_recouvrement_pct',
        'penalite_potentielle_fcfa',
        'penalite_recouvree_fcfa',
        'penalite_abandonnee_fcfa',
    ];

    public function __construct(
        protected NetworkStatsService $stats,
        protected InsurerPenaltyAggregates $penalties,
        protected SettingsRepository $settings,
        protected NetworkPenaltyJournal $journal,
    ) {
        //
    }

    /**
     * One row per insurer, without the header.
     *
     * @return iterable<int, ExportRow>
     */
    public function rows(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): iterable
    {
        $indicators = $this->stats->perInsurer($from, $to, $city, $insurerId);
        $amounts = $this->stats->aggregatedByInsurer($from, $to, $city, $insurerId);
        $names = Insurer::query()->whereIn('id', array_keys($indicators))->pluck('name', 'id');
        $minimum = $this->settings->anonymityMinPharmacies();

        // Les identifiants passés ici sont ceux que perInsurer() a laissé
        // passer : l'agrégateur n'a pas la liberté de contourner le seuil.
        // Un assureur choisi est donc déjà seul dans cette liste, et
        // InsurerPenaltyAggregates n'a pas besoin de connaître le filtre.
        $allowed = array_keys(array_filter(
            $indicators,
            fn (InsurerIndicators|InsufficientData $entry): bool => $entry instanceof InsurerIndicators,
        ));

        $figures = $this->penalties->forInsurers($allowed, $from, $to, $city);
        $ledgerWithheld = $this->ledgerWithheld($allowed, $from, $to, $city, $insurerId);

        foreach ($indicators as $insurerId => $entry) {
            $name = (string) ($names[$insurerId] ?? '');

            if ($entry instanceof InsufficientData) {
                yield $this->withheld($name, $entry);

                continue;
            }

            $amount = $amounts[$insurerId] ?? null;

            // Both aggregations group the same query under the same threshold
            // decision, so a sufficient insurer always has its amounts. If that
            // ever ceased to hold, withholding is the safe reading — never
            // emitting a row of blanks that looks like « nothing was invoiced ».
            if (! $amount instanceof InsurerAmounts) {
                yield $this->withheld($name, new InsufficientData(
                    $entry->declaringPharmacies,
                    $minimum,
                ));

                continue;
            }

            yield $this->full(
                $name,
                $entry,
                $amount,
                $figures[$insurerId] ?? new InsurerPenaltyFigures(null, null),
                $minimum,
                in_array($insurerId, $ledgerWithheld, true),
                $city === null,
            );
        }
    }

    /**
     * Les assureurs autorisés dont le journal des pénalités du même périmètre
     * retient au moins un mois, en entier ou dans son découpage.
     *
     * Même période, même ville, même filtre assureur que l'export : c'est
     * quand les deux fenêtres coïncident que la différence rend le mois
     * caché. Un seul appel pour tout l'export, pas un par assureur.
     *
     * @param  list<int>  $allowed
     * @return list<int>
     */
    protected function ledgerWithheld(array $allowed, Period $from, Period $to, ?string $city, ?int $insurerId): array
    {
        if ($allowed === []) {
            return [];
        }

        return $this->journal->for($from, $to, $city, $insurerId)->insurersWithWithheldMonths();
    }

    /**
     * A row that states the figures are withheld, and carries none.
     *
     * Not even the exact number of declaring officines: under the threshold,
     * that count is itself a figure — « 1 », next to the declaration
     * follow-up that names who declared, designates the officine. The cell
     * says « moins de N » instead.
     *
     * @return ExportRow
     */
    protected function withheld(string $name, InsufficientData $entry): array
    {
        $row = array_fill(0, count(self::COLUMNS), null);

        $row[0] = $name;

        if ($entry->cityShare) {
            // Le compte non plus : non filtré moins les villes publiées, il
            // rendrait exactement la part cachée.
            $row[1] = 'retenu';
            $row[2] = 'chiffres retenus hors filtre ville, les villes non publiees et les officines sans ville y pesent moins de '.$entry->required.' officines, qui se deduiraient par difference';

            return $row;
        }

        $row[1] = 'moins de '.$entry->required;
        $row[2] = 'donnees insuffisantes moins de '.$entry->required.' officines declarantes, agregation a partir de '.$entry->required;

        return $row;
    }

    /**
     * @return ExportRow
     */
    protected function full(
        string $name,
        InsurerIndicators $entry,
        InsurerAmounts $amount,
        InsurerPenaltyFigures $figures,
        int $minimum,
        bool $ledgerWithheld,
        bool $unfilteredByCity,
    ): array {
        // Les trois ensemble, jamais une seule : une part publiée à côté d'une
        // part cachée finit toujours par la rendre, par différence avec un
        // autre chiffre publié ailleurs (PDF, journal). Et les trois aussi
        // quand le journal retient un mois : période − mois publiés le rendrait.
        // Et, non filtré par ville, quand les parts des villes retenues (et des
        // officines sans ville) reposent sur trop peu d'officines : l'export
        // moins les exports par ville les rendrait (CityPartition).
        $splitWithheld = $ledgerWithheld
            || $figures->splitPharmacies->restsOnFewerThan($minimum)
            || ($unfilteredByCity && CityPartition::withholdsSplit($figures->citySplitPharmacies, $minimum));

        return [
            $name,
            $entry->declaringPharmacies,
            $entry->declarations,
            $entry->averageDelayDays,
            $entry->weightedDelayDays,
            $figures->longestDelayDays,
            $entry->standardDelayDays,
            // Le délai et le taux accompagnent le montant pour le rendre
            // vérifiable : cet export finit dans un courrier adressé à
            // l'assureur, où le chiffre doit pouvoir être refait.
            $entry->penaltyTriggerDays,
            $entry->penaltyRatePercent,
            $entry->withinThresholdShare,
            $entry->recoveredWithinDelayShare,
            $entry->instalments,
            $entry->instalmentsPerDeclaration,
            $entry->multiInstalmentShare,
            $entry->averageFirstInstalmentDelayDays,
            $entry->rejectionRate,
            $entry->unpaidRate,
            $amount->invoiced,
            $amount->received,
            $amount->outstanding,
            $amount->recoveryRate,
            $splitWithheld ? null : $figures->penalty,
            $splitWithheld ? null : $figures->recovered,
            $splitWithheld ? null : $figures->waived,
        ];
    }
}
