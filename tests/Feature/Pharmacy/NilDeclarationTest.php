<?php

use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\NilDeclaration;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Declarations\DeclarationCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
});

/**
 * An officine working with $count insurers, in the wizard's alphabetical order.
 *
 * @return array{0: User, 1: Pharmacy, 2: Collection<int, Insurer>}
 */
function officineWithoutInvoices(int $count): array
{
    $user = User::factory()->notOnboarded()->create(['name' => 'Awa Dossou']);

    $pharmacy = Pharmacy::factory()->create();
    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);
    $user->switchPharmacy($pharmacy);

    $insurers = collect(range(1, $count))->map(
        fn (int $i) => Insurer::factory()->create([
            'name' => sprintf('Officine %d · Assureur %02d', $pharmacy->id, $i),
        ]),
    );

    $pharmacy->insurers()->attach($insurers->pluck('id'));

    return [$user->fresh(), $pharmacy, $insurers];
}

/** @return array{year: int, month: int, label: string, isComplete: bool, isCurrent: bool, remaining: int} */
function calendarMonth(Pharmacy $pharmacy, int $month): array
{
    return collect(app(DeclarationCalendar::class)->months($pharmacy))
        ->firstWhere(fn (array $entry): bool => $entry['year'] === 2026 && $entry['month'] === $month);
}

test('an insurer without invoice is answered and the round moves to the next one', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(2);

    $this->actingAs($user)
        ->post(route('pharmacy.nil-declarations.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 7,
        ])
        ->assertRedirect(route('pharmacy.declare', ['year' => 2026, 'month' => 7]));

    expect(NilDeclaration::query()->sole())
        ->pharmacy_id->toBe($pharmacy->id)
        ->insurer_id->toBe($insurers[0]->id)
        ->user_id->toBe($user->id)
        ->author_name->toBe('Awa Dossou');

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['year' => 2026, 'month' => 7]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Declare')
            ->where('insurer.id', $insurers[1]->id),
        );
});

test('a month whose insurers are all declared or without invoice is complete', function () {
    [, $pharmacy, $insurers] = officineWithoutInvoices(2);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    expect(calendarMonth($pharmacy, 7))->toMatchArray(['isComplete' => false, 'remaining' => 1]);

    NilDeclaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[1]->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    expect(calendarMonth($pharmacy, 7))->toMatchArray(['isComplete' => true, 'remaining' => 0]);
});

test('the dashboard reports every remaining insurer of a month at once, declared ones untouched', function () {
    Http::fake();
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(3);

    $declared = Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 6,
    ]);

    $this->actingAs($user)
        ->from(route('dashboard', ['current_pharmacy' => $pharmacy->slug]))
        ->post(route('pharmacy.nil-declarations.store'), ['period_year' => 2026, 'period_month' => 6])
        ->assertRedirect(route('dashboard', ['current_pharmacy' => $pharmacy->slug]));

    expect(NilDeclaration::query()->orderBy('insurer_id')->pluck('insurer_id')->all())
        ->toBe([$insurers[1]->id, $insurers[2]->id])
        ->and($declared->fresh())->not->toBeNull()
        ->and(calendarMonth($pharmacy, 6)['isComplete'])->toBeTrue();
});

test('an insurer whose invoice is declared cannot be reported without invoice', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(1);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $this->actingAs($user)
        ->post(route('pharmacy.nil-declarations.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 7,
        ])
        ->assertSessionHasErrors(['insurer_id' => 'Une facture est déjà déclarée pour cet assureur ce mois-ci.']);

    expect(NilDeclaration::query()->exists())->toBeFalse();
});

test('an insurer the officine has not ticked cannot be reported', function () {
    [$user] = officineWithoutInvoices(1);
    $stranger = Insurer::factory()->create();

    $this->actingAs($user)
        ->post(route('pharmacy.nil-declarations.store'), [
            'insurer_id' => $stranger->id,
            'period_year' => 2026,
            'period_month' => 7,
        ])
        ->assertSessionHasErrors('insurer_id');

    expect(NilDeclaration::query()->exists())->toBeFalse();
});

test('a month beyond the catch-up window cannot be reported', function () {
    [$user, , $insurers] = officineWithoutInvoices(1);

    $this->actingAs($user)
        ->post(route('pharmacy.nil-declarations.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2025,
            'period_month' => 7,
        ])
        ->assertSessionHasErrors('period');
});

