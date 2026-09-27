<?php

use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
});

/**
 * Le formulaire du mois de référence, tel qu'il repart.
 *
 * @param  list<array{amount: int, paid_on: string}>  $payments
 * @return array<string, mixed>
 */
function monthForm(int $insurerId, array $payments, array $extra = []): array
{
    return [
        'insurer_id' => $insurerId,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-03-31',
        'payments' => $payments,
        ...$extra,
    ];
}

/**
 * Le mois de référence, déclaré une fois par le formulaire : une révision d'origine.
 */
function declaredReferenceMonth(User $user): Declaration
{
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    test()->actingAs($user)
        ->post(route('pharmacy.declare.store'), monthForm($declaration->insurer_id, referenceInstalments()))
        ->assertSessionHasNoErrors();

    return $declaration;
}

/**
 * La cellule « corrections » de l'export CSV de l'officine.
 */
function exportedCorrections(User $user): string
{
    $body = str_replace("\xEF\xBB\xBF", '', test()->actingAs($user)
        ->get(route('pharmacy.data-exports.download'))
        ->streamedContent());

    $rows = array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\n", trim($body))),
    );

    return $rows[1][array_search('corrections', $rows[0], true)];
}

test('a settlement on the insurer screen is not counted as a correction', function () {
    $user = User::factory()->create();
    $declaration = declaredReferenceMonth($user);

    $this->actingAs($user)
        ->post(route('pharmacy.penalty-settlement.store', $declaration), ['outcome' => 'paid']);

    expect($declaration->revisions()->count())->toBe(2)
        ->and($declaration->revisions()->latest('id')->first()->penalty_only)->toBeTrue()
        ->and(exportedCorrections($user))->toBe('0');

    $this->actingAs($user)
        ->get(route('pharmacy.history'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('declarations.data.0.corrections', 0));

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['insurer' => $declaration->insurer_id, 'year' => 2026, 'month' => 3]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('declaration.correctionCount', 0)
            ->where('declaration.revisions.0.penaltyOnly', true)
            ->where('declaration.revisions.0.penaltySettlement', 'paid')
            ->where('declaration.revisions.0.penaltySettlementLabel', 'Payée')
            ->where('declaration.revisions.0.penaltySettledAmount', 32_000)
            ->where('declaration.revisions.1.penaltyOnly', false)
            ->where('declaration.revisions.1.penaltySettlement', null));
});

test('putting the penalty back as due is not a correction either', function () {
    $user = User::factory()->create();
    $declaration = declaredReferenceMonth($user);

    $this->actingAs($user)->post(route('pharmacy.penalty-settlement.store', $declaration), ['outcome' => 'waived']);
    $this->actingAs($user)->delete(route('pharmacy.penalty-settlement.destroy', $declaration));

    expect($declaration->revisions()->count())->toBe(3)
        ->and($declaration->revisions()->latest('id')->first()->penalty_only)->toBeTrue()
        ->and(exportedCorrections($user))->toBe('0');
});

test('a figure correction still counts as one', function () {
    $user = User::factory()->create();
    $declaration = declaredReferenceMonth($user);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), monthForm($declaration->insurer_id, [
            ['amount' => 400_000, 'paid_on' => '2026-05-25'],
            ['amount' => 600_000, 'paid_on' => '2026-07-20'],
        ]));

    expect($declaration->revisions()->latest('id')->first()->penalty_only)->toBeFalse()
        ->and(exportedCorrections($user))->toBe('1');

    $this->actingAs($user)
        ->get(route('pharmacy.history'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('declarations.data.0.corrections', 1));

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['insurer' => $declaration->insurer_id, 'year' => 2026, 'month' => 3]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('declaration.correctionCount', 1));
});

test('the revision after a closure from the form carries the settlement', function () {
    $user = User::factory()->create();
    $declaration = declaredReferenceMonth($user);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), monthForm($declaration->insurer_id, referenceInstalments(), [
            'penalty_settlement' => 'paid',
            'penalty_settlement_shown' => 'due',
        ]))
        ->assertSessionHasNoErrors();

    $revision = $declaration->revisions()->latest('id')->first();

    expect($declaration->revisions()->count())->toBe(2)
        ->and($revision->penalty_settlement)->toBe(PenaltySettlement::Paid)
        ->and($revision->penalty_settled_amount)->toBe(32_000)
        ->and($revision->penalty_only)->toBeTrue();
});

test('the first revision is never penalty-only, even on a month already closed', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);
    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), monthForm($declaration->insurer_id, referenceInstalments()));

    expect($declaration->revisions()->sole()->penalty_only)->toBeFalse();
});
