<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Notifications\Declarations\NetworkOverdueDigest;
use App\Services\Declarations\OverduePaymentsService;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 31));
    $this->service = app(OverduePaymentsService::class);
});

/**
 * $count officines distinctes, chacune avec une facture en retard chez $insurer.
 */
function officinesInArrearsWith(Insurer $insurer, int $count, int $daysAgo = 60): void
{
    $deposited = CarbonImmutable::create(2026, 8, 31)->subDays($daysAgo);

    foreach (range(1, $count) as $ignored) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => $deposited->year,
            'period_month' => $deposited->month,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 400_000,
            'status' => DeclarationStatus::Partial,
            'is_status_manual' => false,
            'invoice_deposited_on' => $deposited,
            'paid_on' => null,
            'delay_days' => null,
        ]);
    }
}

test('an insurer below the anonymity threshold is left out entirely', function () {
    $minimum = app(SettingsRepository::class)->anonymityMinPharmacies();

    $shy = Insurer::factory()->create(['name' => 'Timide', 'standard_delay_days' => 30]);
    officinesInArrearsWith($shy, $minimum - 1);

    expect($this->service->networkTotals())->toBeEmpty();
});

test('an insurer at the threshold is aggregated', function () {
    $minimum = app(SettingsRepository::class)->anonymityMinPharmacies();

    $insurer = Insurer::factory()->create(['name' => 'Assureur A', 'standard_delay_days' => 30]);
    officinesInArrearsWith($insurer, $minimum);

    $totals = $this->service->networkTotals();

    expect($totals)->toHaveCount(1)
        ->and($totals[0]->insurerName)->toBe('Assureur A')
        ->and($totals[0]->pharmacies)->toBe($minimum)
        ->and($totals[0]->declarations)->toBe($minimum)
        ->and($totals[0]->outstanding)->toBe(600_000 * $minimum);
});

test('the digest payload names no officine', function () {
    Notification::fake();

    $minimum = app(SettingsRepository::class)->anonymityMinPharmacies();
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    officinesInArrearsWith($insurer, $minimum);

    $admin = User::factory()->networkAdmin()->create();

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertSentTo($admin, NetworkOverdueDigest::class, function (NetworkOverdueDigest $digest) use ($admin) {
        $encoded = json_encode($digest->toArray($admin));

        foreach (Pharmacy::query()->pluck('name') as $name) {
            expect($encoded)->not->toContain($name);
        }

        return true;
    });
});

test('the network digest is not sent when nothing clears the threshold', function () {
    Notification::fake();

    $admin = User::factory()->networkAdmin()->create();
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    officinesInArrearsWith($insurer, 1);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertNotSentTo($admin, NetworkOverdueDigest::class);
});
