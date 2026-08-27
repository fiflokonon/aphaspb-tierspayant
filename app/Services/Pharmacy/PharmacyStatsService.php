<?php

namespace App\Services\Pharmacy;

use App\Data\AgeingBucket;
use App\Models\Pharmacy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * An officine's own payment figures.
 *
 * Deliberately separate from NetworkStatsService: that one may never read a
 * named officine, and this one may never aggregate the network. Two classes
 * make the boundary legible, and each has its own confidentiality test.
 *
 * Reads go through the query builder rather than Eloquent — an aggregate row is
 * not a Declaration, and not hydrating avoids pulling private_note along.
 */
class PharmacyStatsService
{
    /**
     * Totals over the last $months months, ending with the current one.
     *
     * @return array{invoiced: int, received: int, outstanding: int, recoveryRate: float|null, weightedDelayDays: float|null, insurers: int, declarations: int}
     */
    public function summary(Pharmacy $pharmacy, int $months): array
    {
        $row = $this->window($pharmacy, $months)
            ->selectRaw('COUNT(*) as declarations')
            ->selectRaw('SUM(amount_invoiced) as invoiced')
            ->selectRaw('SUM(amount_received) as received')
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days * amount_received ELSE 0 END) as delay_weighted")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN amount_received ELSE 0 END) as delay_basis")
            ->first();

        $invoiced = (int) ($row->invoiced ?? 0);
        $received = (int) ($row->received ?? 0);
        $basis = (int) ($row->delay_basis ?? 0);

        return [
            'invoiced' => $invoiced,
            'received' => $received,
            'outstanding' => max(0, $invoiced - $received),
            'recoveryRate' => $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            'weightedDelayDays' => $basis > 0 ? round((int) $row->delay_weighted / $basis, 1) : null,
            'insurers' => $pharmacy->insurers()->count(),
            'declarations' => (int) ($row->declarations ?? 0),
        ];
    }

    /**
     * Invoiced and collected, month by month, with empty months at zero.
     *
     * @return list<array{key: string, label: string, invoiced: int, received: int, outstanding: int, isCurrent: bool}>
     */
    public function monthlyJourney(Pharmacy $pharmacy, int $months): array
    {
        $rows = $this->window($pharmacy, $months)
            ->select('period_year', 'period_month')
            ->selectRaw('SUM(amount_invoiced) as invoiced')
            ->selectRaw('SUM(amount_received) as received')
            ->groupBy('period_year', 'period_month')
            ->get()
            ->keyBy(fn (object $row) => sprintf('%04d-%02d', $row->period_year, $row->period_month));

        $initials = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
        $now = now();
        $journey = [];

        for ($back = $months - 1; $back >= 0; $back--) {
            $month = $now->subMonths($back);
            $key = sprintf('%04d-%02d', $month->year, $month->month);
            $row = $rows->get($key);

            $invoiced = (int) ($row->invoiced ?? 0);
            $received = (int) ($row->received ?? 0);

            $journey[] = [
                'key' => $key,
                'label' => $initials[$month->month - 1],
                'invoiced' => $invoiced,
                'received' => $received,
                'outstanding' => max(0, $invoiced - $received),
                'isCurrent' => $back === 0,
            ];
        }

        return $journey;
    }

    /**
     * The outstanding balance split by age band.
     *
     * @return list<AgeingBucket>
     */
    public function ageingBuckets(Pharmacy $pharmacy): array
    {
        $bands = [
            ['0–30 j', 0, 30],
            ['31–60 j', 31, 60],
            ['61–90 j', 61, 90],
            ['> 90 j', 91, null],
        ];

        $totals = array_fill(0, count($bands), 0);

        foreach ($this->outstandingByMonth($pharmacy) as $entry) {
            foreach ($bands as $index => [, $from, $to]) {
                if ($entry['age'] >= $from && ($to === null || $entry['age'] <= $to)) {
                    $totals[$index] += $entry['outstanding'];
                    break;
                }
            }
        }

        return array_map(
            fn (int $index) => new AgeingBucket(
                label: $bands[$index][0],
                amount: $totals[$index],
                fromDays: $bands[$index][1],
                toDays: $bands[$index][2],
            ),
            array_keys($bands),
        );
    }

    /**
     * How much of the outstanding balance is older than $days.
     */
    public function outstandingBeyond(Pharmacy $pharmacy, int $days): int
    {
        $total = 0;

        foreach ($this->outstandingByMonth($pharmacy) as $entry) {
            if ($entry['age'] > $days) {
                $total += $entry['outstanding'];
            }
        }

        return $total;
    }

    /**
     * Which insurers owe the most, descending, ticked-but-settled ones included.
     *
     * @return list<array{insurerName: string, outstanding: int}>
     */
    public function outstandingByInsurer(Pharmacy $pharmacy): array
    {
        $rows = DB::table('declarations')
            ->join('insurers', 'insurers.id', '=', 'declarations.insurer_id')
            ->where('declarations.pharmacy_id', $pharmacy->id)
            ->select('insurers.id', 'insurers.name')
            ->selectRaw('SUM(amount_invoiced - amount_received) as outstanding')
            ->groupBy('insurers.id', 'insurers.name')
            ->get();

        $owed = $rows->mapWithKeys(fn (object $row) => [
            (int) $row->id => ['insurerName' => (string) $row->name, 'outstanding' => max(0, (int) $row->outstanding)],
        ]);

        // A ticked insurer with nothing due still belongs in the list, marked
        // as settled: its absence would read as "not declared yet".
        foreach ($pharmacy->insurers()->get(['insurers.id', 'insurers.name']) as $insurer) {
            $owed[$insurer->id] ??= ['insurerName' => $insurer->name, 'outstanding' => 0];
        }

        return array_values($owed->sortByDesc('outstanding')->all());
    }

    /**
     * Outstanding balance per declared month, with its age in days.
     *
     * Age is computed in PHP: turning "end of declared month" into elapsed days
     * depends on today's date, and writing it in SQL would tie the query to one
     * database engine's date functions.
     *
     * @return list<array{outstanding: int, age: int}>
     */
    protected function outstandingByMonth(Pharmacy $pharmacy): array
    {
        $rows = DB::table('declarations')
            ->where('pharmacy_id', $pharmacy->id)
            ->whereRaw('amount_invoiced > amount_received')
            ->select('period_year', 'period_month')
            ->selectRaw('SUM(amount_invoiced - amount_received) as outstanding')
            ->groupBy('period_year', 'period_month')
            ->get();

        $today = now()->startOfDay();

        // diffInDays() returns a float in Carbon 3; both ends are start-of-day
        // so the difference is whole, and the cast is exact rather than lossy.
        return array_values($rows->map(fn (object $row) => [
            'outstanding' => (int) $row->outstanding,
            'age' => (int) now()
                ->setDate((int) $row->period_year, (int) $row->period_month, 1)
                ->endOfMonth()
                ->startOfDay()
                ->diffInDays($today, absolute: false),
        ])->all());
    }

    /**
     * The declarations of one officine over a rolling window of months.
     */
    protected function window(Pharmacy $pharmacy, int $months): Builder
    {
        $end = now();
        $start = $end->subMonths(max(0, $months - 1));

        return DB::table('declarations')
            ->where('pharmacy_id', $pharmacy->id)
            ->whereRaw(
                '(period_year * 12 + period_month) BETWEEN ? AND ?',
                [$start->year * 12 + $start->month, $end->year * 12 + $end->month],
            );
    }
}
