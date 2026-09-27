<?php

use App\Actions\Declarations\RecordPaymentInstalments;
use App\Actions\Declarations\SettlePenalty;
use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
    $this->user = User::factory()->create();
    $this->settle = app(SettlePenalty::class);
});

test('a covered month with a penalty can be marked paid, at the computed amount', function () {
    $declaration = referenceMonth($this->user);

    expect($this->settle->settle($declaration, PenaltySettlement::Paid, $this->user))->toBeTrue()
        ->and($declaration->fresh()->penalty_settled_amount)->toBe(32_000);
});

test('an uncovered month cannot be settled', function () {
    $declaration = referenceMonth($this->user, [['amount' => 400_000, 'paid_on' => '2026-06-15']]);

    expect($this->settle->refusal($declaration))->toBe('Le mois doit être entièrement réglé avant de clore sa pénalité.')
        ->and($this->settle->settle($declaration, PenaltySettlement::Paid, $this->user))->toBeFalse()
        ->and($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('a month without penalty or without clause cannot be settled', function () {
    $noClause = referenceMonth($this->user);
    $noClause->insurer->forceFill(['penalty_trigger_days' => null, 'penalty_rate_bp' => null])->save();

    $onTime = referenceMonth(User::factory()->create(), [['amount' => 1_000_000, 'paid_on' => '2026-04-10']], 'SUNU Assurances');

    expect($this->settle->refusal($noClause->fresh(['insurer', 'payments'])))->toBe("Cet assureur n'a pas de clause de pénalité.")
        ->and($this->settle->refusal($onTime))->toBe("Aucune pénalité n'a couru sur ce mois.");
});

test('settling twice the same way changes nothing', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);
    $this->travelTo(CarbonImmutable::create(2026, 8, 20));

    expect($this->settle->settle($declaration->fresh(['insurer', 'payments']), PenaltySettlement::Paid, $this->user))->toBeFalse()
        ->and($declaration->fresh()->penalty_settled_on->toDateString())->toBe('2026-08-05');
});

test('case A — a correction that reopens the month lifts the settlement', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);

    $reopened = app(RecordPaymentInstalments::class)->handle($declaration->fresh(), [
        ['amount' => 400_000, 'paid_on' => '2026-06-15'],
        ['amount' => 500_000, 'paid_on' => '2026-07-20'],
    ]);

    expect($reopened?->reason)->toBe('uncovered')
        ->and($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('case B — a correction that changes the amount lifts the settlement', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);

    $reopened = app(RecordPaymentInstalments::class)->handle($declaration->fresh(), [
        ['amount' => 400_000, 'paid_on' => '2026-05-25'],
        ['amount' => 600_000, 'paid_on' => '2026-07-20'],
    ]);

    expect($reopened?->reason)->toBe('amountChanged')
        ->and($reopened?->previousAmount)->toBe(32_000)
        ->and($reopened?->currentAmount)->toBe(24_000)
        ->and($reopened?->message('Mars 26', 'NSIA'))->toBe("La pénalité de Mars 26 (NSIA) n'est plus close : son montant a changé (24\u{202F}000 F au lieu de 32\u{202F}000 F).");
});

test('an identical re-save keeps the settlement', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);

    $reopened = app(RecordPaymentInstalments::class)->handle($declaration->fresh(), [
        ['amount' => 400_000, 'paid_on' => '2026-06-15'],
        ['amount' => 600_000, 'paid_on' => '2026-07-20'],
    ]);

    expect($reopened)->toBeNull()->and($declaration->fresh()->isPenaltySettled())->toBeTrue();
});

test('rejecting a settled month reopens its penalty', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);
    $declaration->forceFill(['status' => DeclarationStatus::Rejected, 'is_status_manual' => true])->save();

    $reopened = app(RecordPaymentInstalments::class)->handle($declaration->fresh(), [
        ['amount' => 400_000, 'paid_on' => '2026-06-15'],
        ['amount' => 600_000, 'paid_on' => '2026-07-20'],
    ]);

    expect($reopened?->reason)->toBe('amountChanged')->and($reopened?->currentAmount)->toBeNull();
});

test('reopening lifts a settlement, and says when there was none', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Waived, $this->user);

    expect($this->settle->reopen($declaration->fresh()))->toBeTrue()
        ->and($this->settle->reopen($declaration->fresh()))->toBeFalse();
});
