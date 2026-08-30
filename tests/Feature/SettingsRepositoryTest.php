<?php

use App\Services\Settings\SettingsRepository;

beforeEach(fn () => $this->settings = app(SettingsRepository::class));

test('the anonymity threshold falls back to the value the CDC sets', function () {
    expect($this->settings->anonymityMinPharmacies())->toBe(5);
});

test('writing a setting clears the cached read', function () {
    expect($this->settings->anonymityMinPharmacies())->toBe(5);

    $this->settings->set('anonymity_min_pharmacies', 8);

    expect($this->settings->anonymityMinPharmacies())->toBe(8);
});

test('the payment delay threshold is no longer a global setting', function () {
    expect(defined(SettingsRepository::class.'::PAYMENT_DELAY_THRESHOLD_DAYS'))->toBeFalse()
        ->and(method_exists($this->settings, 'paymentDelayThresholdDays'))->toBeFalse();
});
