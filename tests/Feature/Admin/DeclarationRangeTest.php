<?php

use App\Data\Period;
use App\Models\User;
use App\Services\Declarations\DeclarationRange;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 10, 20));
    $this->admin = User::factory()->networkAdmin()->notOnboarded()->create();
});

test('the admin sets the declaration floor and the catch-up of new officines', function () {
    $this->actingAs($this->admin)
        ->from(route('admin.declarations-followup'))
        ->patch(route('admin.settings.declaration-range'), ['floor_year' => 2025, 'floor_month' => 1, 'catch_up_months' => 6])
        ->assertRedirect(route('admin.declarations-followup'));

    $range = app(DeclarationRange::class);

    expect($range->floor())->toEqual(new Period(2025, 1))
        ->and($range->catchUpMonths())->toBe(6);

    $this->actingAs($this->admin)
        ->get(route('admin.declarations-followup'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('declarationRange.floorLabel', 'JANVIER 2025')
            ->where('declarationRange.catchUpMonths', 6));
});

test('the floor cannot be set in the future, nor the catch-up beyond its maximum', function (array $input, string $field, string $message) {
    $this->actingAs($this->admin)
        ->patch(route('admin.settings.declaration-range'), [...['floor_year' => 2025, 'floor_month' => 1, 'catch_up_months' => 3], ...$input])
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'future floor' => [['floor_year' => 2026, 'floor_month' => 11], 'floor_month', 'Le plancher ne peut pas être dans le futur.'],
    'catch-up too long' => [['catch_up_months' => 25], 'catch_up_months', 'Le rattrapage ne peut pas dépasser 24 mois.'],
]);

test('an officine cannot move the floor', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('admin.settings.declaration-range'), ['floor_year' => 2020, 'floor_month' => 1, 'catch_up_months' => 3])
        ->assertForbidden();
});

test('the follow-up never offers a month below the floor', function () {
    app(SettingsRepository::class)->set(SettingsRepository::DECLARATION_FLOOR, (new Period(2026, 8))->toOrdinal());

    $this->actingAs($this->admin)
        ->get(route('admin.declarations-followup'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('months', [
                ['value' => '2026-09', 'label' => 'septembre 2026'],
                ['value' => '2026-08', 'label' => 'août 2026'],
            ]));
});
