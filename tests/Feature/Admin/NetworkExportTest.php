<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\InsurerPenaltyAggregates;
use App\Services\Network\NetworkExportRows;
use App\Services\Network\NetworkPdfExport;
use App\Services\Network\NetworkPenaltyJournal;
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

/**
 * @param  array<string, mixed>  $query
 */
function downloadCsv(array $query = []): string
{
    return test()->actingAs(test()->admin)
        ->get(route('admin.csv-exports.download', $query))
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
 * @param  array<string, mixed>  $query
 * @return list<list<string>>
 */
function networkCsvRows(array $query = []): array
{
    $body = str_replace("\xEF\xBB\xBF", '', downloadCsv($query));

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

/**
 * Un mois clos, réglé en une fois — condition du geste remplie (§5.1) : la
 * facture est couverte à 100 % avant la clôture, à la différence d'un mois
 * jamais réglé, que l'application refuserait de clore.
 *
 * Le versement tombe le 75ᵉ jour après le dépôt : la première tranche (jour
 * 60) est déjà passée et non soldée — elle porte donc 20 000 F — mais la
 * deuxième (jour 90) n'est jamais atteinte. Le montant clos égale exactement
 * cette courue, comme l'exige ReconcilePenaltySettlement.
 */
function settledCoveredMonth(Insurer $insurer, int $month, PenaltySettlement $outcome, int $amount): void
{
    $depositedOn = CarbonImmutable::create(2026, $month, 1);

    Declaration::factory()
        ->instalments([
            ['amount' => 1_000_000, 'paid_on' => $depositedOn->addDays(75)->toDateString()],
        ])
        ->penaltySettled($outcome, $amount)
        ->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => $month,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => $depositedOn,
        ]);
}

/**
 * $count officines, chacune une facture de 1 000 000 déposée il y a 120 jours
 * et jamais réglée : trois tranches de 20 000, soit 60 000 encore dues.
 */
function unpaidPenaltyMonths(Insurer $insurer, int $count, int $month): void
{
    foreach (range(1, $count) as $ignored) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => $month,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 0,
            'status' => DeclarationStatus::Unpaid,
            'is_status_manual' => true,
            'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
            'paid_on' => null,
            'delay_days' => null,
        ]);
    }
}

/**
 * La cellule d'un assureur, par nom de colonne.
 *
 * @param  array<string, mixed>  $query
 * @return Closure(string): string
 */
function networkCsvCell(string $insurerName, array $query = []): Closure
{
    $rows = networkCsvRows($query);
    $header = $rows[0];
    $row = collect($rows)->first(fn (array $r) => in_array($insurerName, $r, true));

    return fn (string $column): string => $row[array_search($column, $header, true)];
}

test('the csv carries the due, recovered and abandoned penalty amounts', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'NSIA', 'standard_delay_days' => 30]);

    // Chaque part repose sur cinq officines : cinq jamais réglées (60 000
    // chacune, encore dues), cinq closes payées et cinq closes annulées
    // (20 000 chacune).
    unpaidPenaltyMonths($insurer, 5, 2);

    foreach (range(1, 5) as $ignored) {
        settledCoveredMonth($insurer, 3, PenaltySettlement::Paid, 20_000);
        settledCoveredMonth($insurer, 4, PenaltySettlement::Waived, 20_000);
    }

    $cell = networkCsvCell('NSIA');

    expect($cell('penalite_potentielle_fcfa'))->toBe('300000')
        ->and($cell('penalite_recouvree_fcfa'))->toBe('100000')
        ->and($cell('penalite_abandonnee_fcfa'))->toBe('100000');
});

test('an empty part does not withhold the status split', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    // Cinq dues, cinq payées, aucune annulée : la part vide ne repose sur
    // personne, elle ne désigne donc personne.
    unpaidPenaltyMonths($insurer, 5, 2);

    foreach (range(1, 5) as $ignored) {
        settledCoveredMonth($insurer, 3, PenaltySettlement::Paid, 20_000);
    }

    $cell = networkCsvCell('NSIA');

    expect($cell('penalite_potentielle_fcfa'))->toBe('300000')
        ->and($cell('penalite_recouvree_fcfa'))->toBe('100000')
        ->and($cell('penalite_abandonnee_fcfa'))->toBe('0');
});

