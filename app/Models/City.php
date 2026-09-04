<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A commune of Benin, offered as a suggestion when an officine registers.
 *
 * Reference data, not a foreign key: pharmacies.city stays a string. An
 * officine whose locality is spelled differently, or which sits outside the
 * seeded list entirely, must still be able to register — the list proposes,
 * it does not decide.
 *
 * @property int $id
 * @property string $name
 * @property string $department
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
}
