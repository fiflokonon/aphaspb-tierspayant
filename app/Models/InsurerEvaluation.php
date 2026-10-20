<?php

namespace App\Models;

use App\Enums\EvaluationCriterion;
use Database\Factories\InsurerEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ce qu'une officine pense d'un assureur, critère par critère, pour une campagne.
 *
 * Jamais publiée sous le nom de l'officine : le réseau n'en lit que des
 * moyennes, sous le seuil d'anonymat (InsurerEvaluationResults).
 *
 * @property int $id
 * @property int $evaluation_campaign_id
 * @property int $pharmacy_id
 * @property int $insurer_id
 * @property int|null $user_id
 * @property string $author_name
 * @property int $communication
 * @property int $product_lists
 * @property int $online_platform
 * @property int $medical_advisers
 * @property int $settlement_notice
 * @property int $payment_delay
 * @property int $rejection_reasons
 * @property int $claims_handling
 * @property int|null $suggested_payment_delay
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EvaluationCampaign $campaign
 * @property-read Pharmacy $pharmacy
 * @property-read Insurer $insurer
 */
#[Fillable([
    'evaluation_campaign_id',
    'pharmacy_id',
    'insurer_id',
    'user_id',
    'author_name',
    'communication',
    'product_lists',
    'online_platform',
    'medical_advisers',
    'settlement_notice',
    'payment_delay',
    'rejection_reasons',
    'claims_handling',
    'suggested_payment_delay',
])]
class InsurerEvaluation extends Model
{
    /** @use HasFactory<InsurerEvaluationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...array_fill_keys(EvaluationCriterion::columns(), 'integer'),
            'suggested_payment_delay' => 'integer',
        ];
    }

    /**
     * La note sur 20 : la somme des critères, jamais stockée.
     */
    public function total(): int
    {
        return array_sum($this->scores());
    }

    /**
     * @return array<string, int> keyed by criterion column
     */
    public function scores(): array
    {
        $scores = [];

        foreach (EvaluationCriterion::columns() as $column) {
            $scores[$column] = (int) $this->getAttribute($column);
        }

        return $scores;
    }

    /**
     * @return BelongsTo<EvaluationCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EvaluationCampaign::class, 'evaluation_campaign_id');
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
