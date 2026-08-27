<?php

use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkStatsService;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
    $this->admin = User::factory()->networkAdmin()->notOnboarded()->create();
});

test('the list shows active and inactive insurers with their officine count', function () {
    $active = Insurer::factory()->create(['name' => 'Actif']);
    $inactive = Insurer::factory()->inactive()->create(['name' => 'Inactif']);

    Pharmacy::factory()->count(3)->create()->each(
        fn (Pharmacy $pharmacy) => $pharmacy->insurers()->attach($active),
    );

    $this->actingAs($this->admin)
        ->get(route('admin.insurers'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($active, $inactive) {
            $rows = collect($page->toArray()['props']['insurers'])->keyBy('id');

            expect($page->toArray()['component'])->toBe('admin/Insurers')
                ->and($rows[$active->id]['pharmacies'])->toBe(3)
                ->and($rows[$active->id]['isActive'])->toBeTrue()
                ->and($rows[$inactive->id]['isActive'])->toBeFalse();
        });
});

test('an insurer can be created', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.insurers.store'), ['name' => 'Nouvelle Assurance'])
        ->assertRedirect(route('admin.insurers'));

    expect(Insurer::query()->where('name', 'Nouvelle Assurance')->exists())->toBeTrue();
});

test('a duplicate insurer name is refused', function () {
    Insurer::factory()->create(['name' => 'Déjà là']);

    $this->actingAs($this->admin)
        ->post(route('admin.insurers.store'), ['name' => 'Déjà là'])
        ->assertSessionHasErrors('name');
});

test('an insurer can be renamed', function () {
    $insurer = Insurer::factory()->create(['name' => 'Ancien nom']);

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $insurer), ['name' => 'Nouveau nom'])
        ->assertRedirect();

    expect($insurer->fresh()->name)->toBe('Nouveau nom');
});

test('deactivating an insurer keeps its declarations and its past statistics', function () {
    $insurer = Insurer::factory()->create();

    foreach (range(1, 5) as $i) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $insurer), [
            'name' => $insurer->name,
            'is_active' => false,
        ]);

    $rows = app(NetworkStatsService::class)->perInsurer(new Period(2026, 8), new Period(2026, 8));

    expect($insurer->fresh()->is_active)->toBeFalse()
        ->and(Declaration::query()->where('insurer_id', $insurer->id)->count())->toBe(5)
        ->and($rows[$insurer->id]->declaringPharmacies)->toBe(5);
});

test('a deactivated insurer disappears from the officine forms', function () {
    $insurer = Insurer::factory()->create();
    $pharmacyUser = User::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $insurer), [
            'name' => $insurer->name,
            'is_active' => false,
        ]);

    $this->actingAs($pharmacyUser)
        ->get(route('pharmacy.insurers'))
        ->assertInertia(function (AssertableInertia $page) use ($insurer) {
            $offered = collect($page->toArray()['props']['insurers'])->pluck('id');

            expect($offered)->not->toContain($insurer->id);
        });
});

test('an insurer created by an officine arrives inactive and can be approved here', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('pharmacy.insurers.update'), [
        'insurers' => $user->currentPharmacy->insurers()->pluck('insurers.id')->all(),
        'other' => 'Mutuelle proposée par une officine',
    ]);

    $created = Insurer::query()->firstWhere('name', 'Mutuelle proposée par une officine');

    expect($created->is_active)->toBeFalse();

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $created), [
            'name' => $created->name,
            'is_active' => true,
        ]);

    expect($created->fresh()->is_active)->toBeTrue();
});

test('the payment threshold can be changed and screen 2a reflects it', function () {
    $this->actingAs($this->admin)
        ->patch(route('admin.threshold.update'), ['payment_delay_threshold_days' => 45])
        ->assertRedirect();

    expect(app(SettingsRepository::class)->paymentDelayThresholdDays())->toBe(45);

    $this->actingAs($this->admin)
        ->get(route('admin.network'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('threshold', 45));
});

test('a threshold outside one to three hundred sixty five is refused', function () {
    foreach ([0, 366, -5, 'beaucoup'] as $value) {
        $this->actingAs($this->admin)
            ->patch(route('admin.threshold.update'), ['payment_delay_threshold_days' => $value])
            ->assertSessionHasErrors('payment_delay_threshold_days');
    }
});

test('no route can write the anonymity threshold', function () {
    $settings = app(SettingsRepository::class);

    expect($settings->anonymityMinPharmacies())->toBe(5);

    // Both update routes are tried with the key smuggled in.
    $this->actingAs($this->admin)->patch(route('admin.threshold.update'), [
        'payment_delay_threshold_days' => 30,
        'anonymity_min_pharmacies' => 1,
    ]);

    $insurer = Insurer::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.insurers.update', $insurer), [
        'name' => $insurer->name,
        'anonymity_min_pharmacies' => 1,
    ]);

    expect(app(SettingsRepository::class)->anonymityMinPharmacies())->toBe(5);
});

test('a pharmacy account cannot manage insurers', function () {
    $insurer = Insurer::factory()->create();
    $pharmacyUser = User::factory()->create();

    $this->actingAs($pharmacyUser)->get(route('admin.insurers'))->assertForbidden();
    $this->actingAs($pharmacyUser)->post(route('admin.insurers.store'), ['name' => 'X'])->assertForbidden();
    $this->actingAs($pharmacyUser)->patch(route('admin.insurers.update', $insurer), ['name' => 'X'])->assertForbidden();
    $this->actingAs($pharmacyUser)->patch(route('admin.threshold.update'), ['payment_delay_threshold_days' => 10])->assertForbidden();
});
