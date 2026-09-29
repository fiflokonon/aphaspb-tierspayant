<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkPdfExport;
use App\Services\Network\NetworkPenaltyJournal;
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
});

describe('the review scenario', function () {
    beforeEach(function () {
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
});

/**
 * Une facture d'avril jamais réglée, déposée le 30/04 : sous clause 60 j / 2 %,
 * elle court à partir du 29/06 (20 000 par million et par mois).
 */
function aprilUnpaid(Pharmacy $pharmacy, Insurer $insurer, int $invoiced = 1_000_000): void
{
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 4,
        'amount_invoiced' => $invoiced,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-04-30',
        'paid_on' => null,
        'delay_days' => null,
    ]);
}

describe('the two-step rebuild through an insurer withheld by the city partition', function () {
    beforeEach(function () {
        // A : Cotonou ×5 + une seule officine de Bohicon, à 7 000 000. B :
        // Cotonou ×5 + Bohicon ×5. A est retenu (« city-share ») et reste
        // dans la synthèse ; synthèse − B − A_Cotonou rendait la Bohiconnaise.
        $this->first = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Assureur A']);
        $this->second = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Assureur B']);

        $this->cotonou = Pharmacy::factory()->count(5)->create(['city' => 'Cotonou']);
        $this->bohicon = Pharmacy::factory()->count(5)->create(['city' => 'Bohicon']);

        $this->cotonou->each(function (Pharmacy $pharmacy) {
            aprilUnpaid($pharmacy, $this->first);
            aprilUnpaid($pharmacy, $this->second);
        });
        $this->bohicon->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $this->second));
        aprilUnpaid($this->bohicon->first(), $this->first, 7_000_000);
    });

    test('the unfiltered summary no longer gives the city-share insurer hidden cell back', function () {
        $props = fn (array $query): array => $this->actingAs($this->admin)
            ->get(route('admin.trends', $query))
            ->viewData('page')['props'];

        $everyone = $props([]);
        $cotonou = $props(['city' => 'Cotonou']);
        $row = fn (array $page, string $name) => collect($page['amounts'])->firstWhere('insurerName', $name);

        // Les briques de la soustraction restent publiées : B, et A à Cotonou.
        expect($row($everyone, 'Assureur B')['invoiced'])->toBe(10_000_000)
            ->and($row($everyone, 'Assureur A')['withheldReason'])->toBe('city-share')
            ->and($row($cotonou, 'Assureur A')['invoiced'])->toBe(5_000_000)
            // Mais plus la synthèse (22 000 000) qui fermait la boucle.
            ->and($everyone['summary']['withheld'])->toBeTrue()
            ->and($everyone['summary']['withheldReason'])->toBe('city-share')
            ->and($everyone['summary']['invoiced'])->toBeNull()
            ->and($everyone['summary']['outstandingBeyond90'])->toBeNull()
            ->and(json_encode($everyone))->not->toContain('22000000');
    });

    test('the unfiltered journal total no longer gives it back either', function () {
        $journal = app(NetworkPenaltyJournal::class);
        $everyone = $journal->for(new Period(2026, 1), new Period(2026, 9));
        $cotonou = $journal->for(new Period(2026, 1), new Period(2026, 9), 'Cotonou');

        $second = collect($everyone->insurers)->firstWhere('insurerId', $this->second->id);
        $firstInCotonou = collect($cotonou->insurers)->firstWhere('insurerId', $this->first->id);

        // Juin : B 200 000 et A_Cotonou 100 000 publiés ; le total (440 000)
        // les aurait complétés en 140 000, la pénalité de la Bohiconnaise.
        expect($second->month('2026-06')->accrued)->toBe(200_000)
            ->and($firstInCotonou->month('2026-06')->accrued)->toBe(100_000)
            ->and($everyone->total->month('2026-06')->withheld)->toBeTrue()
            ->and($everyone->total->month('2026-06')->accrued)->toBeNull()
            ->and($everyone->total->month('2026-06')->declared)->toBeNull();
    });
});

/**
 * Une facture d'avril réglée à temps (dépôt 30/04, versement 10/05) : rien ne
 * court. Dates explicites, pour un décor déterministe.
 */
function aprilPaidOnTime(Pharmacy $pharmacy, Insurer $insurer): void
{
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 4,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'invoice_deposited_on' => '2026-04-30',
        'paid_on' => '2026-05-10',
    ]);
}

