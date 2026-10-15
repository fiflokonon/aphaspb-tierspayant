<?php

namespace App\Models;

use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A pharmaceutical region: a set of communes the association groups together.
 *
 * Defined by the association, not by the administrative map: a region is not
 * a department. A commune belongs to one region at most — `cities.region_id`
 * carries the link, so the schema holds that rule rather than the forms.
 *
 * An officine's region is never stored: it follows from its city, so moving a
 * commune between regions moves its officines with it.
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, City> $cities
 */
#[Fillable(['name'])]
class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    /**
     * Get the communes grouped in this region.
     *
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
}
