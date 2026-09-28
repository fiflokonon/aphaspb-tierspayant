<?php

use App\Data\Period;
use App\Enums\CompletenessState;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\DeclarationCompleteness;
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

test('an officine without insurer, deleted, or registered after the month is not tracked', function () {
    Pharmacy::factory()->create(['name' => 'Sans assureur', 'created_at' => '2026-01-10']);
    trackedPharmacy('Supprimée')->delete();
    trackedPharmacy('Trop récente', ['created_at' => '2026-09-02']);
    trackedPharmacy('Suivie');

    expect(array_keys(statesFor($this->completeness->forMonth(new Period(2026, 8)))))->toBe(['Suivie']);
});

test('an officine registered during the month is tracked for it, not for the month before', function () {
    trackedPharmacy('Arrivée le 15 août', ['created_at' => '2026-08-15']);

    expect($this->completeness->forMonth(new Period(2026, 8)))->toHaveCount(1)
        ->and($this->completeness->forMonth(new Period(2026, 7)))->toHaveCount(0);
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
