<?php

namespace App\Services\Declarations;

use App\Models\Declaration;
use App\Models\Pharmacy;
use App\Support\MonthLabel;

/**
 * Which months an officine may still declare, and which ones it owes.
 *
 * The server has always accepted a past month — the declaration screen reads
 * year and month from the query string, and DeclarablePeriod allows twelve
 * back — but nothing in the interface ever built that link, so a missed month
 * was unreachable and the date fields of the current month refused anything
 * older. This is the list both ways in are drawn from.
 *
 * A month counts as owed when fewer insurers are declared than the officine
 * has ticked. That answer is read for thirteen months at once, in one grouped
 * query rather than thirteen MonthlyDeclarationRun instances: the dashboard
 * asks for it on every load.
 */
class DeclarationCalendar
{
    /**
     * The current month and the twelve before it, newest first.
     *
     * @return list<array{year: int, month: int, label: string, isComplete: bool, isCurrent: bool}>
     */
    public function months(Pharmacy $pharmacy): array
    {
        $expected = $pharmacy->insurers()->count();
        $declared = $this->declaredCounts($pharmacy);

        $now = now();
        $current = $now->year * 12 + $now->month;
        $months = [];

        for ($ordinal = $current; $ordinal >= $current - Declaration::EARLIEST_MONTHS_BACK; $ordinal--) {
            $year = intdiv($ordinal - 1, 12);
            $month = $ordinal - ($year * 12);

            $months[] = [
                'year' => $year,
                'month' => $month,
                'label' => MonthLabel::long($month, $year),
                'isComplete' => $expected > 0 && ($declared[$ordinal] ?? 0) >= $expected,
                'isCurrent' => $ordinal === $current,
            ];
        }

        return $months;
    }

    /**
     * The months still owed, oldest first — the current one excluded.
     *
     * The month in progress is not late; it is what the dashboard's own
     * declare button is for, and listing it here would read as a reproach.
     *
     * @return list<array{year: int, month: int, label: string, isComplete: bool, isCurrent: bool}>
     */
    public function outstanding(Pharmacy $pharmacy): array
    {
        // An officine that has ticked no insurer owes nothing rather than
        // owing every month: it has not finished its onboarding, and a
        // thirteen-month backlog is a poor way to be told so.
        if ($pharmacy->insurers()->doesntExist()) {
            return [];
        }

        return array_values(array_reverse(array_filter(
            $this->months($pharmacy),
            fn (array $month): bool => ! $month['isCurrent'] && ! $month['isComplete'],
        )));
    }

    /**
     * How many distinct insurers are declared per month, keyed by ordinal.
     *
     * @return array<int, int>
     */
    protected function declaredCounts(Pharmacy $pharmacy): array
    {
        $now = now();
        $earliest = ($now->year * 12 + $now->month) - Declaration::EARLIEST_MONTHS_BACK;

        // Counted on the query builder rather than through Eloquent: these
        // rows are an aggregate projection, not declarations. The model
        // carries no global scope, so nothing is lost by stepping past it.
        return Declaration::query()
            ->toBase()
            ->where('pharmacy_id', $pharmacy->id)
            ->selectRaw('period_year, period_month, COUNT(DISTINCT insurer_id) AS declared')
            ->whereRaw('(period_year * 12 + period_month) >= ?', [$earliest])
            ->groupBy('period_year', 'period_month')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                ((int) $row->period_year * 12 + (int) $row->period_month) => (int) $row->declared,
            ])
            ->all();
    }
}
