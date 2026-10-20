<?php

use App\Models\EvaluationCampaign;
use App\Models\Insurer;
use App\Models\InsurerEvaluation;
use App\Models\User;
use App\Services\Network\InsurerEvaluationResults;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    Http::fake();
    $this->travelTo(CarbonImmutable::create(2026, 3, 15));
    $this->admin = User::factory()->networkAdmin()->notOnboarded()->create();
    app(SettingsRepository::class)->set(SettingsRepository::ANONYMITY_MIN_PHARMACIES, 3);
});

/** $count officines distinctes qui notent $insurer au même niveau. */
function evaluationsOf(EvaluationCampaign $campaign, Insurer $insurer, int $count, float $share = 1.0): void
{
    InsurerEvaluation::factory()->count($count)->scoring($share)->create([
        'evaluation_campaign_id' => $campaign->id,
        'insurer_id' => $insurer->id,
    ]);
}

test('the admin opens a campaign on an evaluated year', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.evaluations.store'), ['year' => 2025, 'opens_on' => '2026-03-20', 'closes_on' => '2026-04-30'])
        ->assertRedirect(route('admin.evaluations'));

    expect(EvaluationCampaign::query()->sole())
        ->year->toBe(2025)
        ->opens_on->toDateString()->toBe('2026-03-20')
        ->closes_on->toDateString()->toBe('2026-04-30');
});

test('a campaign cannot overlap another one', function () {
    EvaluationCampaign::factory()->create(['year' => 2024, 'opens_on' => '2026-03-01', 'closes_on' => '2026-03-31']);

    $this->actingAs($this->admin)
        ->post(route('admin.evaluations.store'), ['year' => 2025, 'opens_on' => '2026-03-31', 'closes_on' => '2026-04-30'])
        ->assertSessionHasErrors(['opens_on' => 'Ces dates chevauchent une autre campagne.']);
});

test('a campaign is refused on a future year, on a taken year, or closing before it opens', function (array $input, string $field, string $message) {
    EvaluationCampaign::factory()->create(['year' => 2024, 'opens_on' => '2025-03-01', 'closes_on' => '2025-03-31']);

    $this->actingAs($this->admin)
        ->post(route('admin.evaluations.store'), [...['year' => 2025, 'opens_on' => '2026-04-01', 'closes_on' => '2026-04-30'], ...$input])
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'future year' => [['year' => 2027], 'year', 'Une année à venir ne peut pas encore être évaluée.'],
    'taken year' => [['year' => 2024], 'year', 'Une campagne existe déjà pour cette année.'],
    'closing first' => [['closes_on' => '2026-03-31'], 'closes_on', 'La clôture ne peut pas précéder l’ouverture.'],
]);

test('a campaign can be closed early by moving its closing date', function () {
    $campaign = EvaluationCampaign::factory()->create(['year' => 2025, 'opens_on' => '2026-03-01', 'closes_on' => '2026-03-31']);

    $this->actingAs($this->admin)
        ->patch(route('admin.evaluations.update', $campaign), ['year' => 2025, 'opens_on' => '2026-03-01', 'closes_on' => '2026-03-14'])
        ->assertRedirect(route('admin.evaluations', ['year' => 2025]));

    expect($campaign->fresh()->isClosed())->toBeTrue();
});

test('an officine cannot reach the campaigns', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.evaluations.store'), ['year' => 2025, 'opens_on' => '2026-03-20', 'closes_on' => '2026-04-30'])
        ->assertForbidden();
});

test('the admin decides whether officines see the averages', function () {
    $this->actingAs($this->admin)
        ->from(route('admin.evaluations'))
        ->patch(route('admin.settings.evaluation-sharing'), ['shared' => true])
        ->assertRedirect(route('admin.evaluations'));

    expect(app(SettingsRepository::class)->evaluationResultsShared())->toBeTrue();

    $this->actingAs($this->admin)->patch(route('admin.settings.evaluation-sharing'), ['shared' => false]);

    expect(app(SettingsRepository::class)->evaluationResultsShared())->toBeFalse();
});

test('the screen opens on the latest closed campaign results', function () {
    $closed = EvaluationCampaign::factory()->closed()->create(['year' => 2024]);
    EvaluationCampaign::factory()->create(['year' => 2025]);
    $insurer = Insurer::factory()->create();
    evaluationsOf($closed, $insurer, 3);

    $this->actingAs($this->admin)
        ->get(route('admin.evaluations'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Evaluations')
            ->has('campaigns', 2)
            ->where('campaigns.1.participants', 3)
            ->where('selectedYear', 2024)
            ->where('results.0.total', 20)
            ->where('results.0.evaluations', 3),
        );
});

test('no average comes out while the campaign is open', function () {
    $campaign = EvaluationCampaign::factory()->create();
    evaluationsOf($campaign, Insurer::factory()->create(), 5);

    expect(app(InsurerEvaluationResults::class)->for($campaign))->toBeNull();
});

test('an insurer evaluated by fewer officines than the threshold is withheld, count included', function () {
    $campaign = EvaluationCampaign::factory()->closed()->create();
    $published = Insurer::factory()->create(['name' => 'A Assurances']);
    $withheld = Insurer::factory()->create(['name' => 'B Assurances']);
    evaluationsOf($campaign, $published, 2, 1.0);
    evaluationsOf($campaign, $published, 1, 0.0);
    evaluationsOf($campaign, $withheld, 2);

    $results = app(InsurerEvaluationResults::class)->for($campaign);

    expect($results[0])
        ->insurerId->toBe($published->id)
        ->withheld->toBeFalse()
        ->evaluations->toBe(3)
        ->total->toBe(13.3)
        ->and($results[0]['criteria']['payment_delay'])->toBe(2.7)
        ->and($results[1])->toBe([
            'insurerId' => $withheld->id,
            'insurerName' => 'B Assurances',
            'withheld' => true,
            'evaluations' => null,
            'total' => null,
            'criteria' => null,
        ]);
});
