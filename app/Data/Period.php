<?php

namespace App\Data;

readonly class Period
{
    public function __construct(
        public int $year,
        public int $month,
    ) {
        //
    }

    /**
     * Build the inclusive bounds of the last $count months, ending this month.
     *
     * @return array{0: self, 1: self}
     */
    public static function lastMonths(int $count): array
    {
        $end = now();
        $start = $end->subMonths(max(0, $count - 1));

        return [
            new self($start->year, $start->month),
            new self($end->year, $end->month),
        ];
    }

    /**
     * Build the inclusive bounds of the calendar quarter containing today.
     *
     * @return array{0: self, 1: self}
     */
    public static function currentQuarter(): array
    {
        $now = now();
        $firstMonth = (intdiv($now->month - 1, 3) * 3) + 1;

        return [
            new self($now->year, $firstMonth),
            new self($now->year, $firstMonth + 2),
        ];
    }

    /**
     * Express the period as a single sortable integer.
     *
     * Comparing (year, month) pairs in SQL is awkward; this collapses them to
     * one monotonic value so a range filter is a plain BETWEEN.
     */
    public function toOrdinal(): int
    {
        return $this->year * 12 + $this->month;
    }
}
