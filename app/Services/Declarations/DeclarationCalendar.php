<?php

namespace App\Services\Declarations;

use App\Data\Period;
use App\Models\NilDeclaration;
use App\Models\Pharmacy;
use App\Support\MonthLabel;
use Illuminate\Support\Facades\DB;

/**
 * Which months an officine may still declare, and which ones it owes.
 *
 * The server has always accepted a past month — the declaration screen reads
 * year and month from the query string, and DeclarablePeriod allows any down
 * to the admin's floor — but nothing in the interface ever built that link, so a missed month
 * was unreachable and the date fields of the current month refused anything
 * older. This is the list both ways in are drawn from.
 *
 * A month counts as owed when fewer insurers are answered — declared, or
 * reported as having no invoice — than the officine has ticked — and when it
 * falls among the months expected of it (DeclarationRange::expectedFrom()).
 * That answer is read for every declarable month at once, in one grouped
 * query rather than one MonthlyDeclarationRun per month: the dashboard asks
 * for it on every load.
 */
class DeclarationCalendar
{
    public function __construct(protected DeclarationRange $range)
    {
        //
    }

    /**
     * Every declarable month, from the current one down to the floor.
     *
     * @return list<array{year: int, month: int, label: string, isComplete: bool, isCurrent: bool, remaining: int}>
     */
    public function months(Pharmacy $pharmacy): array
    {
        $expected = $pharmacy->insurers()->count();
        $declared = $this->declaredCounts($pharmacy);

        $current = $this->range->current()->toOrdinal();
        $floor = $this->range->floor()->toOrdinal();
        $months = [];

        for ($ordinal = $current; $ordinal >= $floor; $ordinal--) {
            $period = Period::fromOrdinal($ordinal);
            $year = $period->year;
            $month = $period->month;

            $months[] = [
                'year' => $year,
                'month' => $month,
                'label' => MonthLabel::long($month, $year),
                'isComplete' => $expected > 0 && ($declared[$ordinal] ?? 0) >= $expected,
                'isCurrent' => $ordinal === $current,
                // Insurers still without an answer: the dashboard offers to
                // report them all as having no invoice in one gesture.
                'remaining' => max(0, $expected - ($declared[$ordinal] ?? 0)),
            ];
        }

        return $months;
    }

    /**
     * The months still owed, oldest first — the current one excluded.
     *
     * The month in progress is not late; it is what the dashboard's own
     * declare button is for, and listing it here would read as a reproach.
     * Neither is a month before the ones expected of this officine: it may
     * declare it, nobody asks it to.
     *
     * @return list<array{year: int, month: int, label: string, isComplete: bool, isCurrent: bool, remaining: int}>
     */
    public function outstanding(Pharmacy $pharmacy): array
    {
        // An officine that has ticked no insurer owes nothing rather than
        // owing every month: it has not finished its onboarding, and a
        // thirteen-month backlog is a poor way to be told so.
        if ($pharmacy->insurers()->doesntExist()) {
            return [];
        }

        $expectedFrom = $this->range->expectedFrom($pharmacy)->toOrdinal();

        return array_values(array_reverse(array_filter(
            $this->months($pharmacy),
            fn (array $month): bool => ! $month['isCurrent']
                && ! $month['isComplete']
                && (new Period($month['year'], $month['month']))->toOrdinal() >= $expectedFrom,
        )));
    }

    /**
     * How many distinct insurers are declared per month, keyed by ordinal.
     *
     * @return array<int, int>
     */
    protected function declaredCounts(Pharmacy $pharmacy): array
    {
        $earliest = $this->range->floor()->toOrdinal();

        // Counted on the query builder rather than through Eloquent: these
        // rows are an aggregate projection, not declarations.
        //
        // Joined to the insurers still ticked: a declaration for an insurer
        // the officine has since dropped does not complete a month. The
        // network's follow-up (DeclarationCompleteness) counts the same way,
        // so the officine is never chased for a month its own dashboard
        // calls done — or the other way round.
        //
        // A « pas de facture » answers a month exactly as a declaration does
        // (NilDeclaration::answeredMonths()).
        return DB::query()
            ->fromSub(NilDeclaration::answeredMonths(fn ($query) => $query
                ->where('pharmacy_id', $pharmacy->id)
                ->whereRaw('(period_year * 12 + period_month) >= ?', [$earliest])), 'answered')
            ->join('insurer_pharmacy', function ($join) {
                $join->on('insurer_pharmacy.pharmacy_id', '=', 'answered.pharmacy_id')
                    ->on('insurer_pharmacy.insurer_id', '=', 'answered.insurer_id');
            })
            ->selectRaw('answered.period_year, answered.period_month, COUNT(DISTINCT answered.insurer_id) AS declared')
            ->groupBy('answered.period_year', 'answered.period_month')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                ((int) $row->period_year * 12 + (int) $row->period_month) => (int) $row->declared,
            ])
            ->all();
    }
}
