<?php

use App\Enums\DeclarationStatus;
use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Notifications\Declarations\OverduePaymentsDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 31));
});

/**
 * Une facture impayée déposée il y a $daysAgo jours.
 */
function arrearsFor(Pharmacy $pharmacy, Insurer $insurer, int $daysAgo, int $invoiced = 1_000_000): void
{
    $deposited = CarbonImmutable::create(2026, 8, 31)->subDays($daysAgo);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => $deposited->year,
        'period_month' => $deposited->month,
        'amount_invoiced' => $invoiced,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => false,
        'invoice_deposited_on' => $deposited,
        'paid_on' => null,
        'delay_days' => null,
    ]);
}

/**
 * Une officine, ses membres par rôle, et une facture en retard.
 *
 * @param  array<string, PharmacyRole>  $roles  nom du membre => rôle
 * @return array{0: Pharmacy, 1: Collection<string, User>}
 */
function officineInArrears(array $roles, int $daysAgo = 60, int $standardDelay = 30): array
{
    $pharmacy = Pharmacy::factory()->create();
    $insurer = Insurer::factory()->create(['standard_delay_days' => $standardDelay]);
    $pharmacy->insurers()->attach($insurer->id);

    $members = collect($roles)->map(function (PharmacyRole $role) use ($pharmacy) {
        $user = User::factory()->notOnboarded()->create();
        $pharmacy->members()->attach($user, ['role' => $role->value]);

        return $user;
    });

    arrearsFor($pharmacy, $insurer, $daysAgo);

    return [$pharmacy, $members];
}

test('the digest reaches the owner and the managers, never plain members', function () {
    Notification::fake();

    [, $members] = officineInArrears([
        'titulaire' => PharmacyRole::Owner,
        'gestionnaire' => PharmacyRole::Admin,
        'membre' => PharmacyRole::Member,
    ]);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertSentTo($members['titulaire'], OverduePaymentsDigest::class);
    Notification::assertSentTo($members['gestionnaire'], OverduePaymentsDigest::class);
    Notification::assertNotSentTo($members['membre'], OverduePaymentsDigest::class);
});

test('an officine with several overdue invoices receives one digest, not several', function () {
    Notification::fake();

    [$pharmacy, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);
    $insurer = $pharmacy->insurers()->sole();

    foreach ([100, 130, 160] as $daysAgo) {
        arrearsFor($pharmacy, $insurer, $daysAgo, 500_000);
    }

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertSentToTimes($members['titulaire'], OverduePaymentsDigest::class, 1);
});

test('an officine with nothing overdue is left alone', function () {
    Notification::fake();

    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner], daysAgo: 10);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertNothingSentTo($members['titulaire']);
});

test('a second run in the same week sends nothing', function () {
    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::fake();
    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertNothingSentTo($members['titulaire']);
});

test('force overrides the weekly guard', function () {
    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::fake();
    $this->artisan('declarations:notify-overdue', ['--force' => true])->assertSuccessful();

    Notification::assertSentTo($members['titulaire'], OverduePaymentsDigest::class);
});

test('dry run reports without sending', function () {
    Notification::fake();

    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);

    $this->artisan('declarations:notify-overdue', ['--dry-run' => true])
        ->expectsOutputToContain('1 facture')
        ->assertSuccessful();

    Notification::assertNothingSentTo($members['titulaire']);
});

test('the stored digest carries the officine and what it is owed', function () {
    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    $stored = $members['titulaire']->notifications()->sole();

    expect($stored->data['outstanding'])->toBe(1_000_000)
        ->and($stored->data['lines'])->toHaveCount(1);
});
