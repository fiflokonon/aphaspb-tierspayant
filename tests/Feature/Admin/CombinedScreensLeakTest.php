<?php

use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkPdfExport;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/**
 * La fuite trouvée en revue le 28/09/2026, en combinant les écrans.
 *
 * Une ville de cinq officines où une seule a déclaré ce trimestre : le suivi
 * la nomme (« Partielle »), et si « Statistiques réseau », « Évolution » ou
 * l'export filtrés sur cette ville publiaient le moindre chiffre — même le
 * seul « 1 officine déclarante » —, l'admin apprendrait quels assureurs cette
 * officine a déclarés et combien elle a facturé.
 */
beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 28));
    $this->admin = User::factory()->networkAdmin()->create();

    $this->insurers = collect(['Assureur Un', 'Assureur Deux', 'Assureur Trois'])
        ->map(fn (string $name) => Insurer::factory()->create(['name' => $name]));

    // Cinq officines à Bohicon, chacune sous convention avec les trois.
    $this->city = collect(range(1, 5))->map(function (int $i) {
        $pharmacy = Pharmacy::factory()->create([
            'name' => 'Pharmacie Bohicon '.$i,
            'city' => 'Bohicon',
            'created_at' => '2026-01-10',
        ]);
        $pharmacy->insurers()->attach($this->insurers->pluck('id'));

        return $pharmacy;
    });

    // La seule déclarante : deux assureurs sur trois, en août (trimestre en
    // cours, mois terminé). Montants distinctifs, pour que leur absence se
    // cherche sans ambiguïté.
    $this->declarant = $this->city->first();

    foreach ([[0, 3_217_000], [1, 1_845_000]] as [$index, $invoiced]) {
        Declaration::factory()->create([
            'pharmacy_id' => $this->declarant->id,
            'insurer_id' => $this->insurers[$index]->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => $invoiced,
            'amount_received' => 0,
            'delay_days' => null,
        ]);
    }

    // Ailleurs, assez de déclarantes pour que le premier assureur soit publié
    // à l'échelle nationale : le seuil doit se rejouer dans la ville.
    Pharmacy::factory()->count(5)->create(['city' => 'Cotonou'])->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $this->insurers[0]->id,
            'period_year' => 2026,
            'period_month' => 8,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 1_000_000,
            'delay_days' => 20,
        ]),
    );
});

test('the follow-up still names the lone declarant as partial', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.declarations-followup', ['month' => '2026-08', 'city' => 'Bohicon', 'state' => 'partial']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pharmacies.data', 1)
            ->where('pharmacies.data.0.name', 'Pharmacie Bohicon 1')
            ->where('pharmacies.data.0.stateLabel', 'Partielle')
            ->where('summary', ['complete' => 0, 'partial' => 1, 'none' => 4, 'total' => 5]));
});

test('the network screen filtered on the city exposes neither the count nor a figure', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.network', ['city' => 'Bohicon']))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];
            $indicators = collect($props['indicators']);

            expect($indicators)->toHaveCount(2)
                ->and($indicators->pluck('sufficient')->unique()->all())->toBe([false])
                ->and($indicators->pluck('declaringPharmacies')->unique()->all())->toBe([null])
                ->and($indicators->pluck('required')->unique()->all())->toBe([5])
                ->and($props['summary']['withheld'])->toBeTrue()
                ->and($props['summary']['declaringPharmacies'])->toBeNull()
                ->and($props['summary']['declarations'])->toBeNull()
                ->and($props['summary']['averageDelayDays'])->toBeNull();
        });
});

test('the network screen filtered on the other city still publishes the cleared insurer', function () {
    // Contrôle. Avant la règle de partition (round 2), ce test lisait l'écran
    // non filtré et y attendait l'assureur publié à 6 officines : c'est
    // précisément ce chiffre, moins Cotonou, qui rendait la déclarante.
    $this->actingAs($this->admin)
        ->get(route('admin.network', ['city' => 'Cotonou']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('indicators.0.insurerName', 'Assureur Un')
            ->where('indicators.0.sufficient', true)
            ->where('indicators.0.declaringPharmacies', 5)
            ->where('summary.withheld', false)
            ->where('summary.declaringPharmacies', 5));
});

test('the trends screen filtered on the city exposes neither the count nor the amounts', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.trends', ['city' => 'Bohicon']))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];

            $page->loadDeferredProps(function (AssertableInertia $reload) use (&$props) {
                $props = [...$props, ...$reload->toArray()['props']];
            });

            $json = json_encode($props);

            expect($props['summary']['withheld'])->toBeTrue()
                ->and($props['summary']['invoiced'])->toBeNull()
                ->and($props['summary']['outstanding'])->toBeNull()
                ->and($props['summary']['declaringPharmacies'])->toBeNull()
                ->and(collect($props['amounts'])->pluck('declaringPharmacies')->unique()->all())->toBe([null])
                ->and(collect($props['amounts'])->pluck('invoiced')->unique()->all())->toBe([null])
                // Les deux assureurs sont retenus dans la ville : pas de courbe.
                ->and($props['trend']['network'])->toBe([])
                ->and($props['trend']['insurers'])->toBe([])
                ->and($json)->not->toContain('3217000')
                ->and($json)->not->toContain('1845000')
                ->and($json)->not->toContain('5062000')
                ->and($json)->not->toContain('"declaringPharmacies":1');
        });
});

