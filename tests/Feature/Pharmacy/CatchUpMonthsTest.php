<?php

use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
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
 * An officine working with $count insurers.
 *
 * @return array{0: User, 1: Pharmacy, 2: Collection<int, Insurer>}
 */
function officineOwing(int $count): array
{
    $user = User::factory()->notOnboarded()->create();

    $pharmacy = Pharmacy::factory()->create();
    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);
    $user->switchPharmacy($pharmacy);

    // range(1, 0) counts backwards and yields two elements, not none.
    $insurers = collect($count > 0 ? range(1, $count) : [])->map(
        fn (int $i) => Insurer::factory()->create([
            'name' => sprintf('Officine %d · Assureur %02d', $pharmacy->id, $i),
        ]),
    );

    $pharmacy->insurers()->attach($insurers->pluck('id'));

    return [$user->fresh(), $pharmacy, $insurers];
}

test('the calendar spans the current month and the twelve before it', function () {
    [, $pharmacy] = officineOwing(1);

    $months = app(DeclarationCalendar::class)->months($pharmacy);

    expect($months)->toHaveCount(13)
        ->and($months[0])->toMatchArray(['year' => 2026, 'month' => 8, 'isCurrent' => true])
        ->and($months[0]['label'])->toBe('AOÛT 2026')
        // Twelve back from August 2026, the boundary DeclarablePeriod accepts.
        ->and($months[12])->toMatchArray(['year' => 2025, 'month' => 8, 'isCurrent' => false]);
});

test('a month counts as declared only once every ticked insurer is in', function () {
    [, $pharmacy, $insurers] = officineOwing(2);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $july = fn (): array => collect(app(DeclarationCalendar::class)->months($pharmacy))
        ->firstWhere(fn (array $month): bool => $month['year'] === 2026 && $month['month'] === 7);

    expect($july()['isComplete'])->toBeFalse();

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[1]->id,
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    expect($july()['isComplete'])->toBeTrue();
});

test('the months owed are listed oldest first and exclude the month in progress', function () {
    [, $pharmacy, $insurers] = officineOwing(1);

    // Everything is owed except June, which is fully declared.
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 6,
    ]);

    $owed = app(DeclarationCalendar::class)->outstanding($pharmacy);

    expect($owed)->toHaveCount(11)
        ->and($owed[0])->toMatchArray(['year' => 2025, 'month' => 8])
        ->and(collect($owed)->contains(fn (array $month): bool => $month['month'] === 6))->toBeFalse()
        ->and(collect($owed)->contains(fn (array $month): bool => $month['isCurrent']))->toBeFalse();
});

test('an officine with no insurers is owed nothing', function () {
    [, $pharmacy] = officineOwing(0);

    expect(app(DeclarationCalendar::class)->outstanding($pharmacy))->toBe([]);
});

test('the dashboard carries the months owed, each with the link that opens it', function () {
    Http::fake();
    [$user, $pharmacy] = officineOwing(1);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $pharmacy->slug]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('outstandingMonths', 12)
            ->where('outstandingMonths.0.url', route('pharmacy.declare', ['year' => 2025, 'month' => 8]))
            ->etc(),
        );
});

test('the finished screen can reach another month without going back to the dashboard', function () {
    Http::fake();
    [$user, $pharmacy, $insurers] = officineOwing(1);

    // Nothing left to declare for August, so the round lands on DeclareDone.
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/DeclareDone')
            ->has('periods', 13)
            ->etc(),
        );
});

test('the declaration screen carries the months it can switch to', function () {
    Http::fake();
    [$user] = officineOwing(1);

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('periods', 13)
            ->where('periods.0.url', route('pharmacy.declare', ['year' => 2026, 'month' => 8]))
            ->etc(),
        );
});
