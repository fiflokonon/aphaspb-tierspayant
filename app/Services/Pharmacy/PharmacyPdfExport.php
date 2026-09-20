<?php

namespace App\Services\Pharmacy;

use App\Data\Period;
use App\Models\Declaration;
use App\Models\Pharmacy;
use App\Services\Declarations\LongestDelay;
use App\Services\Declarations\PenaltyCalculator;
use App\Support\MonthLabel;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Collection;

/**
 * An officine's own figures as a report it can hand over.
 *
 * The register on screen answers « what did I declare »; this answers « how is
 * my tiers-payant going », which is the question a banker, an accountant or a
 * meeting with an insurer actually asks. So it opens on the totals, then breaks
 * them down by insurer, and only then lists the months.
 *
 * It reads one named officine and withholds nothing from it — the anonymity
 * threshold has no business here, and NetworkStatsService is deliberately not
 * involved.
 */
class PharmacyPdfExport
{
    public function __construct(
        protected PharmacyExportRows $source,
        protected PharmacyStatsService $stats,
        protected PenaltyCalculator $penalties,
        protected LongestDelay $longestDelay,
    ) {
        //
    }

    public function document(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId = null): PdfDocument
    {
        return Pdf::loadView('exports.pharmacy', $this->data($pharmacy, $from, $to, $insurerId))
            ->setPaper('a4', 'portrait');
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId): array
    {
        $declarations = $this->source->declarations($pharmacy, $from, $to, $insurerId);

        return [
            'pharmacy' => $pharmacy,
            'totals' => $this->totals($declarations),
            'perInsurer' => $this->perInsurer($declarations),
            'insurerPages' => $this->insurerPages($declarations),
            'declarations' => $declarations,
            'periodLabel' => $this->periodLabel($from, $to),
            'insurerFilter' => $insurerId === null
                ? null
                : $declarations->first()?->insurer->name,
            'generatedAt' => now(),
        ];
    }

    /**
     * The headline figures, computed over the very rows the file lists.
     *
     * Not delegated to PharmacyStatsService: that one answers over a window of
     * months ending today, and a report whose totals disagreed with its own
     * table would be worse than a report with no totals at all.
     *
     * @param  Collection<int, Declaration>  $declarations
     * @return array<string, int|float|null>
     */
    protected function totals(Collection $declarations): array
    {
        $invoiced = (int) $declarations->sum('amount_invoiced');
        $received = (int) $declarations->sum('amount_received');
        $dated = $declarations->whereNotNull('delay_days');
        $instalments = $declarations->sum(fn (Declaration $one): int => $one->payments->count());

        return [
            'declarations' => $declarations->count(),
            'insurers' => $declarations->pluck('insurer_id')->unique()->count(),
            'invoiced' => $invoiced,
            'received' => $received,
            'outstanding' => max(0, $invoiced - $received),
            'recoveryRate' => $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            'averageDelayDays' => $dated->count() > 0 ? round($dated->avg('delay_days'), 1) : null,
            'instalments' => $instalments,
            'splitSettlements' => $declarations->filter(
                fn (Declaration $one): bool => $one->payments->count() > 1,
            )->count(),
            'longestDelayDays' => $this->longestDelay->for($declarations),
            'penalty' => $this->penalties->total($declarations),
            'withinStandard' => $dated->count() === 0 ? null : round($dated->filter(
                fn (Declaration $one): bool => $one->delay_days <= $one->insurer->standard_delay_days,
            )->count() / $dated->count() * 100, 1),
        ];
    }

