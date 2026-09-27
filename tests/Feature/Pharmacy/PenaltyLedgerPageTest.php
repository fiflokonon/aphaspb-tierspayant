<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\User;
use App\Services\Pharmacy\PharmacyPenaltyLedgerRows;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

/**
 * Un titulaire et une facture de mars jamais réglée chez un assureur sous convention.
 *
 * @return array{0: User, 1: Insurer}
 */
function ledgerOfficine(string $insurerName = 'NSIA'): array
{
    $user = User::factory()->create();
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => $insurerName]);

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-03-31',
        'paid_on' => null,
        'delay_days' => null,
    ]);

    return [$user, $insurer];
}

test('the officine reads its own penalty ledger', function () {
    [$user, $insurer] = ledgerOfficine();

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/PenaltyLedger')
            ->where('insurers.0.id', $insurer->id)
            ->missing('penaltyTrend')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('penaltyTrend.total.months.7.month', '2026-05')
                ->where('penaltyTrend.total.months.7.accrued', 20_000)));
});

test('another officine\'s invoices never reach the ledger', function () {
    ledgerOfficine();
    [$other] = ledgerOfficine('SUNU Assurances');

    $this->actingAs($other)
        ->get(route('pharmacy.penalty-ledger'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('penaltyTrend.total.months.7.accrued', 20_000)));
});

test('a forged insurer id is ignored', function () {
    [$user] = ledgerOfficine();
    $foreign = Insurer::factory()->withPenalty()->create();

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger', ['insurer' => $foreign->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('insurer', null)
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('penaltyTrend.insurers', 1)));
});

test('the ledger is not open to a network admin without an officine', function () {
    $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('pharmacy.penalty-ledger'))
        ->assertForbidden();
});

/**
 * @return list<list<string>>
 */
function ledgerCsv(User $user, array $query = []): array
{
    $body = str_replace("\xEF\xBB\xBF", '', test()->actingAs($user)
        ->get(route('pharmacy.penalty-ledger.download', $query))
        ->streamedContent());

    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\n", trim($body))),
    );
}

test('the CSV has one row per month and insurer, then the total', function () {
    [$user] = ledgerOfficine();

    $rows = ledgerCsv($user, ['format' => 'csv']);
    $columns = PharmacyPenaltyLedgerRows::COLUMNS;
    $may = array_values(array_filter($rows, fn (array $row) => $row[array_search('mois', $columns)] === '2026-05'));

    expect($rows[0])->toBe($columns)
        ->and(array_column($may, array_search('assureur', $columns)))->toBe(['NSIA', 'Tous assureurs'])
        ->and($may[0][array_search('penalite_courue', $columns)])->toBe('20000')
        // Octobre et la suite ne sont pas encore arrivés : aucune ligne.
        ->and(array_filter($rows, fn (array $row) => $row[0] === '2026-10'))->toBe([]);
});

test('the current month is flagged in the file', function () {
    [$user] = ledgerOfficine();

    $rows = ledgerCsv($user, ['format' => 'csv']);
    $columns = PharmacyPenaltyLedgerRows::COLUMNS;
    $september = array_values(array_filter($rows, fn (array $row) => $row[0] === '2026-09'))[0];

    expect($september[array_search('mois_en_cours', $columns)])->toBe('oui');
});

test('the three formats download with their own type and name', function (string $format, string $type) {
    [$user] = ledgerOfficine();
    $slug = $user->currentPharmacy->slug;

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger.download', ['format' => $format]))
        ->assertOk()
        ->assertHeader('Content-Type', $type)
        ->assertDownload("{$slug}-penalites-2026-09.{$format}");
})->with([
    ['csv', 'text/csv; charset=UTF-8'],
    ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ['pdf', 'application/pdf'],
]);
