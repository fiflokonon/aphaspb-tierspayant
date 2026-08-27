<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Read and write the two thresholds the APhaSPB admin controls.
 *
 * Cached because every aggregate query reads them, and invalidated on write so
 * a threshold change takes effect on the next request rather than at the end of
 * the cache window.
 */
class SettingsRepository
{
    public const PAYMENT_DELAY_THRESHOLD_DAYS = 'payment_delay_threshold_days';

    public const ANONYMITY_MIN_PHARMACIES = 'anonymity_min_pharmacies';

    /**
     * @var array<string, int>
     */
    protected const DEFAULTS = [
        self::PAYMENT_DELAY_THRESHOLD_DAYS => 30,
        self::ANONYMITY_MIN_PHARMACIES => 5,
    ];

    /**
     * The regulatory reference the network is measured against, in days.
     */
    public function paymentDelayThresholdDays(): int
    {
        return $this->integer(self::PAYMENT_DELAY_THRESHOLD_DAYS);
    }

    /**
     * How many declaring pharmacies an insurer needs before its figures show.
     */
    public function anonymityMinPharmacies(): int
    {
        return $this->integer(self::ANONYMITY_MIN_PHARMACIES);
    }

    public function set(string $key, int|string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);

        Cache::forget($this->cacheKey($key));
    }

    protected function integer(string $key): int
    {
        return (int) Cache::rememberForever(
            $this->cacheKey($key),
            fn (): string => Setting::query()->where('key', $key)->value('value')
                ?? (string) self::DEFAULTS[$key],
        );
    }

    protected function cacheKey(string $key): string
    {
        return 'settings:'.$key;
    }
}
