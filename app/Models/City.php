<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A commune of Benin, the closed list an officine picks its city from.
 *
 * pharmacies.city stays a string rather than a foreign key: officines that
 * registered before the list was imposed keep the spelling they typed. Since
 * the regions arrived (15/10/2026), the onboarding only accepts a name from
 * this table, because the officine's region follows from its city.
 *
 * @property int $id
 * @property string $name
 * @property string $department
 * @property int|null $region_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Region|null $region
 */
#[Fillable(['name', 'department'])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    /**
     * Every commune, alphabetically.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        // array_values(), not the collection's: PHPStan cannot prove
        // Collection::pluck()->all() yields a list.
        return array_values(self::query()
            ->orderBy('name')
            ->pluck('name')
            ->all());
    }

    /**
     * Get the pharmaceutical region this commune belongs to, if any.
     *
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
