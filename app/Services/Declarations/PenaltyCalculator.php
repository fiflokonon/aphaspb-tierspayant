<?php

namespace App\Services\Declarations;

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\DeclarationPayment;
use App\Models\Insurer;
use Carbon\CarbonImmutable;

/**
 * Ce qu'un assureur doit en plus pour avoir payé trop tard.
 *
 * Calcul pur, sans écriture, sans colonne de cache — et c'est la rupture
 * assumée avec `delay_days`. Ce dernier est stocké parce qu'il ne dépend que
 * des données saisies : deux dates entrent, un entier sort. La pénalité, elle,
 * **croît toute seule** : un mois jamais réglé voit la sienne augmenter tous
 * les trente jours sans qu'aucune écriture ne survienne. Une colonne exigerait
 * une tâche quotidienne repassant sur toutes les déclarations ouvertes, et le
 * chiffre serait faux entre deux passages — c'est-à-dire la plupart du temps.
 *
 * Conséquence à connaître : ce calcul ne se somme pas en SQL. Il lui faut les
 * versements de chaque déclaration. À l'échelle d'une officine c'est une
 * boucle sur une centaine de lignes ; à l'échelle du réseau il faudra une
 * autre stratégie.
 */
class PenaltyCalculator
{
    /**
     * La pénalité courue par une déclaration, ou null s'il n'y a rien à courir.
     *
     * Exige `insurer` et `payments` préchargés : appelé en boucle sur une
     * collection, il produirait sinon deux requêtes par ligne.
     */
    public function for(Declaration $declaration): ?int
    {
        $insurer = $declaration->insurer;

        if (! $insurer->hasPenaltyClause()) {
            return null;
        }

        if ($declaration->invoice_deposited_on === null) {
            return null;
        }

        // Une facture refusée n'est pas due, donc rien ne la majore. Même
        // exclusion que dans OverduePaymentsService::overdueQuery().
        if ($declaration->status === DeclarationStatus::Rejected) {
            return null;
        }

        return $this->accrued(
            amountInvoiced: $declaration->amount_invoiced,
            amountReceived: $declaration->amount_received,
            depositedOn: $declaration->invoice_deposited_on,
            paidOn: $declaration->paid_on,
            triggerDays: (int) $insurer->penalty_trigger_days,
            rateBp: (int) $insurer->penalty_rate_bp,
            payments: array_values($declaration->payments->map(fn (DeclarationPayment $payment): array => [
                'amount' => $payment->amount,
                'paid_on' => $payment->paid_on,
            ])->all()),
        );
    }

    /**
     * La pénalité cumulée d'un lot, ou null si aucune ligne ne porte de clause.
     *
     * Null et zéro disent deux choses différentes : « aucune clause avec cet
     * assureur » se rend par un tiret, « une clause mais rien à réclamer » par
     * un zéro. Les confondre ferait lire une convention absente comme une
     * convention respectée.
     *
     * @param  iterable<Declaration>  $declarations
     */
    public function total(iterable $declarations): ?int
    {
        $total = null;

        foreach ($declarations as $declaration) {
            $penalty = $this->for($declaration);

            if ($penalty === null) {
                continue;
            }

            $total = ($total ?? 0) + $penalty;
        }

        return $total;
    }

    /**
     * L'algorithme, sur des valeurs nues.
     *
     * Séparé de for() parce qu'OverduePaymentsService lit en query builder et
     * n'hydrate jamais de Declaration — il ne doit pas charger `private_note`.
     * Une seule implémentation, deux portes d'entrée.
     *
     * @param  list<array{amount: int, paid_on: CarbonImmutable}>  $payments
     */
    public function accrued(
        int $amountInvoiced,
        int $amountReceived,
        CarbonImmutable $depositedOn,
        ?CarbonImmutable $paidOn,
        int $triggerDays,
        int $rateBp,
        array $payments,
    ): int {
        $end = $this->clockStopsOn($amountInvoiced, $amountReceived, $paidOn);

        if ($end === null) {
            return 0;
        }

        $total = 0;
        $tranche = $depositedOn->startOfDay()->addDays($triggerDays);

        while ($tranche <= $end) {
            $base = $amountInvoiced - $this->receivedBy($payments, $tranche);

            // Les versements ne font que s'ajouter : une base retombée à zéro
            // ne peut plus remonter, donc les tranches suivantes ne
            // factureraient rien. Sortir plutôt que continuer est une
            // optimisation, pas une règle — le total est le même dans les deux
            // cas, et c'est pourquoi aucun test ne peut les distinguer.
            if ($base <= 0) {
                break;
            }

            $total += intdiv($base * $rateBp, 10_000);
            $tranche = $tranche->addDays(Insurer::PENALTY_TRANCHE_DAYS);
        }

        return $total;
    }

    /**
     * Jusqu'à quand la pénalité court.
     *
     * Un mois entièrement soldé cesse de courir au dernier versement, et ce
     * qu'il avait accumulé lui reste acquis. Un mois qui doit encore quelque
     * chose court jusqu'à aujourd'hui.
     */
    protected function clockStopsOn(int $amountInvoiced, int $amountReceived, ?CarbonImmutable $paidOn): ?CarbonImmutable
    {
        if ($amountReceived < $amountInvoiced) {
            return CarbonImmutable::now()->startOfDay();
        }

        return $paidOn?->startOfDay();
    }

    /**
     * Ce qui était arrivé au plus tard le jour où la tranche mord.
     *
     * Comparaison inclusive : de l'argent viré le jour même allège cette
     * tranche-là plutôt que la suivante.
     *
     * @param  list<array{amount: int, paid_on: CarbonImmutable}>  $payments
     */
    protected function receivedBy(array $payments, CarbonImmutable $tranche): int
    {
        $total = 0;

        foreach ($payments as $payment) {
            if ($payment['paid_on']->startOfDay() <= $tranche) {
                $total += $payment['amount'];
            }
        }

        return $total;
    }
}
