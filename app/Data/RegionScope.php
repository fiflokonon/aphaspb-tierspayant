<?php

namespace App\Data;

/**
 * A network filter on one or several pharmaceutical regions.
 *
 * Travels where a city name travels (`string|RegionScope|null $city`): null
 * is the whole network, a string a single city, this a union of regions. A
 * single city is a leaf of the partition; a region is not — it groups cities,
 * so its figures follow the region partition rule (CityPartition).
 *
 * `$regionOf` maps the canonical key of **every** city an officine uses
 * (CityPartition::canonicalCitySql) to its region, selected or not: the rule
 * reads the whole partition, not only the chosen regions.
 */
readonly class RegionScope
{
    /**
     * @param  list<int>  $regionIds  the selected regions
     * @param  list<string>  $names  their names, for labels
     * @param  list<string>  $cities  the communes of the selected regions
     * @param  array<string, int>  $regionOf  canonical city key → region id
     */
    public function __construct(
        public array $regionIds,
        public array $names,
        public array $cities,
        public array $regionOf,
    ) {
        //
    }

    /**
     * Whether a canonical city key falls inside the selected regions.
     */
    public function contains(string $cityKey): bool
    {
        return isset($this->regionOf[$cityKey])
            && in_array($this->regionOf[$cityKey], $this->regionIds, true);
    }

    /**
     * « Région Sud, Région Nord » — what exports and screens print.
     */
    public function label(): string
    {
        return implode(', ', $this->names);
    }
}
