<?php

namespace App\Models;

use Database\Factories\InsurerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property int|null $penalty_trigger_days
 * @property int|null $penalty_rate_bp
 * @property-read float|null $penalty_rate_percent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'is_active', 'standard_delay_days', 'penalty_trigger_days', 'penalty_rate_bp'])]
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
     * How long a penalty tranche runs, in days.
     *
     * The clause bites on the trigger day, then again every tranche for as
     * long as something remains owed.
     */
    public const PENALTY_TRANCHE_DAYS = 30;

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
            'penalty_trigger_days' => 'integer',
            'penalty_rate_bp' => 'integer',
        ];
    }

    /**
     * Whether a penalty was actually agreed with this insurer.
     *
     * The two columns are meaningless apart — a trigger without a rate accrues
     * nothing, a rate without a trigger never starts — so they are read as one
     * clause, and half a clause is no clause.
     */
    public function hasPenaltyClause(): bool
    {
        return $this->penalty_trigger_days !== null && $this->penalty_rate_bp !== null;
    }

    /**
     * The penalty rate as the convention writes it: 250 basis points → 2.5 %.
     *
     * Read-only. The column stays the source of truth, in basis points, so
     * every amount derived from it is integer arithmetic.
     *
     * @return Attribute<float|null, never>
     */
    protected function penaltyRatePercent(): Attribute
    {
        return Attribute::get(
            fn (): ?float => $this->penalty_rate_bp === null
                ? null
                : $this->penalty_rate_bp / 100,
        );
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
