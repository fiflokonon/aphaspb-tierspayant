<?php

use App\Data\Period;
use App\Rules\DeclarablePeriod;
use App\Services\Settings\SettingsRepository;
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

test('until the admin sets it, the floor freezes twelve months back', function () {
    expect(validatePeriod(2025, 8))->toBeTrue()
        ->and(validatePeriod(2025, 7))->toBeFalse();

    // Un an plus tard, le plancher n'a pas glissé.
    $this->travelTo(CarbonImmutable::create(2027, 8, 15));

    expect(validatePeriod(2025, 8))->toBeTrue();
});

test('a month below the floor the admin sets is refused, with the floor named', function () {
    app(SettingsRepository::class)->set(SettingsRepository::DECLARATION_FLOOR, (new Period(2024, 3))->toOrdinal());

    $validator = Validator::make(['period' => [2024, 2]], ['period' => new DeclarablePeriod]);

    expect(validatePeriod(2024, 3))->toBeTrue()
        ->and($validator->errors()->first('period'))->toBe('Les déclarations ne remontent pas avant mars 2024.');
});

test('a future month is refused', function () {
    expect(validatePeriod(2026, 9))->toBeFalse()
        ->and(validatePeriod(2027, 1))->toBeFalse();
});

test('a month outside one to twelve is refused', function () {
    expect(validatePeriod(2026, 0))->toBeFalse()
        ->and(validatePeriod(2026, 13))->toBeFalse();
});
