<?php

use App\Enums\PenaltySettlement;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
});

test('the officine marks a penalty paid, with a revision and a toast', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);

    $this->actingAs($user)
        ->from('/pharmacy/insurers/'.$declaration->insurer_id)
        ->post(route('pharmacy.penalty-settlement.store', $declaration), ['outcome' => 'paid'])
        ->assertRedirect('/pharmacy/insurers/'.$declaration->insurer_id)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pénalité de Mars 26 marquée payée.']);

    expect($declaration->fresh()->penalty_settlement)->toBe(PenaltySettlement::Paid)
        ->and($declaration->revisions()->count())->toBe(1);
});

test('a refused settlement says why', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user, [['amount' => 400_000, 'paid_on' => '2026-06-15']]);

    $this->actingAs($user)
        ->post(route('pharmacy.penalty-settlement.store', $declaration), ['outcome' => 'waived'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Le mois doit être entièrement réglé avant de clore sa pénalité.']);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('another officine\'s declaration is not found', function () {
    $declaration = referenceMonth(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('pharmacy.penalty-settlement.store', $declaration), ['outcome' => 'paid'])
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->delete(route('pharmacy.penalty-settlement.destroy', $declaration))
        ->assertNotFound();
});

test('an unknown outcome is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('pharmacy.penalty-settlement.store', referenceMonth($user)), ['outcome' => 'forgiven'])
        ->assertSessionHasErrors('outcome');
});

test('putting a penalty back as due', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);

    $this->actingAs($user)
        ->delete(route('pharmacy.penalty-settlement.destroy', $declaration))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pénalité de Mars 26 remise en dû.']);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});