test('the status split is withheld together when one of its parts rests on too few officines', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    // Cinq officines, l'assureur passe le seuil. Une seule annule : publiée,
    // sa part abandonnée serait sa pénalité exacte (20 000), et la due, qui
    // repose sur quatre, le serait aussi par différence.
    unpaidPenaltyMonths($insurer, 4, 2);
    settledCoveredMonth($insurer, 4, PenaltySettlement::Waived, 20_000);

    $cell = networkCsvCell('NSIA');

    expect($cell('officines_declarantes'))->toBe('5')
        ->and($cell('facture_fcfa'))->toBe('5000000')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('')
        ->and($cell('penalite_recouvree_fcfa'))->toBe('')
        ->and($cell('penalite_abandonnee_fcfa'))->toBe('');
});

test('the report withholds the status split with the same rule, and says so', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    unpaidPenaltyMonths($insurer, 4, 2);
    settledCoveredMonth($insurer, 4, PenaltySettlement::Waived, 20_000);

    $export = app(NetworkPdfExport::class);
    $payload = (new ReflectionMethod($export, 'data'))->invoke($export, new Period(2026, 1), new Period(2026, 8), null);
    $html = view('exports.network', $payload)->render();

    expect($payload['rows'][0]['splitWithheld'])->toBeTrue()
        ->and($html)->toContain('Pénalité due')
        ->and($html)->toContain('retenu')
        ->and($html)->toContain('répartition due / recouvrée / abandonnée retenue')
        ->and($html)->not->toContain(Fcfa::format(20_000))
        ->and($html)->not->toContain(Fcfa::format(240_000));
});

test('the report publishes the status split when every part rests on enough officines', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    unpaidPenaltyMonths($insurer, 5, 2);

    foreach (range(1, 5) as $ignored) {
        settledCoveredMonth($insurer, 3, PenaltySettlement::Paid, 20_000);
    }

    $export = app(NetworkPdfExport::class);
    $payload = (new ReflectionMethod($export, 'data'))->invoke($export, new Period(2026, 1), new Period(2026, 8), null);
    $html = view('exports.network', $payload)->render();

    expect($payload['rows'][0]['splitWithheld'])->toBeFalse()
        ->and($html)->toContain(Fcfa::format(300_000))
        ->and($html)->toContain('dont recouvrée '.Fcfa::format(100_000));
});

test('a month whose status split the journal withholds withholds the export split too', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    // Sur la période, chaque part non vide repose sur au moins cinq
    // officines : six payées, cinq annulées. La règle de partition laisse
    // passer. Mais en mai, une seule officine a couru une part payée : le
    // journal retient le découpage de mai, et la recouvrée de l'export moins
    // la payée d'avril (publiée) rendrait les 20 000 de cette officine.
    foreach (range(1, 5) as $ignored) {
        settledCoveredMonth($insurer, 3, PenaltySettlement::Paid, 20_000);
        settledCoveredMonth($insurer, 4, PenaltySettlement::Waived, 20_000);
    }

    settledCoveredMonth($insurer, 4, PenaltySettlement::Paid, 20_000);

    $cell = networkCsvCell('NSIA');

    expect($cell('officines_declarantes'))->toBe('11')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('')
        ->and($cell('penalite_recouvree_fcfa'))->toBe('')
        ->and($cell('penalite_abandonnee_fcfa'))->toBe('');

    $export = app(NetworkPdfExport::class);
    $payload = (new ReflectionMethod($export, 'data'))->invoke($export, new Period(2025, 9), new Period(2026, 8), null);
    $html = view('exports.network', $payload)->render();

    expect($payload['rows'][0]['splitWithheld'])->toBeTrue()
        ->and($html)->toContain('un mois du journal des pénalités est retenu')
        ->and($html)->not->toContain(Fcfa::format(120_000));
});

test('a month the journal withholds entirely withholds the export split too', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    // Six officines payées, la partition passe. Mais mai ne repose que sur
    // une officine : le journal le retient en entier, et la recouvrée de
    // l'export moins avril le rendrait.
    foreach (range(1, 5) as $ignored) {
        settledCoveredMonth($insurer, 3, PenaltySettlement::Paid, 20_000);
    }

    settledCoveredMonth($insurer, 4, PenaltySettlement::Paid, 20_000);

    $cell = networkCsvCell('NSIA');

    expect($cell('officines_declarantes'))->toBe('6')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('')
        ->and($cell('penalite_recouvree_fcfa'))->toBe('')
        ->and($cell('penalite_abandonnee_fcfa'))->toBe('');
});