describe('the month-level rebuild through an authorised insurer', function () {
    beforeEach(function () {
        // A : Cotonou ×5 impayées + une Bohiconnaise impayée à 7 000 000 +
        // quatre Bohiconnaises réglées à temps : A est autorisé sur la
        // période, mais son mois de juin, non filtré, est retenu par la
        // partition. B : Cotonou ×5 + Bohicon ×5.
        $this->first = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Assureur A']);
        $this->second = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Assureur B']);

        $cotonou = Pharmacy::factory()->count(5)->create(['city' => 'Cotonou']);
        $bohicon = Pharmacy::factory()->count(5)->create(['city' => 'Bohicon']);

        $cotonou->each(function (Pharmacy $pharmacy) {
            aprilUnpaid($pharmacy, $this->first);
            aprilUnpaid($pharmacy, $this->second);
        });
        $bohicon->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $this->second));
        aprilUnpaid($bohicon->first(), $this->first, 7_000_000);
        $bohicon->skip(1)->each(fn (Pharmacy $pharmacy) => aprilPaidOnTime($pharmacy, $this->first));
    });

    test('the unfiltered total no longer completes a series month the city partition withholds', function () {
        $journal = app(NetworkPenaltyJournal::class);
        $everyone = $journal->for(new Period(2026, 1), new Period(2026, 9));
        $cotonou = $journal->for(new Period(2026, 1), new Period(2026, 9), 'Cotonou');

        $series = fn ($ledger, Insurer $insurer) => collect($ledger->insurers)->firstWhere('insurerId', $insurer->id);

        // Juin : A retenu, B 200 000, A_Cotonou 100 000 ; le total (440 000)
        // les aurait complétés en 140 000, la pénalité de la Bohiconnaise.
        expect($series($everyone, $this->first)->month('2026-06')->withheld)->toBeTrue()
            ->and($series($everyone, $this->second)->month('2026-06')->accrued)->toBe(200_000)
            ->and($series($cotonou, $this->first)->month('2026-06')->accrued)->toBe(100_000)
            ->and($everyone->total->month('2026-06')->withheld)->toBeTrue()
            ->and($everyone->total->month('2026-06')->accrued)->toBeNull();
    });
});

describe('a control where the withheld series months hide enough officines', function () {
    beforeEach(function () {
        // A cache deux Bohiconnaises en juin, C trois Parakoises : union 5.
        [$a, $b, $c, $d] = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->count(4)->create()->all();
        $cotonou = Pharmacy::factory()->count(5)->create(['city' => 'Cotonou']);
        $bohicon = Pharmacy::factory()->count(5)->create(['city' => 'Bohicon']);
        $parakou = Pharmacy::factory()->count(5)->create(['city' => 'Parakou']);

        $cotonou->each(function (Pharmacy $pharmacy) use ($a, $c) {
            aprilUnpaid($pharmacy, $a);
            aprilUnpaid($pharmacy, $c);
        });
        $bohicon->take(2)->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $a));
        $bohicon->skip(2)->each(fn (Pharmacy $pharmacy) => aprilPaidOnTime($pharmacy, $a));
        $parakou->take(3)->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $c));
        $parakou->skip(3)->each(fn (Pharmacy $pharmacy) => aprilPaidOnTime($pharmacy, $c));
        $bohicon->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $b));
        $parakou->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $d));
    });

    test('the unfiltered journal total is published', function () {
        $june = app(NetworkPenaltyJournal::class)->for(new Period(2026, 1), new Period(2026, 9))->total->month('2026-06');

        // A 7 + C 8 + B 5 + D 5 factures impayées : 25 × 20 000.
        expect($june->withheld)->toBeFalse()
            ->and($june->accrued)->toBe(500_000);
    });
});

describe('a control where the city-share insurers hide enough officines', function () {
    beforeEach(function () {
        // A cache deux Bohiconnaises, C trois Parakoises : leurs cellules
        // cachées, ensemble, reposent sur cinq officines distinctes.
        $a = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Assureur A']);
        $b = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Assureur B']);
        $c = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Assureur C']);
        $d = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'Assureur D']);

        $cotonou = Pharmacy::factory()->count(5)->create(['city' => 'Cotonou']);
        $bohicon = Pharmacy::factory()->count(5)->create(['city' => 'Bohicon']);
        $parakou = Pharmacy::factory()->count(5)->create(['city' => 'Parakou']);

        $cotonou->each(function (Pharmacy $pharmacy) use ($a, $c) {
            aprilUnpaid($pharmacy, $a);
            aprilUnpaid($pharmacy, $c);
        });
        $bohicon->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $b));
        $parakou->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $d));
        $bohicon->take(2)->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $a));
        $parakou->take(3)->each(fn (Pharmacy $pharmacy) => aprilUnpaid($pharmacy, $c));
    });

    test('the unfiltered summary and journal total are published', function () {
        $summary = $this->actingAs($this->admin)->get(route('admin.trends'))->viewData('page')['props']['summary'];
        $journal = app(NetworkPenaltyJournal::class)->for(new Period(2026, 1), new Period(2026, 9));

        // 25 factures : 5 A + 5 C à Cotonou, 5 B, 5 D, 2 A, 3 C.
        expect($summary['withheld'])->toBeFalse()
            ->and($summary['invoiced'])->toBe(25_000_000)
            ->and($journal->total->month('2026-06')->withheld)->toBeFalse()
            ->and($journal->total->month('2026-06')->accrued)->toBe(500_000);
    });
});
