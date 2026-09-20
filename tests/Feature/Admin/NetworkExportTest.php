<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\InsurerPenaltyAggregates;
use App\Services\Network\NetworkExportRows;
use App\Services\Network\NetworkPdfExport;
use App\Support\Fcfa;
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

    $rows = networkCsvRows();
    $header = $rows[0];
    $cells = collect($rows)->first(fn (array $row) => in_array('Assez de declarants', $row, true));
    $cell = fn (string $column): string => $cells[array_search($column, $header, true)];

    // Référencées par leur nom et non par leur index : une colonne insérée en
    // amont décalait silencieusement ces assertions vers une autre valeur.
    expect($cell('officines_declarantes'))->toBe('5')
        // The delay the share « sous seuil » is judged against travels with it,
        // otherwise the file states a percentage against an unstated rule.
        ->and($cell('delai_standard_jours'))->toBe('30')
        ->and($cells)->toHaveCount(count(NetworkExportRows::COLUMNS))
        ->and($cell('facture_fcfa'))->toBe('5000000');
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

test('the report comes back as a pdf, and states the period it covers', function () {
    exportDeclare(Insurer::factory()->create(['name' => 'Assez de declarants']), 5);

    $response = $this->actingAs($this->admin)
        ->get(route('admin.csv-exports.download', ['format' => 'pdf', 'period' => 'last-12-months']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->streamedContent())->toStartWith('%PDF-');
});

test('the report withholds an insurer below the anonymity threshold like every other format', function () {
    exportDeclare(Insurer::factory()->create(['name' => 'Petit Assureur']), 3);

    $data = app(NetworkPdfExport::class);

    // Rendre le PDF puis y chercher du texte n'apprendrait rien de fiable : la
    // vue est testée par ce qu'on lui donne, et la règle de retenue vit dans le
    // service. Un assureur sous le seuil ne doit jamais arriver dans `rows`.
    $reflected = new ReflectionMethod($data, 'data');
    $payload = $reflected->invoke($data, new Period(2026, 8), new Period(2026, 8), null);

    expect($payload['rows'])->toBeEmpty()
        ->and($payload['withheld'])->toHaveCount(1)
        ->and($payload['withheld'][0]['name'])->toBe('Petit Assureur')
        ->and($payload['withheld'][0]['declaringPharmacies'])->toBe(3);
});

/**
 * Le CSV téléchargé, parsé, en-tête compris.
 *
 * @return list<list<string>>
 */
function networkCsvRows(): array
{
    $body = str_replace("\xEF\xBB\xBF", '', downloadCsv());

    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\n", trim($body))),
    );
}

test('the csv carries the longest delay and the potential penalty', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'NSIA', 'standard_delay_days' => 30]);

    // Cinq officines : le seuil d'anonymat par défaut vaut 5, et en deçà cet
    // assureur sortirait sans aucun chiffre. Chacune une facture de 1 000 000
    // déposée il y a 120 jours et jamais réglée : trois tranches à 20 000,
    // cinq fois, et un retard de 120 jours.
    exportDeclare($insurer, 5, [
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $rows = networkCsvRows();
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('delai_le_plus_long_jours'))->toBe('120')
        ->and($cell('delai_declenchement_penalite_jours'))->toBe('60')
        ->and($cell('taux_penalite_pct'))->toBe('2')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('300000');
});

test('an insurer without a clause leaves the penalty cells empty', function () {
    // Soldée, sinon c'est l'âge de l'encours qui l'emporte sur delay_days —
    // et exportDeclare() laisse 300 000 impayés par défaut.
    exportDeclare(Insurer::factory()->create(['standard_delay_days' => 30]), 5, [
        'amount_received' => 1_000_000,
        'delay_days' => 44,
    ]);

    $rows = networkCsvRows();
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('delai_le_plus_long_jours'))->toBe('44')
        ->and($cell('delai_declenchement_penalite_jours'))->toBe('')
        ->and($cell('taux_penalite_pct'))->toBe('')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('');
});

test('an insurer under the anonymity threshold gets no penalty figure either', function () {
    $hidden = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'Petit Assureur']);

    // Une seule officine : loin sous le seuil de 5.
    exportDeclare($hidden, 1, [
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $rows = networkCsvRows();
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('assureur'))->toBe('Petit Assureur')
        ->and($cell('delai_le_plus_long_jours'))->toBe('')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('');
});

test('the report gives a page to each insurer above the threshold', function () {
    $shown = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'NSIA Assurances']);
    $hidden = Insurer::factory()->create(['name' => 'Petit Assureur']);

    // Soldées : sans cela l'âge de l'encours l'emporterait sur delay_days.
    exportDeclare($shown, 5, ['amount_received' => 1_000_000, 'delay_days' => 44]);
    exportDeclare($hidden, 1, ['amount_received' => 1_000_000, 'delay_days' => 10]);

    $export = app(NetworkPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, new Period(2026, 8), new Period(2026, 8), null);

    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['name'])->toBe('NSIA Assurances')
        ->and($payload['rows'][0]['figures']->longestDelayDays)->toBe(44)
        ->and($payload['rows'][0])->toHaveKey('monthly')
        ->and($payload['rows'][0]['monthly'])->toHaveCount(1)
        // L'assureur sous le seuil n'a pas de page : il n'est pas dans `rows`.
        // Son nom reste dans la liste de retenue, et c'est voulu — sa ligne
        // explique pourquoi ses chiffres manquent.
        ->and($payload['withheld'])->toHaveCount(1)
        ->and($payload['withheld'][0]['name'])->toBe('Petit Assureur');
});

