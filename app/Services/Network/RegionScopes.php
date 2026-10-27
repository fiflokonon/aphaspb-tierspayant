<?php

namespace App\Services\Network;

use App\Data\RegionScope;
use App\Models\City;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Turns the `regions[]` query parameter into a RegionScope.
 *
 * The admin screens filter on one city or on several regions, never both: a
 * chosen city wins, because it is the narrower question.
 */
class RegionScopes
{
    /**
     * The filter a network screen applies: a city, regions, or nothing.
     */
    public function filterFrom(Request $request): string|RegionScope|null
    {
        $city = $request->string('city')->value() ?: null;

        return $city ?? $this->fromIds($this->requestedIds($request));
    }

    /**
     * The region ids asked for, unknown ones dropped later by fromIds().
     *
     * @return list<int>
     */
    public function requestedIds(Request $request): array
    {
        $raw = $request->query('regions', []);

        return array_values(array_unique(array_filter(
            array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw)),
            fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  list<int>  $ids
     */
    public function fromIds(array $ids): ?RegionScope
    {
        if ($ids === []) {
            return null;
        }

        $regions = Region::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);

        if ($regions->isEmpty()) {
            return null;
        }

        $selected = array_values(array_map('intval', $regions->pluck('id')->all()));

        return new RegionScope(
            regionIds: $selected,
            names: array_values(array_map('strval', $regions->pluck('name')->all())),
            cities: array_values(array_map('strval', City::query()->whereIn('region_id', $selected)->pluck('name')->all())),
            regionOf: $this->regionOfEveryCity(),
        );
    }

    /**
     * The regions on offer, for the filter control.
     *
     * @return list<array{id: int, name: string}>
     */
    public function options(): array
    {
        return array_values(Region::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Region $region): array => ['id' => $region->id, 'name' => $region->name])
            ->all());
    }

    /**
     * Each city key officines use, mapped to its region when it has one.
     *
     * Keyed on the canonical city, joined in SQL: the collation decides that
     * « cotonou » is Cotonou, as it does for the city filter and the
     * partition buckets. Deleted officines included, like the aggregates.
     *
     * @return array<string, int>
     */
    protected function regionOfEveryCity(): array
    {
        // Clé calculée dans une table dérivée, groupée dehors : MySQL
        // (ONLY_FULL_GROUP_BY) refuse un GROUP BY sur la sous-requête corrélée.
        $keyed = DB::table('pharmacies')
            ->join('cities', 'cities.name', '=', 'pharmacies.city')
            ->whereNotNull('cities.region_id')
            ->selectRaw(CityPartition::canonicalCitySql('pharmacies').' as city')
            ->addSelect('cities.region_id');

        return DB::query()
            ->fromSub($keyed, 'keyed')
            ->select('city')
            ->selectRaw('MIN(region_id) as region_id')
            ->groupBy('city')
            ->get()
            ->mapWithKeys(fn (object $row): array => [CityPartition::key($row->city) => (int) $row->region_id])
            ->all();
    }
}