    /**
     * The same figures, insurer by insurer, worst payer first.
     *
     * @param  Collection<int, Declaration>  $declarations
     * @return list<array<string, mixed>>
     */
    protected function perInsurer(Collection $declarations): array
    {
        $rows = $declarations->groupBy('insurer_id')->map(function (Collection $group): array {
            $insurer = $group->first()->insurer;
            $invoiced = (int) $group->sum('amount_invoiced');
            $received = (int) $group->sum('amount_received');
            $dated = $group->whereNotNull('delay_days');

            return [
                'name' => $insurer->name,
                'standardDelayDays' => $insurer->standard_delay_days,
                'declarations' => $group->count(),
                'invoiced' => $invoiced,
                'received' => $received,
                'outstanding' => max(0, $invoiced - $received),
                'recoveryRate' => $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
                'averageDelayDays' => $dated->count() > 0 ? round($dated->avg('delay_days'), 1) : null,
                'instalments' => $group->sum(fn (Declaration $one): int => $one->payments->count()),
                'splitSettlements' => $group->filter(
                    fn (Declaration $one): bool => $one->payments->count() > 1,
                )->count(),
                // Calculés sur le groupe que ce fichier liste, jamais délégués
                // à InsurerRelationshipReport : une synthèse qui contredirait
                // sa propre table de détail serait pire que pas de synthèse.
                'longestDelayDays' => $this->longestDelay->for($group),
                'penalty' => $this->penalties->total($group),
            ];
        })->values();

        // array_values() rather than the collection's: what leaves this method
        // is declared a list, and only re-indexing an array proves it is one.
        return array_values($rows->sortByDesc(fn (array $row): int => $row['outstanding'])->all());
    }

    /**
     * Une page par assureur, avec ses seuls mois.
     *
     * Bâtie sur la même collection que perInsurer() et que la table de détail :
     * les trois vues du même fichier ne peuvent pas se contredire, et un test
     * compare une page à la ligne de synthèse qui la précède.
     *
     * Les mois sont aplatis ici et non dans la vue : une vue Blade ne doit pas
     * appeler un service, et la pénalité de chaque mois demande le calculateur.
     *
     * @param  Collection<int, Declaration>  $declarations
     * @return list<array<string, mixed>>
     */
    protected function insurerPages(Collection $declarations): array
    {
        $pages = $declarations->groupBy('insurer_id')->map(function (Collection $group): array {
            $insurer = $group->first()->insurer;
            $invoiced = (int) $group->sum('amount_invoiced');
            $received = (int) $group->sum('amount_received');

            return [
                'name' => $insurer->name,
                'standardDelayDays' => $insurer->standard_delay_days,
                'penaltyTriggerDays' => $insurer->penalty_trigger_days,
                'penaltyRatePercent' => $insurer->penaltyRatePercent(),
                'longestDelayDays' => $this->longestDelay->for($group),
                'penalty' => $this->penalties->total($group),
                'invoiced' => $invoiced,
                'received' => $received,
                'outstanding' => max(0, $invoiced - $received),
                // Du plus récent au plus ancien, comme la table de détail.
                'months' => array_values($group->sortByDesc(
                    fn (Declaration $one): int => $one->period_year * 12 + $one->period_month,
                )->map(fn (Declaration $one): array => [
                    'monthLabel' => MonthLabel::short($one->period_month, $one->period_year),
                    'statusLabel' => $one->status->label(),
                    'invoiced' => $one->amount_invoiced,
                    'received' => $one->amount_received,
                    'outstanding' => $one->amount_outstanding,
                    'depositedOn' => $one->invoice_deposited_on?->toDateString(),
                    'delayDays' => $one->delay_days,
                    'penalty' => $this->penalties->for($one),
                ])->all()),
            ];
        })->values();

        // array_values() plutôt que celui de la collection : ce qui sort est
        // déclaré list, et seule une réindexation le prouve.
        return array_values($pages->sortByDesc(fn (array $page): int => $page['outstanding'])->all());
    }

    protected function periodLabel(Period $from, Period $to): string
    {
        $start = MonthLabel::long($from->month, $from->year);
        $end = MonthLabel::long($to->month, $to->year);

        return $start === $end ? $start : $start.' — '.$end;
    }
}
