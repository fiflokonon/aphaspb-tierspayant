<?php

use App\Enums\EvaluationCriterion;
use App\Models\Declaration;
use App\Models\EvaluationCampaign;
use App\Models\Insurer;
use App\Models\InsurerEvaluation;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Pharmacy\InsurerEvaluationSuggestions;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    Http::fake();
    $this->travelTo(CarbonImmutable::create(2026, 3, 15));

    $this->user = User::factory()->create(['name' => 'Awa Dossou']);
    $this->pharmacy = $this->user->currentPharmacy;
    $this->insurer = $this->pharmacy->insurers()->sole();
    $this->insurer->update(['standard_delay_days' => 30]);
});

function openCampaign(): EvaluationCampaign
{
    return EvaluationCampaign::factory()->create([
        'year' => 2025,
        'opens_on' => '2026-03-01',
        'closes_on' => '2026-03-31',
    ]);
}

/**
 * Les huit notes, toutes à leur maximum sauf celles qu'on précise.
 *
 * @param  array<string, int>  $overrides
 * @return array<string, int>
 */
function evaluationScores(array $overrides = []): array
{
    $scores = [];

    foreach (EvaluationCriterion::cases() as $criterion) {
        $scores[$criterion->value] = $criterion->maxPoints();
    }

    return [...$scores, ...$overrides];
}

/** Un mois de 2025 déposé le 5, réglé en un versement $delayDays jours plus tard. */
function evaluatedMonth(Pharmacy $pharmacy, Insurer $insurer, int $month, int $invoiced, int $delayDays): Declaration
{
    $deposited = CarbonImmutable::create(2025, $month, 5);

    return Declaration::factory()
        ->instalments([['amount' => $invoiced, 'paid_on' => $deposited->addDays($delayDays)->toDateString()]])
        ->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2025,
            'period_month' => $month,
            'amount_invoiced' => $invoiced,
            'invoice_deposited_on' => $deposited->toDateString(),
        ]);
}

test('the list offers the ticked insurers and those declared during the evaluated year', function () {
    openCampaign();
    $untied = Insurer::factory()->create(['name' => 'Ancien Assureur']);
    Declaration::factory()->create(['pharmacy_id' => $this->pharmacy->id, 'insurer_id' => $untied->id, 'period_year' => 2025]);
    $declaredLastYearOnly = Insurer::factory()->create();
    Declaration::factory()->create(['pharmacy_id' => $this->pharmacy->id, 'insurer_id' => $declaredLastYearOnly->id, 'period_year' => 2024]);
    $stranger = Insurer::factory()->create();

    $this->actingAs($this->user)
        ->get(route('pharmacy.evaluations'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Evaluations')
            ->where('campaign.year', 2025)
            ->has('insurers', 2)
            ->where('insurers', fn ($insurers) => collect($insurers)->pluck('id')->sort()->values()->all()
                === collect([$this->insurer->id, $untied->id])->sort()->values()->all()),
        );

    $this->actingAs($this->user)
        ->get(route('pharmacy.evaluations.edit', $stranger))
        ->assertNotFound();
});

test('the payment delay score is suggested from the money paid within the agreed delay', function () {
    openCampaign();
    // 600 000 sur 1 000 000 versés sous 30 jours : 60 % → 2/4.
    evaluatedMonth($this->pharmacy, $this->insurer, 2, 600_000, 20);
    evaluatedMonth($this->pharmacy, $this->insurer, 3, 400_000, 75);
    Declaration::factory()->rejected()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $this->insurer->id,
        'period_year' => 2025,
        'period_month' => 4,
        'amount_invoiced' => 0,
    ]);

    $this->actingAs($this->user)
        ->get(route('pharmacy.evaluations.edit', $this->insurer))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Evaluate')
            ->where('scores', null)
            ->where('suggestions.paymentDelay.points', 2)
            ->where('suggestions.paymentDelay.share', 60)
            ->where('suggestions.rejections', ['declarations' => 3, 'rejected' => 1]),
        );
});

test('the suggestion bands map the on-time share to points', function (float $share, int $points) {
    expect(app(InsurerEvaluationSuggestions::class)->paymentDelayPoints($share))->toBe($points);
})->with([
    [100.0, 4], [90.0, 4], [89.9, 3], [75.0, 3], [50.0, 2], [25.0, 1], [24.9, 0], [0.0, 0],
]);

