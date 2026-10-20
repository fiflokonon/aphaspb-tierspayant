<?php

namespace App\Models;

use Closure;
use Database\Factories\NilDeclarationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * « Pas de facture ce mois pour cet assureur ».
 *
 * Completes a month exactly as a declaration does, and carries no figure: no
 * statistic ever reads this table. A real declaration of the same insurer and
 * month replaces it (DeclarationController::store()).
 *
 * @property int $id
 * @property int $pharmacy_id
 * @property int $insurer_id
 * @property int $period_year
 * @property int $period_month
 * @property int|null $user_id
 * @property string $author_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pharmacy $pharmacy
 * @property-read Insurer $insurer
 */
#[Fillable(['pharmacy_id', 'insurer_id', 'period_year', 'period_month', 'user_id', 'author_name'])]
class NilDeclaration extends Model
{
    /** @use HasFactory<NilDeclarationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
        ];
    }

    /**
     * Every (officine, insurer, month) that has its answer: a declaration or a
     * « pas de facture ». The one source both completeness readers count on —
     * DeclarationCalendar for the officine, DeclarationCompleteness for the
     * network — so they never disagree on a month.
     *
     * UNION, not UNION ALL: a pair present in both tables (a race between two
     * tabs) still counts once. The constraint runs inside each branch, on
     * unprefixed columns, so neither table is read whole.
     *
     * @param  Closure(QueryBuilder): mixed  $constrain
     */
    public static function answeredMonths(Closure $constrain): QueryBuilder
    {
        $columns = ['pharmacy_id', 'insurer_id', 'period_year', 'period_month'];

        return DB::table('declarations')
            ->select($columns)
            ->where($constrain)
            ->union(DB::table('nil_declarations')->select($columns)->where($constrain));
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
