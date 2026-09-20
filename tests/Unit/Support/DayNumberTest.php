<?php

use App\Support\DayNumber;
use Carbon\CarbonImmutable;

test('a day number counts whole days from the epoch', function () {
    expect(DayNumber::fromDate('1970-01-01'))->toBe(0)
        ->and(DayNumber::fromDate('1970-01-02'))->toBe(1);
});

test('dates before the epoch count backwards', function () {
    // Pin la convention de signe, pas le choix de floor() contre intdiv() :
    // sur une date seule les deux rendent le même entier, et aucun test ne
    // peut les séparer.
    expect(DayNumber::fromDate('1969-12-31'))->toBe(-1)
        ->and(DayNumber::fromDate('1969-12-30'))->toBe(-2);
});

test('the difference of two day numbers matches Carbon to the day', function () {
    $anchor = CarbonImmutable::create(2024, 1, 1);

    // Quatre ans jour par jour : deux 29 février, tous les changements de mois,
    // et des écarts dans les deux sens. C'est la propriété dont dépend tout le
    // calcul de pénalité.
    for ($offset = -400; $offset <= 1000; $offset++) {
        $other = $anchor->addDays($offset);

        expect(DayNumber::fromDate($other->format('Y-m-d')) - DayNumber::fromDate($anchor->format('Y-m-d')))
            ->toBe((int) $anchor->diffInDays($other, absolute: false));
    }
});

test('a leap day is one day after the twenty-eighth', function () {
    expect(DayNumber::fromDate('2024-02-29') - DayNumber::fromDate('2024-02-28'))->toBe(1)
        ->and(DayNumber::fromDate('2024-03-01') - DayNumber::fromDate('2024-02-29'))->toBe(1);
});

test('fromCarbon reads the calendar date, not the timestamp', function () {
    // Le projet tourne en UTC, mais si app.timezone changeait, un
    // getTimestamp() / 86400 décalerait toutes les dates d'un jour une partie
    // de l'année, sans que rien ne rougisse.
    date_default_timezone_set('Pacific/Kiritimati');

    $moment = CarbonImmutable::create(2026, 5, 1, 2, 0, 0);

    expect(DayNumber::fromCarbon($moment))->toBe(DayNumber::fromDate('2026-05-01'));

    date_default_timezone_set('UTC');
});

test('today follows the test clock', function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 20));

    expect(DayNumber::today())->toBe(DayNumber::fromDate('2026-09-20'));

    $this->travelTo(CarbonImmutable::create(2027, 1, 15));

    expect(DayNumber::today())->toBe(DayNumber::fromDate('2027-01-15'));
});

test('an unparseable date is refused rather than silently zero', function () {
    expect(fn () => DayNumber::fromDate('pas une date'))
        ->toThrow(InvalidArgumentException::class);
});

test('an empty date is refused, where strtotime would answer today', function () {
    // strtotime(' UTC') ne rend pas false : il rend maintenant. Une date nulle
    // transtypée en chaîne passerait donc pour aujourd'hui, ce qui annulerait
    // sa pénalité sans bruit.
    expect(strtotime(' UTC'))->not->toBeFalse()
        ->and(fn () => DayNumber::fromDate(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DayNumber::fromDate('   '))->toThrow(InvalidArgumentException::class);
});

test('a date carrying a time truncates down to its day', function () {
    // paid_on remonte de la base en Y-m-d H:i:s : la troncature doit tomber
    // sur le jour de la date, pas sur le suivant.
    expect(DayNumber::fromDate('2026-09-19 23:59:59'))->toBe(DayNumber::fromDate('2026-09-19'))
        ->and(DayNumber::fromDate('2026-09-19 00:00:01'))->toBe(DayNumber::fromDate('2026-09-19'));
});
