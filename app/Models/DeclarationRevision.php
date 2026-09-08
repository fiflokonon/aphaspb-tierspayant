<?php

namespace App\Models;

use App\Enums\DeclarationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DeclarationRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a declaration said at one point in time, and who said it.
 *
 * Written only when a save changes something: re-opening a month and saving it
 * untouched must not inflate the count of corrections the officine is shown.
 *
 * @property int $id
 * @property int $declaration_id
 * @property int|null $user_id
 * @property string $author_name
 * @property int $amount_invoiced
 * @property int $amount_received
 * @property DeclarationStatus $status
 * @property CarbonImmutable|null $invoice_deposited_on
 * @property CarbonImmutable|null $paid_on
 * @property int|null $delay_days
 * @property list<array{amount: int, paid_on: string, delay_days: int|null}> $payments
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Declaration $declaration
 */
#[Fillable([
    'declaration_id',
    'user_id',
    'author_name',
    'amount_invoiced',
    'amount_received',
    'status',
    'invoice_deposited_on',
    'paid_on',
    'delay_days',
    'payments',
])]
class DeclarationRevision extends Model
{
    /** @use HasFactory<DeclarationRevisionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeclarationStatus::class,
            'invoice_deposited_on' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'amount_invoiced' => 'integer',
            'amount_received' => 'integer',
            'delay_days' => 'integer',
            'payments' => 'array',
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
