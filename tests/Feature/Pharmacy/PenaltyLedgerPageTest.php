<?php

use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\User;
use App\Services\Pharmacy\PharmacyPenaltyTraceRows;
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

/**
 * Les lignes d'une facture, repérées par son mois déclaré.
 *
 * @param  list<list<string>>  $rows
 * @return list<array<string, string>>
 */
function invoiceRows(array $rows, string $period): array
{
    $columns = PharmacyPenaltyTraceRows::COLUMNS;

    return array_values(array_map(
        fn (array $row): array => array_combine($columns, $row),
        array_filter(array_slice($rows, 1), fn (array $row) => $row[array_search('facture_mois', $columns)] === $period),
    ));
}

test('the file traces each invoice event by event, then what it owes today', function () {
    [$user] = ledgerOfficine();

    $rows = ledgerCsv($user, ['format' => 'csv']);
    $march = invoiceRows($rows, '2026-03');
    $due = end($march);

    expect($rows[0])->toBe(PharmacyPenaltyTraceRows::COLUMNS)
        ->and(array_column($march, 'evenement'))->toBe([
            'Facture déposée', 'Déclenchement de la pénalité',
            'Tranche n° 1', 'Tranche n° 2', 'Tranche n° 3', 'Tranche n° 4',
            'Pénalité exigible',
        ])
        ->and(array_column($march, 'date'))->toBe([
            '2026-03-31', '2026-05-30', '2026-05-30', '2026-06-29', '2026-07-29', '2026-08-28', '2026-09-19',
        ])
        ->and($march[2]['detail'])->toBe("1\u{202F}000\u{202F}000 F × 2 %")
        ->and(array_column($march, 'penalite_cumulee'))->toBe(['', '', '20000', '40000', '60000', '80000', ''])
        ->and($due['detail'])->toBe("4 tranches × 20\u{202F}000 F = 80\u{202F}000 F")
        ->and($due['penalite_exigible'])->toBe('80000')
        ->and(array_filter(array_column($march, 'penalite_exigible')))->toBe([6 => '80000']);
});

test('the file shows each instalment and a closed penalty owes nothing more', function () {
    [$user, $insurer] = ledgerOfficine();
    // Une facture d'avril soldée le 01/09, sa pénalité (juin à août) close « payée ».
    Declaration::factory()
        ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-09-01']])
        ->penaltySettled(PenaltySettlement::Paid, 60_000)
        ->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 4,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-04-30',
        ]);

    $april = invoiceRows(ledgerCsv($user, ['format' => 'csv']), '2026-04');
    $payment = collect($april)->firstWhere('evenement', 'Versement reçu');

    expect($payment)->toMatchArray(['date' => '2026-09-01', 'versement' => '1000000', 'reste_du_facture' => '0'])
        ->and(collect($april)->pluck('statut_penalite')->unique()->all())->toBe(['Pénalité payée'])
        ->and(end($april)['penalite_exigible'])->toBe('0');
});

test('the file follows the insurer filter', function () {
    [$user] = ledgerOfficine();
    $sunu = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'SUNU']);
    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $sunu->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 500_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-03-31',
        'paid_on' => null,
    ]);

    $rows = array_slice(ledgerCsv($user, ['format' => 'csv', 'insurer' => $sunu->id]), 1);

    expect(array_unique(array_column($rows, array_search('assureur', PharmacyPenaltyTraceRows::COLUMNS))))->toBe(['SUNU']);
});

test('the three formats download with their own type and name', function (string $format, string $type) {
    [$user] = ledgerOfficine();
    $slug = $user->currentPharmacy->slug;

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger.download', ['format' => $format]))
        ->assertOk()
        ->assertHeader('Content-Type', $type)
        ->assertDownload("{$slug}-penalites-au-2026-09-19.{$format}");
})->with([
    ['csv', 'text/csv; charset=UTF-8'],
    ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ['pdf', 'application/pdf'],
]);

test('without any insurer under a clause the file carries its header only', function () {
    $user = User::factory()->create();
    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => Insurer::factory(),
        'period_year' => 2026,
        'period_month' => 3,
    ]);

    // Une ligne « Tous assureurs » à zéro se lirait « convention respectée ».
    expect(ledgerCsv($user, ['format' => 'csv']))->toBe([PharmacyPenaltyTraceRows::COLUMNS]);

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger.download', ['format' => 'pdf']))
        ->assertOk();
});
