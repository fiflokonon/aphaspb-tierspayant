<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
    $this->admin = User::factory()->networkAdmin()->notOnboarded()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function exportDeclare(Insurer $insurer, int $pharmacies, array $attributes = []): void
{
    Pharmacy::factory()->count($pharmacies)->create()->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->create([
            'amount_invoiced' => 1_000_000,
            'amount_received' => 700_000,
            'delay_days' => 40,
            ...$attributes,
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]),
    );
}

function downloadCsv(): string
{
    return test()->actingAs(test()->admin)
        ->get(route('admin.csv-exports.download'))
        ->streamedContent();
}

test('the page offers the export', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.csv-exports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Exports')
            ->has('downloadUrl')
            ->has('columns'),
        );
});

test('the download is a csv with a dated filename', function () {
    exportDeclare(Insurer::factory()->create(), 5);

    $response = $this->actingAs($this->admin)->get(route('admin.csv-exports.download'));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))
        ->toContain('aphaspb-reseau-2026-08');
});

test('the header carries the expected columns', function () {
    exportDeclare(Insurer::factory()->create(), 5);

    $lines = explode("\n", trim(downloadCsv()));

    expect($lines[0])->toContain('assureur')
        ->and($lines[0])->toContain('officines_declarantes')
        ->and($lines[0])->toContain('delai_moyen_pondere_jours')
        ->and($lines[0])->toContain('encours_fcfa')
        ->and($lines[0])->toContain('taux_recouvrement_pct');
});

test('an insurer above the threshold gets a full row', function () {
    exportDeclare(Insurer::factory()->create(['name' => 'Assez de declarants']), 5);

    $line = collect(explode("\n", downloadCsv()))
        ->first(fn (string $row) => str_contains($row, 'Assez de declarants'));

    $cells = explode(';', trim($line));

    expect($cells[1])->toBe('5')
        ->and($cells)->toHaveCount(12)
        ->and($line)->toContain('5000000');
});

test('an insurer below the threshold gets no figures at all', function () {
    exportDeclare(Insurer::factory()->create(['name' => 'Trop peu']), 2);

    $line = collect(explode("\n", downloadCsv()))
        ->first(fn (string $row) => str_contains($row, 'Trop peu'));

    expect($line)->toContain('donnees insuffisantes')
        ->and($line)->not->toContain('1000000')
        ->and($line)->not->toContain('2000000')
        ->and($line)->not->toContain('40');

    // Every cell after the count and the notice must be empty.
    $cells = explode(';', trim($line));

    expect(array_slice($cells, 3))->each->toBe('');
});

test('the file carries no officine name, no individual amount and no note', function () {
    $insurer = Insurer::factory()->create();
    $pharmacies = Pharmacy::factory()->count(5)->create();

    foreach ($pharmacies as $pharmacy) {
        Declaration::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => 1_234_567,
            'amount_received' => 89_012,
            'delay_days' => 40,
            'private_note' => 'note privée à ne jamais divulguer',
        ]);
    }

    $csv = downloadCsv();

    expect($csv)->not->toContain('privée')
        ->and($csv)->not->toContain('1234567')
        ->and($csv)->not->toContain('89012');

    foreach ($pharmacies as $pharmacy) {
        expect($csv)->not->toContain($pharmacy->name);
    }
});

test('the file is UTF-8 with a BOM so Excel keeps the accents', function () {
    exportDeclare(Insurer::factory()->create(['name' => "L'Africaine des Assurances"]), 5);

    $csv = downloadCsv();

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($csv)->toContain("L'Africaine des Assurances");
});

test('a pharmacy account cannot reach either export route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.csv-exports'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.csv-exports.download'))->assertForbidden();
});