test('the export split is published when every month of the journal is', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    // Avril : cinq payées ; mai : cinq annulées. Aucun mois ni aucun
    // découpage retenu au journal.
    foreach (range(1, 5) as $ignored) {
        settledCoveredMonth($insurer, 3, PenaltySettlement::Paid, 20_000);
        settledCoveredMonth($insurer, 4, PenaltySettlement::Waived, 20_000);
    }

    $cell = networkCsvCell('NSIA');

    expect($cell('penalite_potentielle_fcfa'))->toBe('0')
        ->and($cell('penalite_recouvree_fcfa'))->toBe('100000')
        ->and($cell('penalite_abandonnee_fcfa'))->toBe('100000');

    $export = app(NetworkPdfExport::class);
    $payload = (new ReflectionMethod($export, 'data'))->invoke($export, new Period(2025, 9), new Period(2026, 8), null);

    expect($payload['rows'][0]['splitWithheld'])->toBeFalse();
});

test('without closures, a due resting on too few officines stays withheld', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    // Cinq officines déclarent mai, deux seulement laissent leur facture
    // impayée. Leur tranche tombe le 31 juillet, après la fin de période
    // (juin) : le journal n'a aucun mois retenu, seule la partition retient.
    $declare = fn (int $received) => Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 5,
        'amount_invoiced' => 1_000_000,
        'amount_received' => $received,
        'status' => $received === 0 ? DeclarationStatus::Unpaid : DeclarationStatus::Paid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-06-01',
        'paid_on' => $received === 0 ? null : '2026-06-20',
        'delay_days' => $received === 0 ? null : 19,
    ]);

    foreach (range(1, 3) as $ignored) {
        $declare(1_000_000);
    }

    $declare(0);
    $declare(0);

    [$from, $to] = [new Period(2026, 1), new Period(2026, 6)];

    expect(app(NetworkPenaltyJournal::class)->for($from, $to)->insurersWithWithheldMonths())->toBe([]);

    $row = collect(app(NetworkExportRows::class)->rows($from, $to))->first();
    $cell = fn (string $column) => $row[array_search($column, NetworkExportRows::COLUMNS, true)];

    expect($cell('officines_declarantes'))->toBe(5)
        ->and($cell('penalite_potentielle_fcfa'))->toBeNull();

    $export = app(NetworkPdfExport::class);
    $payload = (new ReflectionMethod($export, 'data'))->invoke($export, $from, $to, null);

    expect($payload['rows'][0]['splitWithheld'])->toBeTrue()
        ->and(view('exports.network', $payload)->render())->not->toContain(Fcfa::format(40_000));
});

test('a withheld insurer leaves the due, recovered and abandoned penalty columns empty too', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Trop peu retenu']);

    exportDeclare($insurer, 2);

    // Une troisième officine, dont le mois est clos payé : toujours sous le
    // seuil, mais l'assureur a bien un montant recouvré sur la période — la
    // colonne doit rester vide malgré tout, pas seulement quand rien n'a été
    // réglé.
    settledCoveredMonth($insurer, 8, PenaltySettlement::Paid, 20_000);

    $rows = networkCsvRows();
    $header = $rows[0];
    $row = collect($rows)->first(fn (array $r) => in_array('Trop peu retenu', $r, true));
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('penalite_potentielle_fcfa'))->toBe('')
        ->and($cell('penalite_recouvree_fcfa'))->toBe('')
        ->and($cell('penalite_abandonnee_fcfa'))->toBe('');
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

