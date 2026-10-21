<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRegionRequest;
use App\Models\City;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The association's pharmaceutical regions, and the communes each groups.
 *
 * A commune belongs to one region at most: the screen only offers the free
 * ones, and SaveRegionRequest refuses a commune already taken elsewhere.
 * Deleting a region frees its communes, it never deletes them.
 *
 * The screen picks communes by department, since the association's regions
 * follow the departments; the link stays per commune, so a region can still
 * take a department in part.
 */
class RegionManagementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Regions', [
            'regions' => Region::query()
                ->with(['cities' => fn ($query) => $query->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(fn (Region $region) => [
                    'id' => $region->id,
                    'name' => $region->name,
                    'cityIds' => $region->cities->pluck('id')->all(),
                    'cities' => $region->cities->pluck('name')->all(),
                ]),
            'cities' => City::query()
                ->orderBy('name')
                ->get(['id', 'name', 'department', 'region_id'])
                ->map(fn (City $city) => [
                    'id' => $city->id,
                    'name' => $city->name,
                    'department' => $city->department,
                    'regionId' => $city->region_id,
                ]),
        ]);
    }

    public function store(SaveRegionRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $region = Region::query()->create(['name' => $request->validated('name')]);

            $this->assignCities($region, $request->validated('city_ids'));
        });

        return to_route('admin.regions');
    }

    public function update(SaveRegionRequest $request, Region $region): RedirectResponse
    {
        DB::transaction(function () use ($request, $region) {
            $region->update(['name' => $request->validated('name')]);

            $this->assignCities($region, $request->validated('city_ids'));
        });

        return to_route('admin.regions');
    }

    public function destroy(Region $region): RedirectResponse
    {
        // La clé étrangère libère les villes (nullOnDelete).
        $region->delete();

        return to_route('admin.regions');
    }

    /**
     * Make `$cityIds` exactly the communes of `$region`.
     *
     * The assignment re-checks that a commune is free or already this
     * region's: validation alone leaves a window where a second administrator
     * could have claimed it in between.
     *
     * @param  list<int|string>  $cityIds
     */
    protected function assignCities(Region $region, array $cityIds): void
    {
        $region->cities()
            ->whereNotIn('id', $cityIds)
            ->update(['region_id' => null]);

        City::query()
            ->whereIn('id', $cityIds)
            ->where(fn ($query) => $query->whereNull('region_id')->orWhere('region_id', $region->id))
            ->update(['region_id' => $region->id]);
    }
}
