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

    public const EVALUATION_RESULTS_SHARED = 'evaluation_results_shared';

    /** The oldest declarable month, as an ordinal (year × 12 + month). */
    public const DECLARATION_FLOOR = 'declaration_floor';

    public const CATCH_UP_MONTHS = 'catch_up_months_before_signup';

    /**
     * Where the floor stands until the admin moves it, in months back.
     *
     * Read once and then written down: the twelve-month window the platform
     * started with stops sliding on the day this code goes live, instead of
     * quietly retiring a month of history every month.
     */
    public const DEFAULT_FLOOR_MONTHS_BACK = 12;

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
        self::EVALUATION_RESULTS_SHARED => 0,
        self::CATCH_UP_MONTHS => 3,
    ];

    /**
     * How many declaring pharmacies an insurer needs before its figures show.
     */
    public function anonymityMinPharmacies(): int
    {
        return $this->integer(self::ANONYMITY_MIN_PHARMACIES);
    }

    /**
     * Whether the officines see the network's insurer evaluation averages.
     *
     * Off until the admin decides otherwise: the averages are the network's
     * opinion of its insurers, and publishing it is the association's call.
     */
    public function evaluationResultsShared(): bool
    {
        return $this->integer(self::EVALUATION_RESULTS_SHARED) === 1;
    }

    /**
     * The oldest month any officine may declare, as an ordinal.
     *
     * Frozen on first read (DEFAULT_FLOOR_MONTHS_BACK) rather than given a
     * constant default: a constant would either slide with the clock or pin
     * an arbitrary date into the code.
     */
    public function declarationFloorOrdinal(): int
    {
        $stored = Cache::rememberForever(
            $this->cacheKey(self::DECLARATION_FLOOR),
            fn (): ?string => Setting::query()->where('key', self::DECLARATION_FLOOR)->value('value'),
        );

        if ($stored !== null) {
            return (int) $stored;
        }

        $floor = now()->startOfMonth()->subMonths(self::DEFAULT_FLOOR_MONTHS_BACK);
        $ordinal = $floor->year * 12 + $floor->month;

        $this->set(self::DECLARATION_FLOOR, $ordinal);
        // Remis en cache aussitôt : set() le vide, et la lecture suivante
        // repasserait sinon par la base.
        Cache::forever($this->cacheKey(self::DECLARATION_FLOOR), (string) $ordinal);

        return $ordinal;
    }

    /**
     * How many months before its sign-up a new officine is expected to declare.
     */
    public function catchUpMonthsBeforeSignup(): int
    {
        return $this->integer(self::CATCH_UP_MONTHS);
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