test('no score is suggested when received money has no dated instalment', function () {
    openCampaign();
    // Reçu sans versement : l'état des déclarations antérieures aux dates de
    // paiement. La fabrique crée le versement, on l'efface derrière elle.
    $declaration = Declaration::factory()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $this->insurer->id,
        'period_year' => 2025,
        'period_month' => 1,
    ]);
    $declaration->payments()->delete();

    $this->actingAs($this->user)
        ->get(route('pharmacy.evaluations.edit', $this->insurer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('suggestions.paymentDelay.points', null)
            ->where('suggestions.paymentDelay.unavailableReason', 'Une partie des montants reçus n’a pas de date de versement.'),
        );
});

test('the officine validates its scores, and the suggestion is kept beside them', function () {
    $campaign = openCampaign();
    evaluatedMonth($this->pharmacy, $this->insurer, 2, 1_000_000, 10);

    $this->actingAs($this->user)
        ->put(route('pharmacy.evaluations.update', $this->insurer), evaluationScores(['payment_delay' => 3]))
        ->assertRedirect(route('pharmacy.evaluations'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Évaluation de {$this->insurer->name} enregistrée."]);

    $evaluation = InsurerEvaluation::query()->sole();

    expect($evaluation)
        ->evaluation_campaign_id->toBe($campaign->id)
        ->pharmacy_id->toBe($this->pharmacy->id)
        ->payment_delay->toBe(3)
        ->suggested_payment_delay->toBe(4)
        ->author_name->toBe('Awa Dossou')
        ->and($evaluation->total())->toBe(19);

    $this->actingAs($this->user)
        ->put(route('pharmacy.evaluations.update', $this->insurer), evaluationScores(['communication' => 0]))
        ->assertRedirect(route('pharmacy.evaluations'));

    expect(InsurerEvaluation::query()->sole()->communication)->toBe(0);
});

test('a score above its criterion maximum is refused', function () {
    openCampaign();

    $this->actingAs($this->user)
        ->put(route('pharmacy.evaluations.update', $this->insurer), evaluationScores(['payment_delay' => 5]))
        ->assertSessionHasErrors(['payment_delay' => 'Ce critère est noté sur 4 points.']);

    expect(InsurerEvaluation::query()->exists())->toBeFalse();
});

test('every criterion must be scored', function () {
    openCampaign();

    $this->actingAs($this->user)
        ->put(route('pharmacy.evaluations.update', $this->insurer), [])
        ->assertSessionHasErrors(array_fill_keys(EvaluationCriterion::columns(), 'Attribuez une note à ce critère.'));
});

test('nothing is written outside an open campaign', function () {
    EvaluationCampaign::factory()->closed()->create(['year' => 2025]);

    $this->actingAs($this->user)
        ->put(route('pharmacy.evaluations.update', $this->insurer), evaluationScores())
        ->assertRedirect(route('pharmacy.evaluations'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Aucune campagne d’évaluation n’est ouverte.']);

    expect(InsurerEvaluation::query()->exists())->toBeFalse();
});

test('the network averages reach the officine only when the admin shares them', function () {
    app(SettingsRepository::class)->set(SettingsRepository::ANONYMITY_MIN_PHARMACIES, 2);
    $campaign = EvaluationCampaign::factory()->closed()->create(['year' => 2025]);
    InsurerEvaluation::factory()->scoring(1.0)->create([
        'evaluation_campaign_id' => $campaign->id,
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $this->insurer->id,
    ]);
    InsurerEvaluation::factory()->scoring(0.5)->create([
        'evaluation_campaign_id' => $campaign->id,
        'insurer_id' => $this->insurer->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('pharmacy.evaluations'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('network', null));

    app(SettingsRepository::class)->set(SettingsRepository::EVALUATION_RESULTS_SHARED, 1);

    // scoring(0.5) : 1+1+1+2+1+2+2+1 = 11 (3 × 0,5 s'arrondit à 2) ; moyenne (20 + 11) / 2.
    $this->actingAs($this->user)
        ->get(route('pharmacy.evaluations'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('network.year', 2025)
            ->where('network.insurers.0.withheld', false)
            ->where('network.insurers.0.total', 15.5)
            ->where('network.insurers.0.ownTotal', 20),
        );
});
