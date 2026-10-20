<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EvaluationCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Une campagne annuelle d'évaluation des assureurs.
 *
 * L'année est celle que les officines évaluent, et celle dont les données
 * fondent la note proposée pour le respect du délai de règlement. La fenêtre
 * (bornes incluses) est celle où elles répondent ; deux campagnes ne se
 * chevauchent jamais (SaveEvaluationCampaignRequest).
 *
 * @property int $id
 * @property int $year
 * @property CarbonImmutable $opens_on
 * @property CarbonImmutable $closes_on
 * @property Carbon|null $announced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['year', 'opens_on', 'closes_on'])]
class EvaluationCampaign extends Model
{
    /** @use HasFactory<EvaluationCampaignFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'opens_on' => 'immutable_date',
            'closes_on' => 'immutable_date',
            'announced_at' => 'datetime',
        ];
    }

    /**
     * La campagne où les officines peuvent répondre aujourd'hui, s'il y en a une.
     */
    public static function current(): ?self
    {
        return self::query()->open()->first();
    }

    public function isOpen(): bool
    {
        $today = today()->toDateString();

        return $this->opens_on->toDateString() <= $today && $today <= $this->closes_on->toDateString();
    }

    /**
     * Close, donc ses résultats peuvent sortir : plus aucune note ne bougera.
     */
    public function isClosed(): bool
    {
        return $this->closes_on->toDateString() < today()->toDateString();
    }

    /**
     * @return 'upcoming'|'open'|'closed'
     */
    public function state(): string
    {
        return match (true) {
            $this->isClosed() => 'closed',
            $this->isOpen() => 'open',
            default => 'upcoming',
        };
    }

    /**
     * Les assureurs que l'officine peut évaluer pour cette campagne.
     *
     * Cochés **ou** déclarés sur l'année évaluée : une officine qui a cessé
     * de travailler avec un assureur en cours d'année a encore son avis sur
     * l'année écoulée. Seule définition : l'écran d'évaluation et le bandeau
     * du tableau de bord comptent les mêmes assureurs.
     *
     * @return Builder<Insurer>
     */
    public function insurersEvaluableBy(Pharmacy $pharmacy): Builder
    {
        return Insurer::query()->where(fn (Builder $query) => $query
            ->whereIn('id', $pharmacy->insurers()->select('insurers.id'))
            ->orWhereIn('id', $pharmacy->declarations()->where('period_year', $this->year)->select('insurer_id')),
        );
    }

    /**
     * Combien d'assureurs l'officine a encore à évaluer, sur combien.
     *
     * @return array{total: int, remaining: int}
     */
    public function progressOf(Pharmacy $pharmacy): array
    {
        $total = $this->insurersEvaluableBy($pharmacy)->count();

        $evaluated = $this->insurersEvaluableBy($pharmacy)
            ->whereIn('id', $this->evaluations()->where('pharmacy_id', $pharmacy->id)->select('insurer_id'))
            ->count();

        return ['total' => $total, 'remaining' => $total - $evaluated];
    }

    /**
     * @param  Builder<EvaluationCampaign>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $today = today()->toDateString();

        $query->whereDate('opens_on', '<=', $today)->whereDate('closes_on', '>=', $today);
    }

    /**
     * @param  Builder<EvaluationCampaign>  $query
     */
    #[Scope]
    protected function closed(Builder $query): void
    {
        $query->whereDate('closes_on', '<', today()->toDateString());
    }

    /**
     * @return HasMany<InsurerEvaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(InsurerEvaluation::class);
    }
}