test('the declaration screen shows the answer and keeps the form open', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(1);

    $nil = NilDeclaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 7,
        'author_name' => 'Awa Dossou',
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['year' => 2026, 'month' => 7, 'insurer' => $insurers[0]->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Declare')
            ->where('declaration', null)
            ->where('nilDeclaration.authorName', 'Awa Dossou')
            ->where('nilDeclaration.destroyUrl', route('pharmacy.nil-declarations.destroy', $nil)),
        );

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['year' => 2026, 'month' => 7]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/DeclareDone')
            ->where('declared', 0)
            ->where('withoutInvoice', 1),
        );
});

test('declaring an invoice replaces the answer « pas de facture »', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(1);

    NilDeclaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 7,
            'amount_invoiced' => 400_000,
            'invoice_deposited_on' => '2026-08-01',
            'payments' => [],
        ])
        ->assertSessionHasNoErrors();

    expect(Declaration::query()->count())->toBe(1)
        ->and(NilDeclaration::query()->exists())->toBeFalse();
});

test('withdrawing the answer sends the insurer back to « à déclarer »', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(1);

    $nil = NilDeclaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $this->actingAs($user)
        ->delete(route('pharmacy.nil-declarations.destroy', $nil))
        ->assertRedirect(route('pharmacy.declare', ['year' => 2026, 'month' => 7, 'insurer' => $insurers[0]->id]));

    expect(NilDeclaration::query()->exists())->toBeFalse()
        ->and(calendarMonth($pharmacy, 7)['isComplete'])->toBeFalse();
});

test('another officine\'s answer cannot be withdrawn', function () {
    [$user] = officineWithoutInvoices(1);
    $foreign = NilDeclaration::factory()->create();

    $this->actingAs($user)
        ->delete(route('pharmacy.nil-declarations.destroy', $foreign))
        ->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('a zero amount on a blank month is recorded as « pas de facture », without a deposit date', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(2);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 7,
            'amount_invoiced' => 0,
            'payments' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('pharmacy.declare', ['year' => 2026, 'month' => 7]));

    expect(Declaration::query()->exists())->toBeFalse()
        ->and(NilDeclaration::query()->sole()->insurer_id)->toBe($insurers[0]->id)
        ->and(calendarMonth($pharmacy, 7)['remaining'])->toBe(1);
});

test('a rejected invoice at zero stays a declaration and still needs its deposit date', function () {
    [$user, , $insurers] = officineWithoutInvoices(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 7,
            'amount_invoiced' => 0,
            'status' => 'rejected',
            'payments' => [],
        ])
        ->assertSessionHasErrors('invoice_deposited_on');

    expect(NilDeclaration::query()->exists())->toBeFalse();
});

test('a declared month cannot be turned into « pas de facture » by a zero amount', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(1);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 500_000,
    ]);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 7,
            'amount_invoiced' => 0,
            'invoice_deposited_on' => '2026-08-01',
            'payments' => [],
        ])
        ->assertSessionHasErrors(['amount_invoiced' => 'Une facture est déjà enregistrée pour ce mois : corrigez son montant plutôt que de le passer à 0.']);

    expect(Declaration::query()->sole()->amount_invoiced)->toBe(500_000)
        ->and(NilDeclaration::query()->exists())->toBeFalse();
});

test('the form checkbox reports the remaining insurers without invoice after saving the current one', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(3);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 7,
            'amount_invoiced' => 400_000,
            'invoice_deposited_on' => '2026-08-01',
            'payments' => [],
            'remaining_without_invoice' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect(Declaration::query()->sole()->insurer_id)->toBe($insurers[0]->id)
        ->and(NilDeclaration::query()->orderBy('insurer_id')->pluck('insurer_id')->all())
        ->toBe([$insurers[1]->id, $insurers[2]->id])
        ->and(calendarMonth($pharmacy, 7)['isComplete'])->toBeTrue();
});

test('without the checkbox the remaining insurers stay to declare', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(2);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), [
            'insurer_id' => $insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 7,
            'amount_invoiced' => 0,
            'payments' => [],
        ]);

    expect(NilDeclaration::query()->count())->toBe(1)
        ->and(calendarMonth($pharmacy, 7)['isComplete'])->toBeFalse();
});

test('the form knows how many other insurers are still without answer', function () {
    [$user, $pharmacy, $insurers] = officineWithoutInvoices(3);

    NilDeclaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[2]->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['year' => 2026, 'month' => 7]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('insurer.id', $insurers[0]->id)
            ->where('remainingAfter', 1),
        );
});
