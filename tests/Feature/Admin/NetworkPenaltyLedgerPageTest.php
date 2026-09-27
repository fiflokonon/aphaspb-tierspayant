<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkPenaltyLedgerRows;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

function networkLedgerDeclare(Insurer $insurer, int $count): void
{
    Pharmacy::factory()->count($count)->create()->each(fn (Pharmacy $pharmacy) => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
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
    ]));
}

test('the network admin reads the network penalty ledger', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkLedgerDeclare($insurer, 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/PenaltyLedger')
            ->missing('penaltyTrend')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('penaltyTrend.insurers', 1)
                ->where('penaltyTrend.total.months.7.accrued', 100_000)));
});

test('an officine user cannot open the network ledger', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.penalty-ledger'))
        ->assertForbidden();
});

test('an unknown insurer id falls back to every insurer', function () {
    $insurer = Insurer::factory()->withPenalty()->create();
    networkLedgerDeclare($insurer, 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger', ['insurer' => 999_999]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('insurer', null));
});

/**
 * @return list<list<string>>
 */
function networkLedgerCsv(User $admin, array $query = []): array
{
    $body = str_replace("\xEF\xBB\xBF", '', test()->actingAs($admin)
        ->get(route('admin.penalty-ledger.download', ['format' => 'csv', ...$query]))
        ->streamedContent());

    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\n", trim($body))),
    );
}

test('a withheld month keeps its row, emptied and explained', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);
    networkLedgerDeclare($insurer, 5);
    // Juin : une seule officine déclarante.
    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 6,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-06-30',
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $rows = networkLedgerCsv(User::factory()->networkAdmin()->create());
    $columns = NetworkPenaltyLedgerRows::COLUMNS;
    $june = array_values(array_filter($rows, fn (array $row) => $row[0] === '2026-06' && $row[1] === 'NSIA'))[0];

    expect($june[array_search('penalite_courue', $columns)])->toBe('')
        ->and($june[array_search('penalite_mois_declare', $columns)])->toBe('')
        ->and($june[array_search('dont_payee', $columns)])->toBe('')
        ->and($june[array_search('dont_annulee', $columns)])->toBe('')
        ->and($june[array_search('reste_due', $columns)])->toBe('')
        ->and($june[array_search('retenu', $columns)])->toBe('moins de 5 officines');

    // Juillet est publié, mais son cumul trahirait juin : vidé, et dit pourquoi.
    $july = array_values(array_filter($rows, fn (array $row) => $row[0] === '2026-07' && $row[1] === 'NSIA'))[0];

    expect($july[array_search('penalite_courue', $columns)])->toBe('100000')
        ->and($july[array_search('cumul_couru', $columns)])->toBe('')
        ->and($july[array_search('dont_payee', $columns)])->toBe('0')
        ->and($july[array_search('reste_due', $columns)])->toBe('100000')
        ->and($july[array_search('retenu', $columns)])->toBe('cumul interrompu par un mois retenu');
});

test('the network file never names an officine', function () {
    $insurer = Insurer::factory()->withPenalty()->create();
    networkLedgerDeclare($insurer, 5);
    Pharmacy::query()->first()->update(['name' => 'Pharmacie Nommable']);

    $admin = User::factory()->networkAdmin()->create();

    foreach (['csv', 'xlsx', 'pdf'] as $format) {
        $response = $this->actingAs($admin)->get(route('admin.penalty-ledger.download', ['format' => $format]))->assertOk();
        $content = $response->streamedContent();

        expect($content)->not->toContain('Pharmacie Nommable');
    }
});

test('the network PDF renders with a withheld month', function () {
    $insurer = Insurer::factory()->withPenalty()->create();
    networkLedgerDeclare($insurer, 2);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger.download', ['format' => 'pdf', 'insurer' => $insurer->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('reseau-penalites-2026-09.pdf');
});
