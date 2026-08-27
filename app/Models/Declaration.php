<?php

namespace App\Models;

use App\Enums\DeclarationStatus;
use Database\Factories\DeclarationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $pharmacy_id
 * @property int $insurer_id
 * @property int $period_year
 * @property int $period_month
 * @property int $amount_invoiced
 * @property int $amount_received
 * @property DeclarationStatus $status
 * @property bool $is_status_manual
 * @property int|null $delay_days
 * @property string|null $private_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int<0, max> $amount_outstanding
 * @property-read Pharmacy $pharmacy
 * @property-read Insurer $insurer
 */
#[Fillable([
    'pharmacy_id',
    'insurer_id',
    'period_year',
    'period_month',
    'amount_invoiced',
    'amount_received',
    'status',
    'is_status_manual',
    'delay_days',
    'private_note',
])]
class Declaration extends Model
{
    /** @use HasFactory<DeclarationFactory> */
    use HasFactory;

    /**
     * How far back a pharmacy may still record a missed month.
     */
    public const EARLIEST_MONTHS_BACK = 12;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Declaration $declaration) {
            if (! $declaration->is_status_manual) {
                $declaration->status = $declaration->deriveStatus();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeclarationStatus::class,
            'is_status_manual' => 'boolean',
            'amount_invoiced' => 'integer',
            'amount_received' => 'integer',
            'delay_days' => 'integer',
        ];
    }

    /**
     * Work out the status from the two amounts.
     *
     * Rejected is never derived: no pair of amounts implies an insurer refused
     * the invoice, so that status is always an explicit choice.
     */
    public function deriveStatus(): DeclarationStatus
    {
        return DeclarationStatus::derive($this->amount_invoiced, $this->amount_received);
    }

    /**
     * What the insurer still owes on this month.
     *
     * Derived rather than stored: a column would be a second source of truth.
     *
     * @return Attribute<int<0, max>, never>
     */
    protected function amountOutstanding(): Attribute
    {
        return Attribute::get(
            fn (): int => max(0, $this->amount_invoiced - $this->amount_received),
        );
    }

    /**
     * Limit the query to a single declared month.
     *
     * @param  Builder<Declaration>  $query
     */
    #[Scope]
    protected function forPeriod(Builder $query, int $year, int $month): void
    {
        $query->where('period_year', $year)->where('period_month', $month);
    }

    /**
     * Limit the query to the statuses that carry a payment delay.
     *
     * @param  Builder<Declaration>  $query
     */
    #[Scope]
    protected function settled(Builder $query): void
    {
        $query->whereIn('status', DeclarationStatus::settledValues());
    }

    /**
     * @return BelongsTo<Pharmacy, $this>
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    /**
     * @return BelongsTo<Insurer, $this>
     */
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }
}
