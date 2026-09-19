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

/**
 * Un mois entamé puis abandonné : un acompte encaissé, le solde jamais versé.
 *
 * `delay_days` est **dérivé**, pas forcé : syncFromInstalments() pose `paid_on`
 * dès le premier versement, donc le hook `saving` en tire un délai — court, et
 * sans rapport avec l'âge de la dette qui, elle, court toujours. Un helper qui
 * mettrait `delay_days` à null décrirait un état que la production ne produit
 * jamais, et le test passerait par une porte dérobée.
 */
function partlyPaidSince(int $ageDays, int $paidAfterDays): Declaration
{
    $deposited = CarbonImmutable::create(2026, 9, 19)->subDays($ageDays);

    $declaration = (new Declaration)->forceFill([
        'amount_invoiced' => 1_000_000,
        'amount_received' => 100_000,
        'status' => DeclarationStatus::Partial,
        'is_status_manual' => true,
        'invoice_deposited_on' => $deposited,
        'paid_on' => $deposited->addDays($paidAfterDays),
    ]);

    $declaration->delay_days = $declaration->deriveDelayDays();

    return $declaration;
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

test('a partly paid month counts its age, not the delay of its instalment', function () {
    // Déposée il y a 261 jours, un acompte reçu au 9e : la dette a 261 jours,
    // et c'est ce chiffre qui pèse en négociation — pas le 9.
    expect($this->longest->for([partlyPaidSince(261, 9)]))->toBe(261);
});

test('a partly paid month can beat every settled one', function () {
    expect($this->longest->for([settledWithDelay(48), partlyPaidSince(261, 9)]))->toBe(261);
});

test('a rejected month carrying a delay is still not a debt', function () {
    $rejected = partlyPaidSince(261, 9);
    $rejected->status = DeclarationStatus::Rejected;

    // Le délai de son acompte ne doit pas davantage entrer : PenaltyCalculator
    // exclut un mois rejeté d'entrée, et les deux classes doivent s'accorder.
    expect($this->longest->for([settledWithDelay(48), $rejected]))->toBe(48);
});
