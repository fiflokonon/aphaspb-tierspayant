<?php

use App\Enums\PharmacyRole;
use App\Models\EvaluationCampaign;
use App\Models\Insurer;
use App\Models\InsurerEvaluation;
use App\Models\Pharmacy;
use App\Models\User;
use App\Notifications\Evaluations\EvaluationCampaignOpened;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    Http::fake();
    $this->travelTo(CarbonImmutable::create(2026, 3, 15));
});

/** Le bandeau du tableau de bord de $user. */
function dashboardNotice(User $user): mixed
{
    return test()->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertOk()
        ->inertiaProps('evaluationCampaign');
}

test('the dashboard tells how many insurers are left to evaluate in the open campaign', function () {
    $user = User::factory()->create();
    $pharmacy = $user->currentPharmacy;
    $pharmacy->insurers()->attach(Insurer::factory()->create());
    $campaign = EvaluationCampaign::factory()->create(['year' => 2025, 'closes_on' => '2026-03-31']);
    InsurerEvaluation::factory()->create([
        'evaluation_campaign_id' => $campaign->id,
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $pharmacy->insurers()->first()->id,
    ]);

    expect(dashboardNotice($user))->toBe([
        'year' => 2025,
        'closesOn' => '2026-03-31',
        'total' => 2,
        'remaining' => 1,
        'url' => route('pharmacy.evaluations', absolute: false),
    ]);
});

test('the dashboard says nothing once every insurer is evaluated, or without an open campaign', function () {
    $user = User::factory()->create();

    expect(dashboardNotice($user))->toBeNull();

    $campaign = EvaluationCampaign::factory()->create();
    InsurerEvaluation::factory()->create([
        'evaluation_campaign_id' => $campaign->id,
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $user->currentPharmacy->insurers()->sole()->id,
    ]);

    expect(dashboardNotice($user))->toBeNull();
});

test('the opening is announced once to the owners and admins of officines with insurers', function () {
    Notification::fake();
    $campaign = EvaluationCampaign::factory()->create();

    $owner = User::factory()->create();
    $pharmacy = $owner->currentPharmacy;
    $admin = User::factory()->notOnboarded()->create();
    $member = User::factory()->notOnboarded()->create();
    $pharmacy->members()->attach($admin, ['role' => PharmacyRole::Admin->value]);
    $pharmacy->members()->attach($member, ['role' => PharmacyRole::Member->value]);
    // Titulaire d'une seconde officine : une seule annonce quand même.
    $second = Pharmacy::factory()->create();
    $second->members()->attach($owner, ['role' => PharmacyRole::Owner->value]);
    $second->insurers()->attach(Insurer::factory()->create());
    $withoutInsurer = User::factory()->notOnboarded()->create();
    Pharmacy::factory()->create()->members()->attach($withoutInsurer, ['role' => PharmacyRole::Owner->value]);

    $this->artisan('evaluations:announce')->assertSuccessful();
    $this->artisan('evaluations:announce')->expectsOutputToContain('déjà annoncée')->assertSuccessful();

    Notification::assertSentTo($owner, EvaluationCampaignOpened::class, fn (EvaluationCampaignOpened $sent) => $sent->campaign->is($campaign));
    Notification::assertSentTimes(EvaluationCampaignOpened::class, 2);
    Notification::assertSentTo($admin, EvaluationCampaignOpened::class);
    Notification::assertNotSentTo([$member, $withoutInsurer], EvaluationCampaignOpened::class);
    expect($campaign->fresh()->announced_at)->not->toBeNull();
});

test('nothing is announced before the campaign opens, nor on a dry run', function () {
    Notification::fake();
    User::factory()->create();
    $campaign = EvaluationCampaign::factory()->upcoming()->create();

    $this->artisan('evaluations:announce')->expectsOutput('Aucune campagne ouverte.')->assertSuccessful();

    $this->travelTo($campaign->opens_on);
    $this->artisan('evaluations:announce --dry-run')->assertSuccessful();

    Notification::assertNothingSent();
    expect($campaign->fresh()->announced_at)->toBeNull();
});

test('the announcement reaches the bell, pointing at the evaluation screen', function () {
    $user = User::factory()->create();
    $campaign = EvaluationCampaign::factory()->create(['year' => 2025, 'closes_on' => '2026-03-31']);
    $user->notifyNow(new EvaluationCampaignOpened($campaign), ['database']);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.items.data.0.title', 'Évaluation des assureurs 2025 ouverte')
            ->where('notifications.items.data.0.body', "Notez vos assureurs jusqu'au 31/03/2026.")
            ->where('notifications.items.data.0.href', route('pharmacy.evaluations', absolute: false)),
        );
});
