<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Services\Declarations\LongestDelay;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->longest = new LongestDelay;
});

/**
 * Un mois réglé, avec le délai voulu.
 *
 * Construit à la main plutôt que par la fabrique : ces tests vivent dans
 * tests/Unit, qui n'a pas RefreshDatabase.
 */
function settledWithDelay(int $delayDays): Declaration
{
    return (new Declaration)->forceFill([
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'status' => DeclarationStatus::Paid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-01-01',
        'paid_on' => CarbonImmutable::create(2026, 1, 1)->addDays($delayDays),
        'delay_days' => $delayDays,
    ]);
}

/** Un mois encore dû, déposé il y a $ageDays jours. */
function openSince(int $ageDays): Declaration
{
    return (new Declaration)->forceFill([
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 9, 19)->subDays($ageDays),
        'paid_on' => null,
        'delay_days' => null,
    ]);
}

test('nothing declared yields no delay', function () {
    expect($this->longest->for([]))->toBeNull();
});

test('only settled months yields the worst settled delay', function () {
    expect($this->longest->for([settledWithDelay(12), settledWithDelay(48)]))->toBe(48);
});

test('only open months yields the age of the oldest', function () {
    expect($this->longest->for([openSince(30), openSince(210)]))->toBe(210);
});

test('an open month can beat every settled one', function () {
    expect($this->longest->for([settledWithDelay(48), openSince(400)]))->toBe(400);
});

test('a settled month can beat every open one', function () {
    expect($this->longest->for([settledWithDelay(300), openSince(20)]))->toBe(300);
});

test('a rejected month is not an open debt', function () {
    $rejected = openSince(400);
    $rejected->status = DeclarationStatus::Rejected;

    expect($this->longest->for([settledWithDelay(48), $rejected]))->toBe(48);
});

test('an open month never deposited has no age', function () {
    $undeposited = openSince(400);
    $undeposited->invoice_deposited_on = null;

    expect($this->longest->for([$undeposited]))->toBeNull();
});

test('a partly paid month still counts as an open debt', function () {
    $partial = openSince(150);
    $partial->amount_received = 400_000;
    $partial->status = DeclarationStatus::Partial;

    expect($this->longest->for([$partial]))->toBe(150);
});
