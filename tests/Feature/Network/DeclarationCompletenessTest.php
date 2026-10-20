<?php

use App\Data\Period;
use App\Enums\CompletenessState;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\NilDeclaration;
use App\Models\Pharmacy;
use App\Services\Declarations\DeclarationRange;
use App\Services\Network\DeclarationCompleteness;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 28));
    $this->completeness = app(DeclarationCompleteness::class);
    $this->insurers = Insurer::factory()->count(2)->create();
});

/**
 * Une officine inscrite en janvier, avec les deux assureurs du décor.
 *
 * @param  array<string, mixed>  $attributes
 */
function trackedPharmacy(string $name, array $attributes = []): Pharmacy
{
    $pharmacy = Pharmacy::factory()->create(['name' => $name, 'created_at' => '2026-01-10', ...$attributes]);
    $pharmacy->insurers()->attach(test()->insurers->pluck('id'));

    return $pharmacy;
}

function declareAugust(Pharmacy $pharmacy, Insurer $insurer, array $attributes = []): void
{
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        ...$attributes,
    ]);
}

function statesFor(array $rows): array
{
    return collect($rows)->mapWithKeys(fn ($row) => [$row->name => $row->state])->all();
}

test('complete, partial and none on one month', function () {
    [$first, $second] = $this->insurers;
    $complete = trackedPharmacy('A Complète');
    $partial = trackedPharmacy('B Partielle');
    trackedPharmacy('C Rien');
    declareAugust($complete, $first);
    declareAugust($complete, $second);
    declareAugust($partial, $first);

    expect(statesFor($this->completeness->forMonth(new Period(2026, 8))))->toBe([
        'A Complète' => CompletenessState::Complete,
        'B Partielle' => CompletenessState::Partial,
        'C Rien' => CompletenessState::None,
    ]);
});

test('an officine without insurer, deleted, or registered beyond its catch-up is not tracked', function () {
    app(SettingsRepository::class)->set(SettingsRepository::CATCH_UP_MONTHS, 0);
    Pharmacy::factory()->create(['name' => 'Sans assureur', 'created_at' => '2026-01-10']);
    trackedPharmacy('Supprimée')->delete();
    trackedPharmacy('Trop récente', ['created_at' => '2026-09-02']);
    trackedPharmacy('Suivie');

    expect(array_keys(statesFor($this->completeness->forMonth(new Period(2026, 8)))))->toBe(['Suivie']);
});

test('a new officine is chased for the catch-up months before its registration, and no further', function () {
    app(SettingsRepository::class)->set(SettingsRepository::CATCH_UP_MONTHS, 2);
    trackedPharmacy('Arrivée le 15 août', ['created_at' => '2026-08-15']);

    // Inscrite en août, deux mois de rattrapage : juin et juillet lui sont
    // réclamés, mai non.
    expect($this->completeness->forMonth(new Period(2026, 8)))->toHaveCount(1)
        ->and($this->completeness->forMonth(new Period(2026, 6)))->toHaveCount(1)
        ->and($this->completeness->forMonth(new Period(2026, 5)))->toHaveCount(0);
});

test('nothing is chased below the declaration floor', function () {
    trackedPharmacy('Fondatrice', ['created_at' => '2024-01-10']);
    app(SettingsRepository::class)->set(SettingsRepository::DECLARATION_FLOOR, (new Period(2026, 3))->toOrdinal());

    expect($this->completeness->forMonth(new Period(2026, 3)))->toHaveCount(1)
        ->and($this->completeness->forMonth(new Period(2026, 2)))->toHaveCount(0);
});

test('a rejected declaration counts, one for an insurer no longer ticked does not', function () {
    [$first, $second] = $this->insurers;
    $pharmacy = trackedPharmacy('Mixte');
    declareAugust($pharmacy, $first, ['status' => DeclarationStatus::Rejected, 'is_status_manual' => true]);
    declareAugust($pharmacy, Insurer::factory()->create());

    expect(statesFor($this->completeness->forMonth(new Period(2026, 8))))->toBe(['Mixte' => CompletenessState::Partial]);
});

test('the city filter narrows the roll', function () {
    trackedPharmacy('Cotonou 1', ['city' => 'Cotonou']);
    trackedPharmacy('Parakou 1', ['city' => 'Parakou']);

    expect(array_keys(statesFor($this->completeness->forMonth(new Period(2026, 8), 'Parakou'))))->toBe(['Parakou 1']);
});

test('the query count stays flat however many officines there are', function () {
    trackedPharmacy('Seule');
    // Le premier accès fige le plancher en base : un coût unique, pas un N+1.
    app(DeclarationRange::class)->floor();
    app(DeclarationRange::class)->catchUpMonths();
    DB::enableQueryLog();
    $this->completeness->forMonth(new Period(2026, 8));
    $withOne = count(DB::getQueryLog());

    foreach (range(1, 20) as $n) {
        declareAugust(trackedPharmacy("Officine {$n}"), $this->insurers[0]);
    }
    DB::flushQueryLog();
    $this->completeness->forMonth(new Period(2026, 8));

    expect(count(DB::getQueryLog()))->toBe($withOne)->and($withOne)->toBe(2);
});

test('an insurer reported without invoice completes the month like a declaration', function () {
    [$first, $second] = $this->insurers;
    $pharmacy = trackedPharmacy('Sans facture NSIA');
    declareAugust($pharmacy, $first);
    NilDeclaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $second->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    expect(statesFor($this->completeness->forMonth(new Period(2026, 8))))
        ->toBe(['Sans facture NSIA' => CompletenessState::Complete]);
});
