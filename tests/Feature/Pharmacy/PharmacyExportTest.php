<?php

use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Pharmacy\PharmacyExportRows;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
});

/**
 * An officine, its titulaire, and one insurer it declares to.
 *
 * @return array{0: User, 1: Pharmacy, 2: Insurer}
 */
function exportingOfficine(): array
{
    $user = User::factory()->notOnboarded()->create();
    $pharmacy = Pharmacy::factory()->create(['name' => 'Pharmacie Test']);
    // Vingt jours : le premier versement du mois fractionné rentre dans les
    // clous, le second non. C'est le cas que le total seul faisait disparaître.
    $insurer = Insurer::factory()->create(['name' => 'NSIA Assurances', 'standard_delay_days' => 20]);

    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);
    $pharmacy->insurers()->attach($insurer);
    $user->switchPharmacy($pharmacy);

    return [$user->fresh(), $pharmacy, $insurer];
}

function declareSplit(Pharmacy $pharmacy, Insurer $insurer): Declaration
{
    return Declaration::factory()
        ->instalments([
            ['amount' => 400_000, 'paid_on' => '2026-08-06'],
            ['amount' => 600_000, 'paid_on' => '2026-08-26'],
        ])
        ->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-08-01',
            'private_note' => 'Relancer la comptabilite',
        ]);
}

/**
 * The downloaded CSV, parsed rather than split.
 *
 * fputcsv() quotes any field holding a space, so « NSIA Assurances » comes back
 * enclosed; splitting on the separator would compare against the quotes. The
 * BOM that makes Excel read the accents has to come off the first header cell
 * for the same reason.
 *
 * @return list<list<string>>
 */
function csvRowsFor(User $user, array $query = []): array
{
    $body = test()->actingAs($user)
        ->get(route('pharmacy.data-exports.download', $query))
        ->streamedContent();

    $body = str_replace("\xEF\xBB\xBF", '', $body);

    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\n", trim($body))),
    );
}

test('the export screen lists the formats and the officine own insurers', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    declareSplit($pharmacy, $insurer);

    $this->actingAs($user)
        ->get(route('pharmacy.data-exports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Exports')
            ->where('pharmacyName', 'Pharmacie Test')
            ->where('columns', PharmacyExportRows::COLUMNS)
            ->has('insurers', 1)
            ->where('insurers.0.name', 'NSIA Assurances'),
        );
});

test('the csv carries the instalments of a month settled in two goes', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    declareSplit($pharmacy, $insurer);

    $rows = csvRowsFor($user);
    $header = $rows[0];
    $row = $rows[1];

    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('assureur'))->toBe('NSIA Assurances')
        ->and($cell('encaisse_fcfa'))->toBe('1000000')
        ->and($cell('versements'))->toBe('2')
        // Le délai se compte au dernier versement, mais chaque ligne garde le
        // sien : c'est ce que le total seul faisait perdre.
        ->and($cell('delai_jours'))->toBe('25')
        ->and($cell('delai_standard_jours'))->toBe('20')
        ->and($cell('detail_versements'))
        ->toBe('400000 le 2026-08-06 (5 j) | 600000 le 2026-08-26 (25 j)')
        ->and($cell('dans_le_delai'))->toBe('non');
});

test('the officine own file carries its private note, unlike every network export', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    declareSplit($pharmacy, $insurer);

    expect(implode("\n", array_map(fn (array $row): string => implode(';', $row), csvRowsFor($user))))
        ->toContain('Relancer la comptabilite');
});

test('an officine only ever exports its own declarations', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    declareSplit($pharmacy, $insurer);

    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 9_999_999,
    ]);

    $rows = csvRowsFor($user);

    expect($rows)->toHaveCount(2)
        ->and(implode(';', $rows[1]))->not->toContain('9999999');
});

test('the insurer filter narrows the file, and an insurer never declared to is ignored', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    declareSplit($pharmacy, $insurer);

    $other = Insurer::factory()->create(['name' => 'Sanlam']);
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $other->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    expect(csvRowsFor($user, ['insurer' => $insurer->id]))->toHaveCount(2);

    // Un assureur auquel l'officine n'a jamais déclaré ne doit pas produire un
    // fichier vide qui se lirait comme « rien déclaré » : le filtre est ignoré.
    $stranger = Insurer::factory()->create();

    expect(csvRowsFor($user, ['insurer' => $stranger->id]))->toHaveCount(3);
});

test('the workbook and the report come back as their own file types', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    declareSplit($pharmacy, $insurer);

    $this->actingAs($user)
        ->get(route('pharmacy.data-exports.download', ['format' => 'xlsx']))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $report = $this->actingAs($user->fresh())
        ->get(route('pharmacy.data-exports.download', ['format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($report->streamedContent())->toStartWith('%PDF-');
});

test('an admin without an officine cannot reach the officine export', function () {
    $admin = User::factory()->networkAdmin()->create();

    $this->actingAs($admin)->get(route('pharmacy.data-exports'))->assertForbidden();
    $this->actingAs($admin)->get(route('pharmacy.data-exports.download'))->assertForbidden();
});

test('a binary download creates one temp file, not one it then abandons', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    declareSplit($pharmacy, $insurer);

    // tempnam() *crée* le fichier qu'il nomme. Lui concaténer une extension
    // écrivait dans un second fichier et abandonnait le premier — que
    // deleteFileAfterSend() ne connaît pas, donc un orphelin par
    // téléchargement, à jamais.
    //
    // La suppression elle-même n'est pas observable ici : elle se déclenche à
    // l'envoi réel de la réponse, que le client de test ne fait pas. Ce qui se
    // vérifie, c'est qu'un seul fichier naît par téléchargement.
    $before = glob(sys_get_temp_dir().'/aphaspb*') ?: [];

    foreach (['xlsx', 'pdf'] as $format) {
        $this->actingAs($user->fresh())
            ->get(route('pharmacy.data-exports.download', ['format' => $format]))
            ->assertOk();
    }

    $created = array_diff(glob(sys_get_temp_dir().'/aphaspb*') ?: [], $before);

    expect($created)->toHaveCount(2);

    foreach ($created as $orphan) {
        @unlink($orphan);
    }
});
