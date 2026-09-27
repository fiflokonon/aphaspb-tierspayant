<?php

use App\Enums\PenaltySettlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
});

/**
 * Le formulaire tel qu'il repart pour le mois de référence.
 *
 * @param  list<array{amount: int, paid_on: string}>  $payments
 * @return array<string, mixed>
 */
function referencePayload(int $insurerId, array $payments, ?string $settlement = null, ?string $shown = null): array
{
    return array_filter([
        'insurer_id' => $insurerId,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-03-31',
        'payments' => $payments,
        'penalty_settlement' => $settlement,
        'penalty_settlement_shown' => $shown,
    ], fn ($value) => $value !== null);
}

test('the last payment and « paid » in one save close the penalty', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user, [['amount' => 400_000, 'paid_on' => '2026-06-15']]);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'paid'))
        ->assertSessionHasNoErrors();

    expect($declaration->fresh()->penalty_settlement)->toBe(PenaltySettlement::Paid)
        ->and($declaration->fresh()->penalty_settled_amount)->toBe(32_000);
});

test('« paid » on a month still open is ignored, with the reason', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user, [['amount' => 400_000, 'paid_on' => '2026-06-15']]);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, [['amount' => 400_000, 'paid_on' => '2026-06-15']], 'paid'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Pénalité non close : le mois doit être entièrement réglé avant de clore sa pénalité.']);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('an unchanged choice does not re-settle a penalty the correction just reopened', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);
    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);

    // Cas B : le formulaire repart pré-rempli sur « payée ».
    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, [
            ['amount' => 400_000, 'paid_on' => '2026-05-25'],
            ['amount' => 600_000, 'paid_on' => '2026-07-20'],
        ], 'paid', 'paid'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'warning', 'message' => "La pénalité de Mars 26 (NSIA) n'est plus close : son montant a changé (24\u{202F}000 F au lieu de 32\u{202F}000 F)."]);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('« due » on a settled month puts it back as due', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);
    $declaration->settlePenalty(PenaltySettlement::Waived, 32_000, $user);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'due', 'waived'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pénalité de Mars 26 remise en dû.']);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('a stale « due » form does not undo a closure made elsewhere', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    // Le formulaire a affiché « due » ; entre-temps, l'écran assureur a clos.
    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'due', 'due'))
        ->assertSessionHasNoErrors();

    expect($declaration->fresh()->penalty_settlement)->toBe(PenaltySettlement::Paid);
});

test('a stale « paid » form does not re-close a month put back as due elsewhere', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    // Le formulaire a affiché « payée » ; entre-temps, un collègue l'a remise en dû.
    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'paid', 'paid'))
        ->assertSessionHasNoErrors();

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('the settlement choice of the form is confirmed by a toast', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'waived', 'due'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pénalité de Mars 26 marquée annulée.']);

    expect($declaration->fresh()->penalty_settlement)->toBe(PenaltySettlement::Waived);
});

test('a shown state outside the three is rejected', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'paid', 'forgiven'))
        ->assertSessionHasErrors('penalty_settlement_shown');
});

test('the form knows the penalty of the month', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['insurer' => $declaration->insurer_id, 'year' => 2026, 'month' => 3]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('declaration.penalty.accrued', 32_000)
            ->where('declaration.penalty.covered', true)
            ->where('declaration.penalty.settlement', null));
});

test('a form choice already applied elsewhere says so instead of claiming a change', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    // Le formulaire affichait « due » ; un collègue a clos « payée » entre-temps.
    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'paid', 'due'))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'La pénalité de Mars 26 était déjà marquée payée.']);
});

test('a form « due » on a closure already lifted elsewhere says so', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    // Le formulaire affichait « payée » ; la clôture a été levée entre-temps.
    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'due', 'paid'))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => "La pénalité de Mars 26 n'était pas close."]);
});
