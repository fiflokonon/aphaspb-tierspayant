<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Region;
use Illuminate\Database\Seeder;

class RegionSeeder extends Seeder
{
    /**
     * The seven pharmaceutical regions, by the departments they group.
     *
     * The association's split of the twelve departments. A region groups
     * communes, so each department stands here for all of its communes, as
     * CitySeeder records them.
     *
     * @var array<string, list<string>>
     */
    protected const DEPARTMENTS = [
        'Région 1' => ['Littoral'],
        'Région 2' => ['Atlantique'],
        'Région 3' => ['Ouémé', 'Plateau'],
        'Région 4' => ['Mono', 'Couffo'],
        'Région 5' => ['Zou', 'Collines'],
        'Région 6' => ['Borgou'],
        'Région 7' => ['Alibori', 'Atacora', 'Donga'],
    ];

    /**
     * Run the database seeds.
     *
     * Idempotent, and safe to re-run on an installed database: only communes
     * in no region yet are placed, so a commune the association moved by hand
     * stays where it was put.
     */
    public function run(): void
    {
        foreach (self::DEPARTMENTS as $name => $departments) {
            $region = Region::query()->firstOrCreate(['name' => $name]);

            City::query()
                ->whereIn('department', $departments)
                ->whereNull('region_id')
                ->update(['region_id' => $region->id]);
        }
    }
}
