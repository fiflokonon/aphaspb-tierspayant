<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Declarations\LongestDelay;
use App\Services\Network\InsurerPenaltyAggregates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->aggregates = app(InsurerPenaltyAggregates::class);
    $this->bounds = [new Period(2025, 10), new Period(2026, 9)];
});

/**
 * Une déclaration d'une officine neuve, pour que chaque ligne compte une officine.
 *
 * @param  array<string, mixed>  $attributes
 */
function penaltyDeclare(Insurer $insurer, int $month, array $attributes = []): Declaration
{
    return Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => $month,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'delay_days' => 20,
        ...$attributes,
    ]);
}

/**
 * Une facture déposée il y a $daysAgo jours et jamais réglée.
 *
 * @return array<string, mixed>
 */
function neverSettled(int $daysAgo): array
{
    return [
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 9, 19)->subDays($daysAgo),
        'paid_on' => null,
        'delay_days' => null,
    ];
}

test('an insurer with no clause has no penalty but still has a longest delay', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    penaltyDeclare($insurer, 7, ['delay_days' => 44]);

    $figures = $this->aggregates->forInsurers([$insurer->id], ...$this->bounds);

    expect($figures[$insurer->id]->penalty)->toBeNull()
        ->and($figures[$insurer->id]->longestDelayDays)->toBe(44);
});

test('an insurer under a clause with nothing accrued reads zero, not null', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 90, ratePercent: 2.0)->create();
    // Réglée en 20 jours : la pénalité ne se déclenche qu'au 90e.
    penaltyDeclare($insurer, 8);

    expect($this->aggregates->forInsurers([$insurer->id], ...$this->bounds)[$insurer->id]->penalty)
        ->toBe(0);
});

test('the penalty sums across the officines of one insurer', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();

    // Deux officines, chacune une facture de 1 000 000 déposée il y a 120
    // jours et jamais réglée : trois tranches à 20 000 chacune, deux fois.
    foreach ([1, 2] as $month) {
        penaltyDeclare($insurer, $month, neverSettled(120));
    }

    // Deux lignes qui doivent rester hors du total, et qui l'inflateraient si
    // les gardes tombaient : une facture refusée n'est pas due, et une facture
    // jamais déposée n'a pas d'horloge à faire courir.
    penaltyDeclare($insurer, 3, [...neverSettled(400), 'status' => DeclarationStatus::Rejected]);

    // La date de dépôt est effacée par requête et non par la fabrique :
    // DeclarationFactory::configure() la remplit toujours quand elle est nulle,
    // donc la passer en attribut ne produirait pas l'état visé.
    $undeposited = penaltyDeclare($insurer, 4, neverSettled(400));
    DB::table('declarations')->where('id', $undeposited->id)->update(['invoice_deposited_on' => null]);

    expect($this->aggregates->forInsurers([$insurer->id], ...$this->bounds)[$insurer->id]->penalty)
        ->toBe(120_000);
});

test('the longest delay agrees with the per-declaration implementation', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    // Un mois soldé tard, un partiellement réglé qui traîne, un impayé, un
    // rejeté : les quatre cas que LongestDelay distingue.
    penaltyDeclare($insurer, 3, ['delay_days' => 55]);

    Declaration::factory()->instalments([['amount' => 100_000, 'paid_on' => '2026-01-10']])->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 1,
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-01-01',
    ]);

    penaltyDeclare($insurer, 4, neverSettled(150));
    penaltyDeclare($insurer, 5, [...neverSettled(400), 'status' => DeclarationStatus::Rejected]);

    $viaSql = $this->aggregates->forInsurers([$insurer->id], ...$this->bounds)[$insurer->id]->longestDelayDays;

    $viaDeclarations = app(LongestDelay::class)->for(
        Declaration::query()->where('insurer_id', $insurer->id)->get(),
    );

    // 261 jours pour la partielle du 1er janvier : c'est elle qui gagne, pas
    // les 55 du mois soldé ni les 400 du rejeté, qui ne compte pas.
    expect($viaSql)->toBe($viaDeclarations)
        ->and($viaSql)->toBe(261);
});

test('an insurer absent from the allowed list is absent from the result', function () {
    $allowed = Insurer::factory()->withPenalty()->create();
    $hidden = Insurer::factory()->withPenalty()->create();

    penaltyDeclare($allowed, 7);
    penaltyDeclare($hidden, 7);

    $figures = $this->aggregates->forInsurers([$allowed->id], ...$this->bounds);

    expect($figures)->toHaveKey($allowed->id)
        ->and($figures)->not->toHaveKey($hidden->id);
});

test('an insurer with no declaration at all still gets an entry', function () {
    $withClause = Insurer::factory()->withPenalty()->create();
    $without = Insurer::factory()->create();

    $figures = $this->aggregates->forInsurers([$withClause->id, $without->id], ...$this->bounds);

    expect($figures[$withClause->id]->penalty)->toBe(0)
        ->and($figures[$withClause->id]->longestDelayDays)->toBeNull()
        ->and($figures[$without->id]->penalty)->toBeNull()
        ->and($figures[$without->id]->longestDelayDays)->toBeNull();
});

test('a period outside the window contributes nothing', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    penaltyDeclare($insurer, 1, ['delay_days' => 300]);

    $figures = $this->aggregates->forInsurers([$insurer->id], new Period(2026, 6), new Period(2026, 9));

    expect($figures[$insurer->id]->longestDelayDays)->toBeNull();
});

test('the city filter narrows the aggregate like every other network read', function () {
    // Sous convention : sans clause, penaltiesByInsurer() sort tôt et la
    // requête jointe sur declaration_payments — celle dont les colonnes
    // doivent être qualifiées — n'est jamais exercée avec une ville.
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory()->create(['city' => 'Cotonou']),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'delay_days' => 44,
    ]);

    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory()->create(['city' => 'Parakou']),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'delay_days' => 300,
    ]);

    // Une troisième, à Parakou, impayée depuis 120 jours : elle porterait
    // 60 000 de pénalité si le filtre de ville ne traversait pas la requête
    // jointe des versements.
    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory()->create(['city' => 'Parakou']),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 6,
        ...neverSettled(120),
    ]);

    $figures = $this->aggregates->forInsurers([$insurer->id], ...[...$this->bounds, 'Cotonou']);

    expect($figures[$insurer->id]->longestDelayDays)->toBe(44)
        ->and($figures[$insurer->id]->penalty)->toBe(0);
});

test('the query count stays flat however many declarations there are', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();

    penaltyDeclare($insurer, 1);
    DB::enableQueryLog();
    $this->aggregates->forInsurers([$insurer->id], ...$this->bounds);
    $withOne = count(DB::getQueryLog());

    foreach (range(2, 12) as $month) {
        penaltyDeclare($insurer, $month);
    }

    DB::flushQueryLog();
    $this->aggregates->forInsurers([$insurer->id], ...$this->bounds);

    // Les clauses, l'agrégat de délai, les versements, le curseur : quatre,
    // quel que soit le volume. Un whereIn sur les identifiants ferait exploser
    // ce compte le jour où il reviendrait.
    expect(count(DB::getQueryLog()))->toBe($withOne)
        ->and($withOne)->toBe(4);
});
