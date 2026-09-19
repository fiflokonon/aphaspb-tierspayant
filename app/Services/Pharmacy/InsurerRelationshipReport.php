<?php

namespace App\Services\Pharmacy;

use App\Data\InsurerRelationship;
use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Declarations\LongestDelay;
use App\Services\Declarations\PenaltyCalculator;
use App\Support\MonthLabel;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tout ce que montre l'écran d'une officine face à un assureur.
 *
 * Pas une méthode de PharmacyStatsService, et pour deux raisons que l'en-tête
 * de cette classe-là énonce : elle ne lit qu'en query builder, précisément
 * pour ne jamais charger `private_note`, et elle ne produit que des agrégats.
 * Cet écran a besoin des versements de chaque déclaration — la pénalité ne se
 * somme pas en SQL — et d'une ligne par mois. Y greffer cette lecture
 * obligerait à réécrire cet en-tête pour dire l'inverse de ce qu'il dit.
 *
 * Les colonnes sont sélectionnées explicitement et `private_note` reste dehors :
 * cet écran ne l'affiche pas, et `pharmacy/History` demeure le seul endroit où
 * la note apparaît.
 */
class InsurerRelationshipReport
{
    public function __construct(
        protected PenaltyCalculator $penalties,
        protected LongestDelay $longestDelay,
    ) {
        //
    }

    /**
     * Les agrégats et le détail mois par mois, en une seule lecture.
     *
     * Les deux sortent de la même collection : deux méthodes la chargeraient
     * deux fois, et rien ne garantirait qu'elles voient les mêmes lignes.
     *
     * @return array{summary: InsurerRelationship, months: list<array<string, mixed>>}
     */
    public function build(Pharmacy $pharmacy, Insurer $insurer, Period $from, Period $to): array
    {
        $declarations = $this->declarations($pharmacy, $insurer, $from, $to);

        // Attaché une fois pour toutes : PenaltyCalculator::for() lit
        // l'assureur de chaque ligne, et sans cela il le relirait en base une
        // fois par mois déclaré.
        $declarations->each(fn (Declaration $one) => $one->setRelation('insurer', $insurer));

        return [
            'summary' => $this->summary($insurer, $declarations),
            'months' => $this->months($declarations, $insurer),
        ];
    }

    /**
     * Les déclarations de la période, versements préchargés.
     *
     * Filtrées sur l'ordinal (year * 12 + month), comme
     * PharmacyExportRows::declarations() — surtout pas via la fenêtre glissante
     * de PharmacyStatsService::window(), sinon l'écran et le fichier qu'il
     * propose de télécharger ne couvriraient pas les mêmes mois.
     *
     * @return Collection<int, Declaration>
     */
    protected function declarations(Pharmacy $pharmacy, Insurer $insurer, Period $from, Period $to)
    {
        return Declaration::query()
            ->select([
                'id',
                'insurer_id',
                'period_year',
                'period_month',
                'amount_invoiced',
                'amount_received',
                'status',
                'is_status_manual',
                'invoice_deposited_on',
                'paid_on',
                'delay_days',
            ])
            ->with('payments')
            ->where('pharmacy_id', $pharmacy->id)
            ->where('insurer_id', $insurer->id)
            ->whereRaw(
                '(period_year * 12 + period_month) BETWEEN ? AND ?',
                [$from->toOrdinal(), $to->toOrdinal()],
            )
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get();
    }

    /**
     * @param  Collection<int, Declaration>  $declarations
     */
    protected function summary(Insurer $insurer, Collection $declarations): InsurerRelationship
    {
        $invoiced = (int) $declarations->sum('amount_invoiced');
        $received = (int) $declarations->sum('amount_received');
        $settled = $declarations->whereNotNull('delay_days');
        $basis = (int) $settled->sum('amount_received');

        return new InsurerRelationship(
            insurerId: $insurer->id,
            insurerName: $insurer->name,
            standardDelayDays: $insurer->standard_delay_days,
            penaltyTriggerDays: $insurer->penalty_trigger_days,
            penaltyRatePercent: $insurer->penalty_rate_percent,
            declarations: $declarations->count(),
            invoiced: $invoiced,
            received: $received,
            outstanding: max(0, $invoiced - $received),
            recoveryRate: $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            weightedDelayDays: $basis > 0
                ? round($settled->sum(
                    fn (Declaration $one): int => (int) $one->delay_days * $one->amount_received,
                ) / $basis, 1)
                : null,
            longestDelayDays: $this->longestDelay->for($declarations),
            penalty: $this->penalties->total($declarations),
        );
    }

    /**
     * Une ligne par mois déclaré, du plus récent au plus ancien.
     *
     * @param  Collection<int, Declaration>  $declarations
     * @return list<array<string, mixed>>
     */
    protected function months(Collection $declarations, Insurer $insurer): array
    {
        return array_values($declarations->map(fn (Declaration $one): array => [
            'id' => $one->id,
            'year' => $one->period_year,
            'month' => $one->period_month,
            'monthLabel' => MonthLabel::short($one->period_month, $one->period_year),
            'status' => $one->status->value,
            'statusLabel' => $one->status->label(),
            'invoiced' => $one->amount_invoiced,
            'received' => $one->amount_received,
            'outstanding' => $one->amount_outstanding,
            'depositedOn' => $one->invoice_deposited_on?->toDateString(),
            'paidOn' => $one->paid_on?->toDateString(),
            'delayDays' => $one->delay_days,
            'instalments' => $one->payments->count(),
            'penalty' => $this->penalties->for($one),
            'editUrl' => route('pharmacy.declare', [
                'insurer' => $insurer->id,
                'year' => $one->period_year,
                'month' => $one->period_month,
            ], absolute: false),
        ])->all());
    }
}