test('a page totals back to the recap line above it', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'NSIA Assurances']);

    exportDeclare($insurer, 5, [
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $export = app(NetworkPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, new Period(2026, 4), new Period(2026, 8), null);

    // La page et la ligne du récapitulatif sortent du même appel, mais le
    // reste dû n'est **pas** additif : chaque mois est borné à zéro
    // (max(0, facturé − encaissé)) tandis que le récapitulatif ne borne
    // qu'une fois, sur les totaux. Un seul mois surpayé suffit à les séparer,
    // et PenaltyCalculator traite ce cas, donc il est représentable.
    //
    // Ce qui tient toujours, c'est la relation entre les sommes non bornées.
    $months = $payload['rows'][0]['monthly'];
    $invoiced = array_sum(array_column($months, 'invoiced'));
    $received = array_sum(array_column($months, 'received'));

    expect(max(0, $invoiced - $received))->toBe($payload['rows'][0]['amounts']->outstanding)
        ->and($invoiced)->toBe($payload['rows'][0]['amounts']->invoiced)
        ->and($received)->toBe($payload['rows'][0]['amounts']->received);
});

test('the rendered report still comes back as a pdf with the pages in it', function () {
    $shown = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    exportDeclare($shown, 5, ['amount_received' => 1_000_000, 'delay_days' => 44]);

    $this->actingAs($this->admin)
        ->get(route('admin.csv-exports.download', ['format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('the figures are never even computed for an insurer under the threshold', function () {
    $shown = Insurer::factory()->create(['name' => 'Assez de declarants']);
    $hidden = Insurer::factory()->create(['name' => 'Trop peu']);

    exportDeclare($shown, 5, ['amount_received' => 1_000_000, 'delay_days' => 44]);
    exportDeclare($hidden, 1, ['amount_received' => 1_000_000, 'delay_days' => 44]);

    // La ligne de retenue protège déjà la sortie : même calculés, les chiffres
    // d'un assureur sous le seuil n'y arriveraient pas. Ce test épingle la
    // défense en amont — il n'entre pas dans la requête — pour qu'une colonne
    // ajoutée un jour au chemin « retenu » ne puisse pas la faire fuiter.
    $this->mock(InsurerPenaltyAggregates::class, function ($mock) use ($shown, $hidden) {
        $mock->shouldReceive('forInsurers')
            ->once()
            ->withArgs(function (array $insurerIds) use ($shown, $hidden): bool {
                expect($insurerIds)->toContain($shown->id)
                    ->and($insurerIds)->not->toContain($hidden->id);

                return true;
            })
            ->andReturn([]);
    });

    downloadCsv();
});

test('a month declared by a single officine is withheld from the insurer page', function () {
    $insurer = Insurer::factory()->create(['name' => 'NSIA Assurances']);

    // Cinq officines sur la période : l'assureur franchit le seuil et obtient
    // sa page. Mais en juillet, une seule d'entre elles a déclaré.
    $pharmacies = Pharmacy::factory()->count(5)->create();

    $pharmacies->each(fn (Pharmacy $pharmacy) => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'delay_days' => 20,
    ]));

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacies->first()->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 4_210_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'delay_days' => null,
    ]);

    $export = app(NetworkPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, new Period(2026, 7), new Period(2026, 8), null);

    $months = collect($payload['rows'][0]['monthly'])->keyBy('month');

    // Le seuil vaut 5 sur la période ; il vaut 5 sur chaque mois publié aussi.
    // Sans ça, la ligne de juillet imprimerait la facture exacte d'une officine
    // nommable dans un rapport qui promet l'inverse.
    expect($months[7]['withheld'])->toBeTrue()
        ->and($months[7]['invoiced'])->toBeNull()
        ->and($months[7]['outstanding'])->toBeNull()
        ->and($months[8]['withheld'])->toBeFalse()
        ->and($months[8]['invoiced'])->toBe(5_000_000);
});

test('the rendered page prints no figure for a withheld month', function () {
    $insurer = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    $pharmacies = Pharmacy::factory()->count(5)->create();

    // Août laisse un reste dû, pour que le total de période de l'assureur ne
    // coïncide pas numériquement avec la facture retenue de juillet : sans
    // cela le test rougirait sur un agrégat parfaitement légitime.
    $pharmacies->each(fn (Pharmacy $pharmacy) => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 900_000,
        'delay_days' => 20,
    ]));

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacies->first()->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 4_210_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'delay_days' => null,
    ]);

    $export = app(NetworkPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, new Period(2026, 7), new Period(2026, 8), null);

    $html = view('exports.network', $payload)->render();

    // Le montant exact de l'officine unique ne doit apparaître nulle part.
    expect($html)->not->toContain('4'.Fcfa::THIN_NBSP.'210'.Fcfa::THIN_NBSP.'000')
        ->and($html)->toContain('chiffres retenus');
});
