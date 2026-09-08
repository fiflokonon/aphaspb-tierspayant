<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\InsurerAmounts;
use App\Data\Period;
use App\Models\Insurer;
use App\Services\Settings\SettingsRepository;
use App\Support\MonthLabel;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * The network statistics as a report, rather than as a table to compute on.
 *
 * The CSV and the workbook exist to be reopened and totalled; this one exists
 * to be read and attached to a letter. It therefore states its own scope on
 * every page, spells the anonymity rule out where it applies, and puts the
 * figures in the order an argument needs them — the network first, then who
 * inside it behaves how.
 *
 * The withholding rule is not re-decided here: NetworkExportRows owns it, and
 * every format obeys the same call.
 */
class NetworkPdfExport
{
    public function __construct(
        protected NetworkStatsService $stats,
        protected SettingsRepository $settings,
    ) {
        //
    }

    public function document(Period $from, Period $to, ?string $city = null): PdfDocument
    {
        return Pdf::loadView('exports.network', $this->data($from, $to, $city))
            ->setPaper('a4', 'portrait');
    }

    /**
     * Everything the view renders, resolved before a single tag is written.
     *
     * @return array<string, mixed>
     */
    protected function data(Period $from, Period $to, ?string $city): array
    {
        $indicators = $this->stats->perInsurer($from, $to, $city);
        $amounts = $this->stats->aggregatedByInsurer($from, $to, $city);
        $names = Insurer::query()->whereIn('id', array_keys($indicators))->pluck('name', 'id');

        $rows = [];
        $withheld = [];

        foreach ($indicators as $insurerId => $entry) {
            $name = (string) ($names[$insurerId] ?? '');

            if ($entry instanceof InsufficientData) {
                $withheld[] = ['name' => $name, 'declaringPharmacies' => $entry->declaringPharmacies];

                continue;
            }

            $amount = $amounts[$insurerId] ?? null;

            $rows[] = [
                'name' => $name,
                'indicators' => $entry,
                'amounts' => $amount instanceof InsurerAmounts ? $amount : null,
            ];
        }

        // Le plus mauvais payeur en tête : un rapport se lit du haut, et c'est
        // la ligne sur laquelle une lettre de relance s'appuie.
        usort($rows, fn (array $a, array $b): int => ($b['indicators']->averageDelayDays ?? 0)
            <=> ($a['indicators']->averageDelayDays ?? 0));

        return [
            'summary' => $this->stats->networkSummary($from, $to, $city),
            'rows' => $rows,
            'withheld' => $withheld,
            'city' => $city,
            'anonymityThreshold' => $this->settings->anonymityMinPharmacies(),
            'periodLabel' => $this->periodLabel($from, $to),
            'generatedAt' => now(),
        ];
    }

    protected function periodLabel(Period $from, Period $to): string
    {
        $start = MonthLabel::long($from->month, $from->year);
        $end = MonthLabel::long($to->month, $to->year);

        return $start === $end ? $start : $start.' — '.$end;
    }
}
