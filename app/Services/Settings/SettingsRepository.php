<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Read and write the settings the APhaSPB admin controls.
 *
 * Cached because every aggregate query reads them, and invalidated on write so
 * a change takes effect on the next request rather than at the end of the cache
 * window.
 *
 * The payment delay is deliberately absent: it is agreed insurer by insurer and
 * lives on the insurers table, not as one network-wide number.
 */
class SettingsRepository
{
    public const ANONYMITY_MIN_PHARMACIES = 'anonymity_min_pharmacies';

    /**
     * The lowest the anonymity threshold may be set to.
     *
     * At one, an insurer's indicators are a single officine's indicators
     * published under the network's name. Two is not comfortable either — with
     * exactly two declarants, each can subtract its own figures to read the
     * other's — but it is where the number stops being one officine's data
     * outright, so it is where the admin's hand stops.
     */
    public const ANONYMITY_FLOOR = 2;

    /**
     * @var array<string, int>
     */
    protected const DEFAULTS = [
        self::ANONYMITY_MIN_PHARMACIES => 5,
    ];

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
