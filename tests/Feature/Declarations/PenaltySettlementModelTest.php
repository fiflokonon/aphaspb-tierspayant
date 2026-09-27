<?php

use App\Actions\Declarations\RecordDeclarationRevision;
use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
});

test('settling a penalty sets the four columns together, clearing unsets them together', function () {
    $user = User::factory()->create();
    $declaration = Declaration::factory()->create(['amount_invoiced' => 1_000_000, 'amount_received' => 1_000_000]);

    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);
    $fresh = $declaration->fresh();

    expect($fresh->penalty_settlement)->toBe(PenaltySettlement::Paid)
        ->and($fresh->penalty_settled_amount)->toBe(32_000)
        ->and($fresh->penalty_settled_on->toDateString())->toBe('2026-08-05')
        ->and($fresh->penalty_settled_by)->toBe($user->id)
        ->and($fresh->isPenaltySettled())->toBeTrue();

    $fresh->clearPenaltySettlement();

    expect($fresh->fresh()->only(['penalty_settlement', 'penalty_settled_amount', 'penalty_settled_on', 'penalty_settled_by']))
        ->toBe(['penalty_settlement' => null, 'penalty_settled_amount' => null, 'penalty_settled_on' => null, 'penalty_settled_by' => null]);
});

test('a settlement is part of the revision snapshot', function () {
    $user = User::factory()->create();
    $declaration = Declaration::factory()->create(['amount_invoiced' => 1_000_000, 'amount_received' => 1_000_000]);
    $revisions = app(RecordDeclarationRevision::class);

    $revisions->handle($declaration->load('payments'), $user);
    $declaration->settlePenalty(PenaltySettlement::Waived, 12_000, $user);

    expect($revisions->handle($declaration->fresh()->load('payments'), $user))->not->toBeNull()
        ->and($revisions->handle($declaration->fresh()->load('payments'), $user))->toBeNull()
        ->and($declaration->revisions()->latest('id')->first()->penalty_settlement)->toBe(PenaltySettlement::Waived);
});

test('a settlement cannot be mass-assigned', function () {
    $declaration = Declaration::factory()->create();

    $declaration->fill(['penalty_settlement' => 'paid', 'penalty_settled_amount' => 1])->save();

    expect($declaration->fresh()->penalty_settlement)->toBeNull();
});

test('a month is fully covered only when nothing is left to receive', function () {
    expect(Declaration::factory()->make(['amount_invoiced' => 100, 'amount_received' => 100])->isFullyCovered())->toBeTrue()
        ->and(Declaration::factory()->make(['amount_invoiced' => 100, 'amount_received' => 99])->isFullyCovered())->toBeFalse();
});
