<?php

use App\Actions\Declarations\RecordPaymentInstalments;
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

test('an overdue line carries no penalty when the insurer has no clause', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    overdueCandidate($this->pharmacy, $insurer, 100);

    expect($this->service->forPharmacy($this->pharmacy)[0]->penalty)->toBeNull();
});

test('an overdue line carries the penalty accrued since the trigger', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    // Déposée il y a 120 jours : tranches au 60e, 90e et 120e jour, sur une
    // base jamais entamée.
    overdueCandidate($this->pharmacy, $insurer, 120);

    expect($this->service->forPharmacy($this->pharmacy)[0]->penalty)->toBe(60_000);
});

test('an instalment lightens the penalty of an overdue line', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    // Déposée il y a 120 jours, déclenchement à 60 : tranches il y a 60, 30 et
    // 0 jours. Le versement à 45 jours tombe entre la première et la deuxième.
    $declaration = overdueCandidate($this->pharmacy, $insurer, 120);

    app(RecordPaymentInstalments::class)->handle($declaration, [[
        'amount' => 400_000,
        'paid_on' => CarbonImmutable::create(2026, 8, 31)->subDays(45)->toDateString(),
    ]]);

    // 20 000 sur la base entière, puis deux tranches sur 600 000.
    expect($this->service->forPharmacy($this->pharmacy)[0]->penalty)->toBe(44_000);
});

test('an instalment received before the trigger lightens every tranche', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    $declaration = overdueCandidate($this->pharmacy, $insurer, 120);

    // À 70 jours, donc avant la première tranche : aucune ne voit la base
    // entière.
    app(RecordPaymentInstalments::class)->handle($declaration, [[
        'amount' => 400_000,
        'paid_on' => CarbonImmutable::create(2026, 8, 31)->subDays(70)->toDateString(),
    ]]);

    expect($this->service->forPharmacy($this->pharmacy)[0]->penalty)->toBe(36_000);
});

test('an overdue line names the insurer it belongs to', function () {
    $insurer = Insurer::factory()->create(['name' => 'Mutuelle Bénin', 'standard_delay_days' => 30]);
    overdueCandidate($this->pharmacy, $insurer, 100);

    $line = $this->service->forPharmacy($this->pharmacy)[0];

    expect($line->insurerId)->toBe($insurer->id)
        ->and($line->insurerName)->toBe('Mutuelle Bénin');
});

test('reading the overdue lines costs three queries whatever their number', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    // Espacées de 35 jours : la clé unique porte sur le mois déclaré, et six
    // dépôts consécutifs tomberaient dans le même.
    foreach (range(0, 5) as $step) {
        overdueCandidate($this->pharmacy, $insurer, 100 + $step * 35);
    }

    DB::enableQueryLog();
    $this->service->forPharmacy($this->pharmacy);

    // Les délais standards distincts, les lignes en retard, leurs versements.
    expect(DB::getQueryLog())->toHaveCount(3);
});
