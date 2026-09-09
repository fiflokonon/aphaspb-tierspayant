<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeclarationPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One transfer received against a declaration.
 *
 * An insurer settles a month in one or several instalments; each carries its
 * own date, and therefore its own delay. The declaration keeps the totals as
 * caches, but this is where they come from.
 *
 * @property int $id
 * @property int $declaration_id
 * @property int $amount
 * @property CarbonImmutable $paid_on
 * @property int|null $delay_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Declaration $declaration
 */
#[Fillable(['declaration_id', 'amount', 'paid_on', 'delay_days'])]
class DeclarationPayment extends Model
{
    /** @use HasFactory<DeclarationPaymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'immutable_date',
            'delay_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Declaration, $this>
     */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(Declaration::class);
    }
}
