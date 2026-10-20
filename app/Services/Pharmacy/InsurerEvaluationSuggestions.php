<?php

namespace App\Services\Pharmacy;

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Enums\EvaluationCriterion;
use App\Enums\PenaltySettlement;
use App\Models\Insurer;
use App\Models\Pharmacy;
use Illuminate\Support\Facades\DB;

/**
 * Ce que les déclarations d'une officine disent d'un assureur, pour l'évaluer.
 *
 * Une seule note est proposée : le respect du délai de règlement (critère f),
 * le seul que les chiffres mesurent. Pour les motifs de rejet (g) et les
 * réclamations (h), le système ne sait que des faits voisins — combien de
 * factures rejetées, combien de pénalités réclamées et ce qu'elles sont
 * devenues — et les montre sans en tirer de note : il ignore si un rejet a
 * été motivé, et une pénalité n'est qu'une des réclamations possibles.
 *
 * Tout se lit sur l'année évaluée, comme `InsurerRelationshipReport`, dont
 * les lignes servent au contexte.
 */
class InsurerEvaluationSuggestions
{
    /**
     * Les paliers du critère f : part minimale versée dans les délais → points.
     *
     * @var array<int, int>
     */
    protected const PAYMENT_DELAY_BANDS = [90 => 4, 75 => 3, 50 => 2, 25 => 1];

    public function __construct(protected InsurerRelationshipReport $report)
    {
        //
    }

    /**
     * @return array{
     *     paymentDelay: array{points: int|null, share: float|null, standardDelayDays: int, unavailableReason: string|null},
     *     rejections: array{declarations: int, rejected: int},
     *     penalties: array{hasClause: bool, due: int, paid: int, waived: int},
     * }
     */
    public function for(Pharmacy $pharmacy, Insurer $insurer, int $year): array
    {
        $built = $this->report->build($pharmacy, $insurer, new Period($year, 1), new Period($year, 12));
        $summary = $built['summary'];
        $months = collect($built['months']);

        $share = $this->recoveredWithinDelayShare($pharmacy, $insurer, $year, $summary->invoiced, $summary->received);

        return [
            'paymentDelay' => [
                'points' => $share === null ? null : $this->paymentDelayPoints($share),
                'share' => $share,
                'standardDelayDays' => $insurer->standard_delay_days,
                'unavailableReason' => match (true) {
                    $summary->invoiced <= 0 => "Aucune facture déclarée pour {$year}.",
                    $share === null => 'Une partie des montants reçus n’a pas de date de versement.',
                    default => null,
                },
            ],
            'rejections' => [
                'declarations' => $summary->declarations,
                'rejected' => $months->where('status', DeclarationStatus::Rejected->value)->count(),
            ],
            'penalties' => [
                'hasClause' => $insurer->hasPenaltyClause(),
                'due' => $months->filter(fn (array $month): bool => $month['settlement'] === null && ($month['penaltyDue'] ?? 0) > 0)->count(),
                'paid' => $months->where('settlement.outcome', PenaltySettlement::Paid->value)->count(),
                'waived' => $months->where('settlement.outcome', PenaltySettlement::Waived->value)->count(),
            ],
        ];
    }

    /**
     * La note proposée pour le critère f, d'après les paliers.
     */
    public function paymentDelayPoints(float $share): int
    {
        foreach (self::PAYMENT_DELAY_BANDS as $minimum => $points) {
            if ($share >= $minimum) {
                return $points;
            }
        }

        return 0;
    }

    /**
     * La part du facturé arrivée dans le délai conventionnel, versement par versement.
     *
     * La règle de `NetworkStatsService::recoveredShare()`, à l'échelle d'une
     * officine : ce qui n'a jamais été payé pèse comme ce qui l'a été en
     * retard, et la part est inconnue (null) dès qu'un montant reçu n'est pas
     * adossé à des versements datés — l'assureur serait sinon accusé à partir
     * d'une donnée absente.
     */
    protected function recoveredWithinDelayShare(Pharmacy $pharmacy, Insurer $insurer, int $year, int $invoiced, int $received): ?float
    {
        if ($invoiced <= 0) {
            return null;
        }

        $payments = DB::table('declaration_payments')
            ->join('declarations', 'declarations.id', '=', 'declaration_payments.declaration_id')
            ->where('declarations.pharmacy_id', $pharmacy->id)
            ->where('declarations.insurer_id', $insurer->id)
            ->where('declarations.period_year', $year)
            ->selectRaw('SUM(declaration_payments.amount) as recorded')
            ->selectRaw(
                'SUM(CASE WHEN declaration_payments.delay_days <= ? THEN declaration_payments.amount ELSE 0 END) as within_delay',
                [$insurer->standard_delay_days],
            )
            ->first();

        if ($received > (int) ($payments->recorded ?? 0)) {
            return null;
        }

        return round((int) ($payments->within_delay ?? 0) / $invoiced * 100, 1);
    }

    /**
     * Le libellé des paliers, pour que l'écran dise comment la note est tirée.
     */
    public static function paymentDelayScale(): string
    {
        $max = EvaluationCriterion::PaymentDelay->maxPoints();
        $parts = [];

        foreach (self::PAYMENT_DELAY_BANDS as $minimum => $points) {
            $parts[] = "≥ {$minimum} % → {$points}/{$max}";
        }

        return implode(' · ', $parts).' · en dessous → 0';
    }
}
