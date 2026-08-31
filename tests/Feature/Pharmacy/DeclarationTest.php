<?php

use App\Enums\DeclarationStatus;
use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
});

/**
 * An officine working with $count named insurers, in a known order.
 *
 * @return array{0: User, 1: Collection<int, Insurer>}
 */
function officineWith(int $count): array
{
    $user = User::factory()->notOnboarded()->create();

    $pharmacy = Pharmacy::factory()->create();
    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);
    $user->switchPharmacy($pharmacy);

    // Names are unique across the table, so they are scoped by pharmacy id.
    // Within one officine they stay alphabetical, which is the wizard's order.
    $insurers = collect(range(1, $count))->map(
        fn (int $i) => Insurer::factory()->create([
            'name' => sprintf('Officine %d · Assureur %02d', $pharmacy->id, $i),
        ]),
    );

    $pharmacy->insurers()->attach($insurers->pluck('id'));

    return [$user->fresh(), $insurers];
}

/** @return array<string, mixed> */
function declarationPayload(Insurer $insurer, array $overrides = []): array
{
    return [
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_240_000,
        'amount_received' => 860_000,
        'invoice_deposited_on' => '2026-08-01',
        'paid_on' => '2026-08-12',
        ...$overrides,
    ];
}

test('the screen shows the first insurer not yet declared this month', function () {
    [$user, $insurers] = officineWith(3);

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Declare')
            ->where('insurer.id', $insurers[0]->id)
            ->where('progress.current', 1)
            ->where('progress.total', 3)
            ->where('period.year', 2026)
            ->where('period.month', 8),
        );
});

test('the progress counts ticked insurers, not every insurer in the table', function () {
    [$user] = officineWith(2);
    Insurer::factory()->count(5)->create();

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('progress.total', 2));
});

test('saving advances to the next insurer', function () {
    [$user, $insurers] = officineWith(3);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]))
        ->assertRedirect(route('pharmacy.declare'));

    $this->actingAs($user->fresh())
        ->get(route('pharmacy.declare'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('insurer.id', $insurers[1]->id)
            ->where('progress.current', 2),
        );
});

test('an officine coming back later resumes where it stopped', function () {
    [$user, $insurers] = officineWith(3);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('insurer.id', $insurers[1]->id));
});

test('the done screen appears once every insurer is declared', function () {
    [$user, $insurers] = officineWith(2);

    foreach ($insurers as $insurer) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/DeclareDone')
            ->where('declared', 2),
        );
});

test('an insurer can be revisited to be corrected', function () {
    [$user, $insurers] = officineWith(3);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 500_000,
        'amount_received' => 500_000,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['insurer' => $insurers[0]->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('insurer.id', $insurers[0]->id)
            ->where('declaration.amount_invoiced', 500_000),
        );
});

test('two amounts are enough and the status is derived', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));

    $declaration = Declaration::query()->sole();

    expect($declaration->status)->toBe(DeclarationStatus::Partial)
        ->and($declaration->is_status_manual)->toBeFalse()
        ->and($declaration->amount_outstanding)->toBe(380_000);
});

test('receiving more than was invoiced is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_received' => 2_000_000,
        ]))
        ->assertSessionHasErrors('amount_received');
});

test('a negative or non numeric amount is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_invoiced' => -5,
        ]))
        ->assertSessionHasErrors('amount_invoiced');

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_invoiced' => 'beaucoup',
        ]))
        ->assertSessionHasErrors('amount_invoiced');
});

test('both dates are required when something was received', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'invoice_deposited_on' => null,
            'paid_on' => null,
        ]))
        ->assertSessionHasErrors(['invoice_deposited_on', 'paid_on']);
});

test('the delay is computed from the two dates, never submitted', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'invoice_deposited_on' => '2026-08-02',
        'paid_on' => '2026-08-13',
        // Smuggled in: the client has no say over the delay any more.
        'delay_days' => 3,
    ]));

    expect(Declaration::query()->sole()->delay_days)->toBe(11);
});

test('a payment date before the deposit date is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'invoice_deposited_on' => '2026-08-10',
            'paid_on' => '2026-08-02',
        ]))
        ->assertSessionHasErrors('paid_on');
});

test('a date in the future is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'paid_on' => '2026-08-16',
        ]))
        ->assertSessionHasErrors('paid_on');
});

test('a deposit date before the declared month is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'invoice_deposited_on' => '2026-07-31',
            'paid_on' => '2026-08-05',
        ]))
        ->assertSessionHasErrors('invoice_deposited_on');
});

test('a payment date is refused when nothing was received', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_received' => 0,
        ]))
        ->assertSessionHasErrors('paid_on');
});

test('choosing rejected explicitly is kept and survives a resave', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'amount_received' => 0,
        'paid_on' => null,
        'status' => 'rejected',
    ]));

    $declaration = Declaration::query()->sole();

    expect($declaration->status)->toBe(DeclarationStatus::Rejected)
        ->and($declaration->is_status_manual)->toBeTrue();

    $declaration->update(['amount_received' => 1_240_000]);

    expect($declaration->fresh()->status)->toBe(DeclarationStatus::Rejected);
});

test('a private note longer than 150 characters is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'private_note' => str_repeat('a', 151),
        ]))
        ->assertSessionHasErrors('private_note');
});

test('declaring the same insurer twice updates instead of duplicating', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));
    $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'amount_received' => 1_240_000,
        'paid_on' => '2026-08-14',
    ]));

    expect(Declaration::query()->count())->toBe(1)
        ->and(Declaration::query()->sole()->status)->toBe(DeclarationStatus::Paid);
});

test('a period beyond twelve months back, or in the future, is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'period_year' => 2025, 'period_month' => 7,
        ]))
        ->assertSessionHasErrors('period');

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'period_year' => 2026, 'period_month' => 9,
        ]))
        ->assertSessionHasErrors('period');
});

test('an officine cannot declare for an insurer it has not ticked', function () {
    [$user] = officineWith(1);
    $stranger = Insurer::factory()->create();

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($stranger))
        ->assertSessionHasErrors('insurer_id');
});

test('an officine cannot declare for another officine', function () {
    [$user, $insurers] = officineWith(1);
    [$other] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));

    expect(Declaration::query()->sole()->pharmacy_id)->toBe($user->currentPharmacy->id)
        ->and(Declaration::query()->sole()->pharmacy_id)->not->toBe($other->currentPharmacy->id);
});

test('an admin account cannot reach the declaration screen', function () {
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('pharmacy.declare'))
        ->assertForbidden();
});

test('a private note recorded here never reaches the admin space', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'private_note' => 'motif absence ordonnance accentuée',
    ]));

    $props = inertiaPropsJson(
        $this->actingAs(User::factory()->networkAdmin()->create())
            ->get(route('admin.network')),
    );

    expect($props)->not->toContain('motif absence ordonnance accentuée')
        ->and($props)->not->toContain('private_note')
        ->and($props)->not->toContain('privateNote');
});

test('a declaration without a deposit date is refused, even unpaid', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_received' => 0,
            'paid_on' => null,
            'invoice_deposited_on' => null,
        ]))
        ->assertSessionHasErrors('invoice_deposited_on');
});
