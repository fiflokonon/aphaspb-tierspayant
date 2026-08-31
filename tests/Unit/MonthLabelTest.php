<?php

use App\Support\MonthLabel;

test('the short form abbreviates the month and clips the year', function () {
    expect(MonthLabel::short(8, 2026))->toBe('Août 26')
        ->and(MonthLabel::short(1, 2025))->toBe('Janv. 25')
        ->and(MonthLabel::short(12, 2026))->toBe('Déc. 26');
});

test('the long form shouts the month and keeps the full year', function () {
    expect(MonthLabel::long(8, 2026))->toBe('AOÛT 2026')
        ->and(MonthLabel::long(2, 2026))->toBe('FÉVRIER 2026')
        ->and(MonthLabel::long(12, 2026))->toBe('DÉCEMBRE 2026');
});
