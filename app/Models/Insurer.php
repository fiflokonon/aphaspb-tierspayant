<?php

namespace App\Models;

use Database\Factories\InsurerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property int $standard_delay_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'is_active', 'standard_delay_days'])]
class Insurer extends Model
{
    /** @use HasFactory<InsurerFactory> */
    use HasFactory;

    /**
     * The payment delay an insurer starts with, in days.
     *
     * Matches the column default: an insurer created through the officine's
     * free-text entry has no delay agreed yet, and this is what the CDC sets.
     */
    public const DEFAULT_STANDARD_DELAY_DAYS = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'standard_delay_days' => 'integer',
        ];
    }

    /**
     * Limit the query to insurers still offered in the forms.
     *
     * @param  Builder<Insurer>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the declarations recorded for this insurer.
     *
     * @return HasMany<Declaration, $this>
     */
    public function declarations(): HasMany
    {
        return $this->hasMany(Declaration::class);
    }

    /**
     * Get the pharmacies that declared working with this insurer.
     *
     * @return BelongsToMany<Pharmacy, $this>
     */
    public function pharmacies(): BelongsToMany
    {
        return $this->belongsToMany(Pharmacy::class);
    }
}
