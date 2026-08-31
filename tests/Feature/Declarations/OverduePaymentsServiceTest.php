<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Declarations\OverduePaymentsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 31));
    $this->service = app(OverduePaymentsService::class);
    $this->pharmacy = Pharmacy::factory()->create();
});

/**
 * Une déclaration déposée il y a $daysAgo jours, dont il reste quelque chose.
 *
 * @param  array<string, mixed>  $attributes
 */
function overdueCandidate(Pharmacy $pharmacy, Insurer $insurer, int $daysAgo, array $attributes = []): Declaration
{
    $deposited = CarbonImmutable::create(2026, 8, 31)->subDays($daysAgo);

    return Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => $deposited->year,
        'period_month' => $deposited->month,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => false,
        'invoice_deposited_on' => $deposited,
        'paid_on' => null,
        'delay_days' => null,
        ...$attributes,
    ]);
}

test('a declaration exactly at the standard delay is not yet overdue', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    overdueCandidate($this->pharmacy, $insurer, 30);

    expect($this->service->forPharmacy($this->pharmacy))->toBeEmpty();
});

test('a declaration one day past the standard delay is overdue', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    overdueCandidate($this->pharmacy, $insurer, 31);

    $lines = $this->service->forPharmacy($this->pharmacy);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->ageDays)->toBe(31)
        ->and($lines[0]->standardDelayDays)->toBe(30)
        ->and($lines[0]->outstanding)->toBe(1_000_000);
});

test('each insurer is judged by its own standard delay', function () {
    $strict = Insurer::factory()->create(['name' => 'Strict', 'standard_delay_days' => 30]);
    $lenient = Insurer::factory()->create(['name' => 'Souple', 'standard_delay_days' => 45]);

    overdueCandidate($this->pharmacy, $strict, 40);
    overdueCandidate($this->pharmacy, $lenient, 40);

    $lines = $this->service->forPharmacy($this->pharmacy);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->insurerName)->toBe('Strict');
});

test('rejected, settled and undated declarations are left out', function () {
    // Un assureur par cas : la clé unique porte sur (officine, assureur, mois),
    // et trois dépôts à quelques jours d'écart retombent sur le même mois.
    $delay = ['standard_delay_days' => 30];

    overdueCandidate($this->pharmacy, Insurer::factory()->create($delay), 90, [
        'status' => DeclarationStatus::Rejected,
        'is_status_manual' => true,
    ]);
    overdueCandidate($this->pharmacy, Insurer::factory()->create($delay), 91, [
        'amount_received' => 1_000_000,
        'status' => DeclarationStatus::Paid,
        'paid_on' => CarbonImmutable::create(2026, 8, 30),
    ]);
    // La facture sans date ne peut pas être fabriquée : DeclarationFactory
    // remplit toujours invoice_deposited_on. Elle n'existe que comme ligne
    // héritée d'avant la migration, donc on la produit en écrivant la colonne.
    $undated = overdueCandidate($this->pharmacy, Insurer::factory()->create($delay), 92);

    DB::table('declarations')
        ->where('id', $undated->id)
        ->update(['invoice_deposited_on' => null]);

    expect($this->service->forPharmacy($this->pharmacy))->toBeEmpty();
});

test('lines come back oldest first', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    overdueCandidate($this->pharmacy, $insurer, 40);
    overdueCandidate($this->pharmacy, $insurer, 100);
    overdueCandidate($this->pharmacy, $insurer, 70);

    expect(array_map(
        fn ($line) => $line->ageDays,
        $this->service->forPharmacy($this->pharmacy),
    ))->toBe([100, 70, 40]);
});

test('only officines carrying an overdue invoice come back', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    $quiet = Pharmacy::factory()->create();

    overdueCandidate($this->pharmacy, $insurer, 60);
    overdueCandidate($quiet, $insurer, 10);

    $found = $this->service->pharmaciesWithOverdue();

    expect($found)->toHaveCount(1)
        ->and($found->first()->id)->toBe($this->pharmacy->id);
});
