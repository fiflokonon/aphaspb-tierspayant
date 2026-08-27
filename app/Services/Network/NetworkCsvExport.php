<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\InsurerAmounts;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Models\Insurer;

/**
 * The network statistics as a file the APhaSPB can circulate.
 *
 * An export is the worst possible place for a leak: it leaves the application
 * and lands in mailboxes. Insurers below the anonymity threshold therefore
 * produce a row that says so and carries no figure at all — not a row that is
 * quietly dropped, because a missing line reads as « no data » rather than as
 * « withheld to protect an officine ».
 */
class NetworkCsvExport
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
     * The rows of the file, header first.
     *
     * @return iterable<int, list<string>>
     */
    public function rows(Period $from, Period $to, ?string $city = null): iterable
    {
        yield self::COLUMNS;

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
     * @return list<string>
     */
    protected function withheld(string $name, InsufficientData $entry): array
    {
        $row = array_fill(0, count(self::COLUMNS), '');

        $row[0] = $name;
        $row[1] = (string) $entry->declaringPharmacies;
        $row[2] = 'donnees insuffisantes — agregation a partir de '.$entry->required.' officines';

        return $row;
    }

    /**
     * @return list<string>
     */
    protected function full(string $name, InsurerIndicators $entry, InsurerAmounts $amount): array
    {
        return [
            $name,
            (string) $entry->declaringPharmacies,
            (string) $entry->declarations,
            $this->decimal($entry->averageDelayDays),
            $this->decimal($entry->weightedDelayDays),
            (string) $entry->standardDelayDays,
            $this->decimal($entry->withinThresholdShare),
            $this->decimal($entry->rejectionRate),
            $this->decimal($entry->unpaidRate),
            (string) $amount->invoiced,
            (string) $amount->received,
            (string) $amount->outstanding,
            $this->decimal($amount->recoveryRate),
        ];
    }

    /**
     * Comma decimals: the file is read in a French Excel, not by a parser.
     */
    protected function decimal(?float $value): string
    {
        return $value === null ? '' : str_replace('.', ',', (string) $value);
    }
}
