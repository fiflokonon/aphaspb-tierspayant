<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sensitive request: a write, a download or a refusal (RecordAuditLog).
 *
 * Append-only, no updated_at. Kept twelve months, the duration the privacy
 * policy announces for technical logs, then pruned by `model:prune`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $pharmacy_id
 * @property string $method
 * @property string|null $route_name
 * @property string $path
 * @property int $status
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read Pharmacy|null $pharmacy
 */
#[Fillable(['user_id', 'pharmacy_id', 'method', 'route_name', 'path', 'status', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    public const RETENTION_MONTHS = 12;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(self::RETENTION_MONTHS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Pharmacy, $this>
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
