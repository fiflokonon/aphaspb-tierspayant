<?php

use App\Rules\DeclarablePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

function validatePeriod(int $year, int $month): bool
{
    return Validator::make(
        ['period' => [$year, $month]],
        ['period' => new DeclarablePeriod],
    )->passes();
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::create(2026, 8, 15)));

test('the current month is declarable', function () {
    expect(validatePeriod(2026, 8))->toBeTrue();
});

test('the twelfth month back is still declarable', function () {
    expect(validatePeriod(2025, 8))->toBeTrue();
});

test('the thirteenth month back is refused', function () {
    expect(validatePeriod(2025, 7))->toBeFalse();
});

test('a future month is refused', function () {
    expect(validatePeriod(2026, 9))->toBeFalse()
        ->and(validatePeriod(2027, 1))->toBeFalse();
});

test('a month outside one to twelve is refused', function () {
    expect(validatePeriod(2026, 0))->toBeFalse()
        ->and(validatePeriod(2026, 13))->toBeFalse();
});
