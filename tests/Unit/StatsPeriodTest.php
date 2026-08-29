<?php

use App\Data\Period;
use App\Enums\StatsPeriod;
use Carbon\CarbonImmutable;

beforeEach(fn () => test()->travelTo(CarbonImmutable::create(2026, 8, 15)));

/**
 * @param  array{0: Period, 1: Period}  $bounds
 */
function boundsAsString(array $bounds): string
{
    return sprintf(
        '%04d-%02d → %04d-%02d',
        $bounds[0]->year, $bounds[0]->month,
        $bounds[1]->year, $bounds[1]->month,
    );
}

test('each period resolves to the bounds its label promises', function (StatsPeriod $period, string $expected) {
    expect(boundsAsString($period->bounds()))->toBe($expected);
})->with([
    'trimestre en cours' => [StatsPeriod::CurrentQuarter, '2026-07 → 2026-09'],
    'trimestre précédent' => [StatsPeriod::PreviousQuarter, '2026-04 → 2026-06'],
    'semestre en cours' => [StatsPeriod::CurrentSemester, '2026-07 → 2026-12'],
    'semestre précédent' => [StatsPeriod::PreviousSemester, '2026-01 → 2026-06'],
    '12 derniers mois' => [StatsPeriod::LastTwelveMonths, '2025-09 → 2026-08'],
    'année civile' => [StatsPeriod::CalendarYear, '2026-01 → 2026-12'],
]);

test('the previous semester crosses the year when the current one is the first', function () {
    test()->travelTo(CarbonImmutable::create(2026, 2, 3));

    expect(boundsAsString(StatsPeriod::CurrentSemester->bounds()))->toBe('2026-01 → 2026-06')
        ->and(boundsAsString(StatsPeriod::PreviousSemester->bounds()))->toBe('2025-07 → 2025-12');
});

test('the previous quarter crosses the year when the current one is the first', function () {
    test()->travelTo(CarbonImmutable::create(2026, 2, 3));

    expect(boundsAsString(StatsPeriod::PreviousQuarter->bounds()))->toBe('2025-10 → 2025-12');
});

test('every period carries a French label', function (StatsPeriod $period) {
    expect($period->label())->not->toBeEmpty()
        ->and($period->label())->not->toContain('_');
})->with(StatsPeriod::cases());

test('an unknown value falls back rather than throwing', function () {
    expect(StatsPeriod::fromRequest('rien-de-tel', StatsPeriod::CurrentQuarter))
        ->toBe(StatsPeriod::CurrentQuarter)
        ->and(StatsPeriod::fromRequest(null, StatsPeriod::LastTwelveMonths))
        ->toBe(StatsPeriod::LastTwelveMonths)
        ->and(StatsPeriod::fromRequest('current-semester', StatsPeriod::CurrentQuarter))
        ->toBe(StatsPeriod::CurrentSemester);
});

test('the description names the window in plain French', function (StatsPeriod $period, string $expected) {
    expect($period->describe())->toBe($expected);
})->with([
    [StatsPeriod::CurrentQuarter, 'juillet → septembre 2026'],
    [StatsPeriod::PreviousQuarter, 'avril → juin 2026'],
    [StatsPeriod::CurrentSemester, 'juillet → décembre 2026'],
    [StatsPeriod::PreviousSemester, 'janvier → juin 2026'],
    [StatsPeriod::LastTwelveMonths, 'septembre 2025 → août 2026'],
    [StatsPeriod::CalendarYear, 'janvier → décembre 2026'],
]);
