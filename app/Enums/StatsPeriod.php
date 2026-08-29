<?php

namespace App\Enums;

use App\Data\Period;

/**
 * The window the admin screens are read against.
 *
 * One vocabulary for the three of them: statistics, trends and the CSV export
 * are compared with each other, and two screens offering different periods
 * would invite reading non-comparable figures as if they matched.
 *
 * The cases live here rather than as static methods on Period because every
 * screen has to enumerate them to fill its selector — this is the single place
 * the list exists.
 */
enum StatsPeriod: string
{
    case CurrentQuarter = 'current-quarter';
    case PreviousQuarter = 'previous-quarter';
    case CurrentSemester = 'current-semester';
    case PreviousSemester = 'previous-semester';
    case LastTwelveMonths = 'last-12-months';
    case CalendarYear = 'calendar-year';

    /**
     * Resolve a value from the query string, falling back rather than failing.
     *
     * A hand-edited or stale URL must not 500 an admin out of a report.
     */
    public static function fromRequest(?string $value, self $fallback): self
    {
        return self::tryFrom((string) $value) ?? $fallback;
    }

    /**
     * The cases as a selector expects them, in declaration order.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $period): array => ['value' => $period->value, 'label' => $period->label()],
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::CurrentQuarter => 'Trimestre en cours',
            self::PreviousQuarter => 'Trimestre précédent',
            self::CurrentSemester => 'Semestre en cours',
            self::PreviousSemester => 'Semestre précédent',
            self::LastTwelveMonths => '12 derniers mois',
            self::CalendarYear => 'Année civile',
        };
    }

    /**
     * The window spelled out, so a screen title states what it is showing.
     *
     * One rule for every case rather than a phrasing per period: « 3ᵉ trimestre
     * 2026 » reads well but has to be special-cased six times, and the moment a
     * case is added someone forgets one.
     */
    public function describe(): string
    {
        [$from, $to] = $this->bounds();

        $months = [
            'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
        ];

        if ($from->year === $to->year && $from->month === $to->month) {
            return "{$months[$from->month - 1]} {$from->year}";
        }

        $start = $from->year === $to->year
            ? $months[$from->month - 1]
            : "{$months[$from->month - 1]} {$from->year}";

        return "{$start} → {$months[$to->month - 1]} {$to->year}";
    }

    /**
     * The inclusive month bounds this period covers.
     *
     * A calendar year runs January to December: months still to come simply
     * contribute nothing, which is more predictable than a window that shrinks
     * as the year goes.
     *
     * @return array{0: Period, 1: Period}
     */
    public function bounds(): array
    {
        $now = now();

        return match ($this) {
            self::CurrentQuarter => Period::currentQuarter(),
            self::PreviousQuarter => $this->quarterEnding($now->subMonths(3)),
            self::CurrentSemester => $this->semesterOf($now->year, $now->month),
            self::PreviousSemester => $now->month <= 6
                ? $this->semesterOf($now->year - 1, 7)
                : $this->semesterOf($now->year, 1),
            self::LastTwelveMonths => Period::lastMonths(12),
            self::CalendarYear => [new Period($now->year, 1), new Period($now->year, 12)],
        };
    }

    /**
     * The calendar quarter containing the given moment.
     *
     * @return array{0: Period, 1: Period}
     */
    protected function quarterEnding(\DateTimeInterface $moment): array
    {
        $month = (int) $moment->format('n');
        $first = (intdiv($month - 1, 3) * 3) + 1;
        $year = (int) $moment->format('Y');

        return [new Period($year, $first), new Period($year, $first + 2)];
    }

    /**
     * The calendar semester containing the given month.
     *
     * @return array{0: Period, 1: Period}
     */
    protected function semesterOf(int $year, int $month): array
    {
        $first = $month <= 6 ? 1 : 7;

        return [new Period($year, $first), new Period($year, $first + 5)];
    }
}
