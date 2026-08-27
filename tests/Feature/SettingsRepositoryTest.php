<?php

use App\Services\Settings\SettingsRepository;

beforeEach(fn () => $this->settings = app(SettingsRepository::class));

test('the thresholds fall back to the values the CDC sets', function () {
    expect($this->settings->paymentDelayThresholdDays())->toBe(30)
        ->and($this->settings->anonymityMinPharmacies())->toBe(5);
});

test('a stored value overrides the default', function () {
    $this->settings->set('payment_delay_threshold_days', 45);

    expect($this->settings->paymentDelayThresholdDays())->toBe(45);
});

test('writing a setting clears the cached read', function () {
    expect($this->settings->anonymityMinPharmacies())->toBe(5);

    $this->settings->set('anonymity_min_pharmacies', 8);

    expect($this->settings->anonymityMinPharmacies())->toBe(8);
});
