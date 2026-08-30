<?php

use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkExportRows;
use App\Services\Network\NetworkXlsxExport;
use Carbon\CarbonImmutable;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
    $this->export = app(NetworkXlsxExport::class);
    $this->path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
});

afterEach(function () {
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

/**
 * Read the workbook back the way a spreadsheet would.
 *
 * @return list<list<mixed>>
 */
function sheetOf(string $path): array
{
    $reader = new Reader;
    $reader->open($path);

    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();

    return $rows;
}

function declareForXlsx(Insurer $insurer, int $pharmacies, int $delay = 30): void
{
    Pharmacy::factory()->count($pharmacies)->create(['city' => 'Cotonou'])->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 750_000,
            'delay_days' => $delay,
        ]),
    );
}

test('the workbook opens on a header naming every column', function () {
    $this->export->writeTo($this->path, new Period(2026, 8), new Period(2026, 8));

    expect(sheetOf($this->path)[0])->toBe(NetworkExportRows::COLUMNS);
});

test('amounts are numeric cells, not text a spreadsheet cannot total', function () {
    $insurer = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    declareForXlsx($insurer, 5);

    $this->export->writeTo($this->path, new Period(2026, 8), new Period(2026, 8));

    $row = sheetOf($this->path)[1];

    expect($row[0])->toBe('NSIA Assurances')
        ->and($row[1])->toBeInt()
        ->and($row[1])->toBe(5)
        // facture_fcfa: the column an advocacy note sums.
        ->and($row[9])->toBeInt()
        ->and($row[9])->toBe(5_000_000)
        // A whole float comes back as an int from the reader; what matters is
        // that the cell is a number and not the text « 75,0 ».
        ->and($row[12])->not->toBeString()
        ->and((float) $row[12])->toBe(75.0);
});

test('an insurer under the threshold carries its explanation and no figure', function () {
    $insurer = Insurer::factory()->create(['name' => 'Petit Assureur']);
    declareForXlsx($insurer, 3);

    $this->export->writeTo($this->path, new Period(2026, 8), new Period(2026, 8));

    $row = sheetOf($this->path)[1];

    expect($row[0])->toBe('Petit Assureur')
        ->and($row[2])->toContain('donnees insuffisantes')
        ->and(array_slice($row, 3))->each->toBeEmpty();
});

test('the workbook narrows to one city like the csv does', function () {
    $insurer = Insurer::factory()->create();
    declareForXlsx($insurer, 5);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => Pharmacy::factory()->create(['city' => 'Parakou']),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 9_000_000,
        'amount_received' => 0,
    ]);

    $this->export->writeTo($this->path, new Period(2026, 8), new Period(2026, 8), 'Cotonou');

    expect(sheetOf($this->path)[1][9])->toBe(5_000_000);
});
