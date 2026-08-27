<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\InsurerAmounts;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Insurer;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single path by which an APhaSPB account reads declarations.
 *
 * Concentrating every aggregate here is what makes the anonymity rule
 * verifiable: one place enforces the threshold, one test covers it. No
 * controller may query the declarations table directly.
 *
 * Reads go through the query builder rather than Eloquent on purpose: an
 * aggregate row is not a Declaration, and hydrating models would both mislead
 * the type system and load columns — private notes among them — that no
 * aggregate is allowed to touch.
 */
class NetworkStatsService
{
    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    /**
     * Aggregate each insurer's indicators over an inclusive period range.
     *
     * @return array<int, InsurerIndicators|InsufficientData> keyed by insurer id
     */
    public function perInsurer(Period $from, Period $to, ?string $city = null): array
    {
        $threshold = $this->settings->paymentDelayThresholdDays();
        $minimum = $this->settings->anonymityMinPharmacies();

        $rows = $this->baseQuery($from, $to, $city)
            ->select('insurer_id')
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(amount_invoiced) as amount_invoiced')
            ->selectRaw('SUM(amount_received) as amount_received')
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN 1 ELSE 0 END) as settled")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days ELSE 0 END) as delay_total")
            ->selectRaw(
                "SUM(CASE WHEN status IN ('paid', 'partial') AND delay_days <= ? THEN 1 ELSE 0 END) as within_threshold",
                [$threshold],
            )
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw("SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) as unpaid")
            ->groupBy('insurer_id')
            ->get();

        $names = Insurer::query()
            ->whereIn('id', $rows->pluck('insurer_id'))
            ->pluck('name', 'id');

        $indicators = [];

        foreach ($rows as $row) {
            $declaring = (int) $row->declaring_pharmacies;
            $insurerId = (int) $row->insurer_id;

            if (! $this->isSufficient($declaring, $minimum)) {
                $indicators[$insurerId] = new InsufficientData(
                    declaringPharmacies: $declaring,
                    required: $minimum,
                );

                continue;
            }

            $total = (int) $row->total;
            $settled = (int) $row->settled;
            $invoiced = (int) $row->amount_invoiced;
            $received = (int) $row->amount_received;

            $indicators[$insurerId] = new InsurerIndicators(
                insurerName: (string) $names[$insurerId],
                declaringPharmacies: $declaring,
                averageDelayDays: $settled > 0 ? round((int) $row->delay_total / $settled, 1) : null,
                withinThresholdShare: $settled > 0 ? round((int) $row->within_threshold / $settled * 100, 1) : null,
                rejectionRate: $total > 0 ? round((int) $row->rejected / $total * 100, 1) : null,
                unpaidRate: $total > 0 ? round((int) $row->unpaid / $total * 100, 1) : null,
                amountInvoiced: $invoiced,
                amountReceived: $received,
                amountOutstanding: max(0, $invoiced - $received),
                recoveryRate: $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            );
        }

        return $indicators;
    }

    /**
     * How many insurers the anonymity threshold currently hides.
     *
     * Used by the sidebar notice, which restates the rule permanently rather
     * than only where a hidden row happens to appear.
     */
    public function maskedInsurerCount(): int
    {
        [$from, $to] = Period::currentQuarter();

        return count(array_filter(
            $this->perInsurer($from, $to),
            fn (InsurerIndicators|InsufficientData $entry): bool => $entry instanceof InsufficientData,
        ));
    }

    /**
     * Monthly average delay per insurer, plus the network average.
     *
     * @return array{insurers: array<int, array{name: string, points: array<string, float>}>, network: array<string, float>, threshold: int}
     */
    public function delayTrend(int $months): array
    {
        [$from, $to] = Period::lastMonths($months);

        $eligible = array_keys(array_filter(
            $this->perInsurer($from, $to),
            fn (InsurerIndicators|InsufficientData $entry): bool => $entry instanceof InsurerIndicators,
        ));

        $rows = $this->baseQuery($from, $to)
            ->whereIn('insurer_id', $eligible)
            ->whereIn('status', DeclarationStatus::settledValues())
            ->select('insurer_id', 'period_year', 'period_month')
            ->selectRaw('AVG(delay_days) as average_delay')
            ->groupBy('insurer_id', 'period_year', 'period_month')
            ->get();

        $names = Insurer::query()->whereIn('id', $eligible)->pluck('name', 'id');

        $insurers = [];
        $networkTotals = [];

        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', $row->period_year, $row->period_month);
            $insurerId = (int) $row->insurer_id;
            $average = (float) $row->average_delay;

            $insurers[$insurerId]['name'] ??= (string) $names[$insurerId];
            $insurers[$insurerId]['points'][$key] = round($average, 1);

            $networkTotals[$key][] = $average;
        }

        $network = [];

        foreach ($networkTotals as $key => $values) {
            $network[$key] = round(array_sum($values) / count($values), 1);
        }

        ksort($network);

        return [
            'insurers' => $insurers,
            'network' => $network,
            'threshold' => $this->settings->paymentDelayThresholdDays(),
        ];
    }

    /**
     * Network-wide delay indicators over a period.
     *
     * Not derivable from perInsurer(): averaging per-insurer averages would
     * weight a two-declaration insurer like a two-hundred-declaration one, and
     * the distinct pharmacy count cannot be summed across insurers because one
     * officine declares to several.
     *
     * @return array{declaringPharmacies: int, declarations: int, averageDelayDays: float|null, weightedDelayDays: float|null, withinThresholdShare: float|null, rejectionRate: float|null, outstandingBeyond90: int}
     */
    public function networkSummary(Period $from, Period $to, ?string $city = null): array
    {
        $threshold = $this->settings->paymentDelayThresholdDays();

        $row = $this->baseQuery($from, $to, $city)
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN 1 ELSE 0 END) as settled")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days ELSE 0 END) as delay_total")
            ->selectRaw(
                "SUM(CASE WHEN status IN ('paid', 'partial') AND delay_days <= ? THEN 1 ELSE 0 END) as within_threshold",
                [$threshold],
            )
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days * amount_received ELSE 0 END) as delay_weighted")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN amount_received ELSE 0 END) as delay_basis")
            ->first();

        $total = (int) ($row->total ?? 0);
        $settled = (int) ($row->settled ?? 0);
        $basis = (int) ($row->delay_basis ?? 0);

        return [
            'declaringPharmacies' => (int) ($row->declaring_pharmacies ?? 0),
            'declarations' => $total,
            'averageDelayDays' => $settled > 0 ? round((int) $row->delay_total / $settled, 1) : null,
            'weightedDelayDays' => $basis > 0 ? round((int) $row->delay_weighted / $basis, 1) : null,
            'withinThresholdShare' => $settled > 0 ? round((int) $row->within_threshold / $settled * 100, 1) : null,
            'rejectionRate' => $total > 0 ? round((int) $row->rejected / $total * 100, 1) : null,
            'outstandingBeyond90' => $this->outstandingBeyond($from, $to, $city, 90),
        ];
    }

    /**
     * Aggregated amounts per insurer, under the same anonymity threshold.
     *
     * A second per-insurer aggregation is a second place the rule could be
     * forgotten, so the decision is delegated to isSufficient() — the one
     * method both paths consult.
     *
     * @return array<int, InsurerAmounts|InsufficientData> keyed by insurer id
     */
    public function aggregatedByInsurer(Period $from, Period $to, ?string $city = null): array
    {
        $minimum = $this->settings->anonymityMinPharmacies();

        $rows = $this->baseQuery($from, $to, $city)
            ->select('insurer_id')
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->selectRaw('SUM(amount_invoiced) as invoiced')
            ->selectRaw('SUM(amount_received) as received')
            ->groupBy('insurer_id')
            ->get();

        $names = Insurer::query()
            ->whereIn('id', $rows->pluck('insurer_id'))
            ->pluck('name', 'id');

        $amounts = [];

        foreach ($rows as $row) {
            $insurerId = (int) $row->insurer_id;
            $declaring = (int) $row->declaring_pharmacies;

            if (! $this->isSufficient($declaring, $minimum)) {
                $amounts[$insurerId] = new InsufficientData($declaring, $minimum);

                continue;
            }

            $invoiced = (int) $row->invoiced;
            $received = (int) $row->received;

            $amounts[$insurerId] = new InsurerAmounts(
                insurerName: (string) $names[$insurerId],
                declaringPharmacies: $declaring,
                invoiced: $invoiced,
                received: $received,
                outstanding: max(0, $invoiced - $received),
                recoveryRate: $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            );
        }

        return $amounts;
    }

    /**
     * How much of the network's outstanding balance is older than $days.
     *
     * Age is counted from the end of the declared month, as on the officine
     * side: the CDC stores no invoice date.
     */
    public function outstandingBeyond(Period $from, Period $to, ?string $city, int $days): int
    {
        $rows = $this->baseQuery($from, $to, $city)
            ->whereRaw('amount_invoiced > amount_received')
            ->select('period_year', 'period_month')
            ->selectRaw('SUM(amount_invoiced - amount_received) as outstanding')
            ->groupBy('period_year', 'period_month')
            ->get();

        $today = now()->startOfDay();
        $total = 0;

        foreach ($rows as $row) {
            $age = (int) now()
                ->setDate((int) $row->period_year, (int) $row->period_month, 1)
                ->endOfMonth()
                ->startOfDay()
                ->diffInDays($today, absolute: false);

            if ($age > $days) {
                $total += (int) $row->outstanding;
            }
        }

        return $total;
    }

    /**
     * The single place that decides whether an insurer's figures may be shown.
     */
    protected function isSufficient(int $declaringPharmacies, int $minimum): bool
    {
        return $declaringPharmacies >= $minimum;
    }

    /**
     * Network-wide totals over a period, in FCFA and as shares.
     *
     * @return array{invoiced: int, received: int, outstanding: int, recovery_rate: float|null, declaring_pharmacies: int}
     */
    public function aggregatedAmounts(Period $from, Period $to): array
    {
        $row = $this->baseQuery($from, $to)
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->selectRaw('SUM(amount_invoiced) as invoiced')
            ->selectRaw('SUM(amount_received) as received')
            ->first();

        $invoiced = (int) ($row->invoiced ?? 0);
        $received = (int) ($row->received ?? 0);

        return [
            'invoiced' => $invoiced,
            'received' => $received,
            'outstanding' => max(0, $invoiced - $received),
            'recovery_rate' => $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            'declaring_pharmacies' => (int) ($row->declaring_pharmacies ?? 0),
        ];
    }

    /**
     * Build the shared filter: period range, optional city.
     */
    protected function baseQuery(Period $from, Period $to, ?string $city = null): Builder
    {
        return DB::table('declarations')
            ->whereRaw(
                '(period_year * 12 + period_month) BETWEEN ? AND ?',
                [$from->toOrdinal(), $to->toOrdinal()],
            )
            ->when($city, fn (Builder $query, string $filtered) => $query->whereExists(
                fn (Builder $sub) => $sub->from('pharmacies')
                    ->whereColumn('pharmacies.id', 'declarations.pharmacy_id')
                    ->where('pharmacies.city', $filtered),
            ));
    }
}
