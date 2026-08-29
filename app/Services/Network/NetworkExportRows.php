<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\InsurerAmounts;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Models\Insurer;

/**
 * The network statistics as rows, whatever file they end up in.
 *
 * An export is the worst possible place for a leak: it leaves the application
 * and lands in mailboxes. Insurers below the anonymity threshold therefore
 * produce a row that says so and carries no figure at all — not a row that is
 * quietly dropped, because a missing line reads as « no data » rather than as
 * « withheld to protect an officine ».
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
        'delai_standard_jours',
        'part_sous_seuil_pct',
        'taux_rejet_pct',
        'taux_non_paiement_pct',
        'facture_fcfa',
        'encaisse_fcfa',
        'encours_fcfa',
        'taux_recouvrement_pct',
    ];

    public function __construct(protected NetworkStatsService $stats)
    {
        //
    }

    /**
     * One row per insurer, without the header.
     *
     * @return iterable<int, ExportRow>
     */
    public function rows(Period $from, Period $to, ?string $city = null): iterable
    {
        $indicators = $this->stats->perInsurer($from, $to, $city);
        $amounts = $this->stats->aggregatedByInsurer($from, $to, $city);
        $names = Insurer::query()->whereIn('id', array_keys($indicators))->pluck('name', 'id');

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
                    $entry->declaringPharmacies,
                ));

                continue;
            }

            yield $this->full($name, $entry, $amount);
        }
    }

    /**
     * A row that states the figures are withheld, and carries none.
     *
     * @return ExportRow
     */
    protected function withheld(string $name, InsufficientData $entry): array
    {
        $row = array_fill(0, count(self::COLUMNS), null);

        $row[0] = $name;
        $row[1] = $entry->declaringPharmacies;
        $row[2] = 'donnees insuffisantes — agregation a partir de '.$entry->required.' officines';

        return $row;
    }

    /**
     * @return ExportRow
     */
    protected function full(string $name, InsurerIndicators $entry, InsurerAmounts $amount): array
    {
        return [
            $name,
            $entry->declaringPharmacies,
            $entry->declarations,
            $entry->averageDelayDays,
            $entry->weightedDelayDays,
            $entry->standardDelayDays,
            $entry->withinThresholdShare,
            $entry->rejectionRate,
            $entry->unpaidRate,
            $amount->invoiced,
            $amount->received,
            $amount->outstanding,
            $amount->recoveryRate,
        ];
    }
}