test('the city filter re-applies the threshold inside the city', function () {
    $insurer = Insurer::factory()->create(['name' => 'NSIA Assurances']);

    /** Une déclaration d'une officine neuve, dans la ville voulue. */
    $declareIn = function (string $city, int $invoiced) use ($insurer): void {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => $invoiced,
            'amount_received' => $invoiced,
            'delay_days' => 20,
        ]);
    };

    // Cinq officines à Cotonou : l'assureur franchit le seuil nationalement.
    foreach (range(1, 5) as $ignored) {
        $declareIn('Cotonou', 1_000_000);
    }

    // Une seule à Parakou.
    $declareIn('Parakou', 4_210_000);

    $rows = networkCsvRows(['city' => 'Parakou']);
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    // Une clairance nationale ne vaut pas clairance dans chaque ville : dans
    // Parakou cet assureur n'est déclaré que par une officine, et ses chiffres
    // seraient les siens, exactement.
    expect($cell('assureur'))->toBe('NSIA Assurances')
        ->and($cell('officines_declarantes'))->toBe('1')
        ->and($cell('facture_fcfa'))->toBe('')
        ->and($cell('delai_le_plus_long_jours'))->toBe('')
        ->and(downloadCsv(['city' => 'Parakou']))->not->toContain('4210000');
});

test('choosing an insurer leaves only that insurer in the report', function () {
    $chosen = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    $other = Insurer::factory()->create(['name' => 'Atlantique Assurances']);

    exportDeclare($chosen, 5, ['amount_received' => 1_000_000, 'delay_days' => 44]);
    exportDeclare($other, 5, ['amount_received' => 1_000_000, 'delay_days' => 12]);

    $export = app(NetworkPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, new Period(2026, 8), new Period(2026, 8), null, $chosen->id);

    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['name'])->toBe('NSIA Assurances')
        // Le résumé se resserre aussi : c'est le sens de « tout le document ».
        ->and($payload['summary']['declarations'])->toBe(5)
        ->and($payload['withheld'])->toBeEmpty();
});

test('choosing an insurer below the threshold withholds its summary too', function () {
    // Le résumé n'applique aucun seuil quand il couvre tout le réseau : aucune
    // officine n'y est nommable. Restreint à un assureur, il devient les
    // chiffres de cet assureur — et une seule officine déclarante rendrait sa
    // facture exacte lisible. Le filtre ne doit pas ouvrir cette porte.
    $hidden = Insurer::factory()->create(['name' => 'Petit Assureur']);

    exportDeclare($hidden, 1, ['amount_invoiced' => 7_654_321, 'amount_received' => 0]);

    $export = app(NetworkPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, new Period(2026, 8), new Period(2026, 8), null, $hidden->id);

    expect($payload['rows'])->toBeEmpty()
        ->and($payload['withheld'])->toHaveCount(1)
        ->and($payload['summary'])->toBeNull();
});

test('the chosen insurer narrows the csv to its single row', function () {
    $chosen = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    $other = Insurer::factory()->create(['name' => 'Atlantique Assurances']);

    exportDeclare($chosen, 5);
    exportDeclare($other, 5);

    $csv = downloadCsv(['insurer' => $chosen->id]);

    expect($csv)->toContain('NSIA Assurances')
        ->and($csv)->not->toContain('Atlantique Assurances');
});

test('the filename names the insurer the file covers', function () {
    $chosen = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    exportDeclare($chosen, 5);

    $disposition = $this->actingAs($this->admin)
        ->get(route('admin.csv-exports.download', ['insurer' => $chosen->id]))
        ->headers->get('content-disposition');

    // Sans le nom, deux fichiers d'assureurs différents se ressemblent trait
    // pour trait dans un dossier de téléchargements.
    expect($disposition)->toContain('nsia-assurances');
});

test('the report still renders when the summary is withheld', function () {
    // Le test de données ci-dessus prouve que `summary` vaut null ; celui-ci
    // prouve que la vue le supporte. Sans lui, un `$summary['declarations']`
    // resté dans le Blade ne rougirait qu'en production.
    $hidden = Insurer::factory()->create(['name' => 'Petit Assureur']);
    exportDeclare($hidden, 1);

    $this->actingAs($this->admin)
        ->get(route('admin.csv-exports.download', ['format' => 'pdf', 'insurer' => $hidden->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('the page offers the insurers the network declared to', function () {
    $declared = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    Insurer::factory()->create(['name' => 'Jamais Déclaré']);

    exportDeclare($declared, 5);

    $this->actingAs($this->admin)
        ->get(route('admin.csv-exports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Exports')
            // Un assureur sans une seule déclaration ne ferait qu'un fichier
            // vide : la liste ne propose que ce qui a de quoi être exporté.
            ->has('insurers', 1)
            ->where('insurers.0.name', 'NSIA Assurances')
            ->where('insurer', null),
        );
});