test('the network csv and pdf for the city expose neither the count nor a figure', function () {
    $csv = $this->actingAs($this->admin)
        ->get(route('admin.csv-exports.download', ['city' => 'Bohicon', 'period' => 'current-quarter']))
        ->streamedContent();

    $rows = array_map(fn (string $line) => str_getcsv($line, ';'), array_values(array_filter(explode("\n", $csv))));
    $count = array_search('officines_declarantes', $rows[0], true);

    expect(array_slice($rows, 1))->toHaveCount(2)
        ->and(array_unique(array_column(array_slice($rows, 1), $count)))->toBe(['moins de 5']);

    foreach (['3217000', '1845000', '5062000'] as $figure) {
        expect($csv)->not->toContain($figure);
    }

    $export = app(NetworkPdfExport::class);
    $payload = (new ReflectionMethod($export, 'data'))->invoke($export, new Period(2026, 7), new Period(2026, 9), 'Bohicon');
    $html = view('exports.network', $payload)->render();

    expect($payload['summary']['withheld'])->toBeTrue()
        ->and($payload['rows'])->toBeEmpty()
        ->and($html)->toContain('Synthèse retenue')
        ->and($html)->toContain('moins de 5 officines déclarantes')
        ->and($html)->not->toContain('1 officine')
        ->and($html)->not->toContain('déclarations déposées par');

    foreach (['3 217 000', '1 845 000', '5 062 000'] as $figure) {
        expect(str_replace("\u{202F}", ' ', $html))->not->toContain($figure);
    }
});

test('unfiltered minus the published city no longer gives the lone declarant back', function () {
    // Revue du 28/09/2026, second tour : les villes partitionnent le réseau.
    // Non filtré (6 officines, 10 062 000) moins Cotonou (5, 5 000 000) rendait
    // Bohicon, soit la déclarante unique : 5 062 000 ; et l'assureur Un non
    // filtré (8 217 000) moins Cotonou, sa part : 3 217 000.
    $props = fn (array $query): array => $this->actingAs($this->admin)
        ->get(route('admin.trends', $query))
        ->viewData('page')['props'];

    $everyone = $props([]);
    $cotonou = $props(['city' => 'Cotonou']);

    $first = collect($everyone['amounts'])->firstWhere('insurerName', 'Assureur Un');
    $firstInCotonou = collect($cotonou['amounts'])->firstWhere('insurerName', 'Assureur Un');

    expect($cotonou['summary']['withheld'])->toBeFalse()
        ->and($cotonou['summary']['invoiced'])->toBe(5_000_000)
        ->and($firstInCotonou['invoiced'])->toBe(5_000_000)
        ->and($everyone['summary']['withheld'])->toBeTrue()
        ->and($everyone['summary']['withheldReason'])->toBe('city-share')
        ->and($everyone['summary']['invoiced'])->toBeNull()
        ->and($everyone['summary']['declaringPharmacies'])->toBeNull()
        ->and($first['sufficient'])->toBeFalse()
        ->and($first['withheldReason'])->toBe('city-share')
        ->and($first['invoiced'])->toBeNull()
        ->and(json_encode($everyone))->not->toContain('10062000')
        ->and(json_encode($everyone))->not->toContain('8217000');

    $network = $this->actingAs($this->admin)->get(route('admin.network'))->viewData('page')['props'];

    expect($network['summary']['withheld'])->toBeTrue()
        ->and(collect($network['indicators'])->firstWhere('insurerName', 'Assureur Un')['sufficient'])->toBeFalse();

    $csv = $this->actingAs($this->admin)
        ->get(route('admin.csv-exports.download', ['period' => 'current-quarter']))
        ->streamedContent();

    expect($csv)->not->toContain('8217000')
        ->and($csv)->not->toContain('10062000');
});
