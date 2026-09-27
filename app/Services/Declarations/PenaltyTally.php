<?php

namespace App\Services\Declarations;

use App\Data\PenaltyLedger;
use App\Data\PenaltyLedgerMonth;
use App\Data\PenaltyLedgerSeries;
use App\Data\Period;
use App\Enums\PenaltySettlement;
use App\Support\DayNumber;
use App\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Range les tranches de pénalité mois par mois, selon deux horloges.
 *
 * **Couru** : chaque tranche va au mois calendaire où elle tombe — c'est ce
 * qui dit si un assureur s'améliore. **Mois déclaré** : toute la pénalité
 * d'une facture va à son mois — c'est ce que tel mois a coûté.
 *
 * Aucune requête ici : les lecteurs (officine, réseau) passent des valeurs
 * nues, et c'est ce qui rend l'accumulateur testable sans base. Il compte
 * aussi les officines derrière chaque mois, sans rien en décider : la
 * rétention appartient à l'appelant qui détient le seuil.
 *
 * Une pénalité close suit les mêmes horloges : chacune de ses tranches va
 * aussi à « payée » ou « annulée » de son mois, et toute sa somme au clos de
 * son mois déclaré. La due est ce qui reste une fois les deux retirées.
 */
class PenaltyTally
{
    /** @var array<int, true> */
    protected array $insurers = [];

    /** @var array<int, array<string, int>> */
    protected array $accrued = [];

    /** @var array<int, array<string, int>> */
    protected array $declared = [];

    /** @var array<int, array<string, array<int, true>>> */
    protected array $accruedPharmacies = [];

    /** @var array<int, array<string, array<int, true>>> */
    protected array $declaredPharmacies = [];

    /** @var array<int, array{paid: array<string, int>, waived: array<string, int>, declared: array<string, int>}> */
    protected array $settled = [];

    /** @var array<string, int> */
    protected array $totalAccrued = [];

    /** @var array{paid: array<string, int>, waived: array<string, int>, declared: array<string, int>} */
    protected array $totalSettled = ['paid' => [], 'waived' => [], 'declared' => []];

    /** @var array<string, int> */
    protected array $totalDeclared = [];

    /** @var array<string, array<int, true>> */
    protected array $totalAccruedPharmacies = [];

    /** @var array<string, array<int, true>> */
    protected array $totalDeclaredPharmacies = [];

    protected int $firstDay;

    protected int $lastDay;

    /** Hissé : DayNumber::today() traverse Carbon (règle support.md). */
    protected int $today;

    public function __construct(
        protected PenaltyCalculator $penalties,
        protected Period $from,
        protected Period $to,
    ) {
        $this->firstDay = DayNumber::fromDate(sprintf('%04d-%02d-01', $from->year, $from->month));
        $this->lastDay = DayNumber::fromDate(
            CarbonImmutable::create($to->year, $to->month, 1)->endOfMonth()->format('Y-m-d'),
        );
        $this->today = DayNumber::today();
    }

    /**
     * Une déclaration sous convention, non rejetée, déposée.
     *
     * @param  list<array{0: int, 1: int}>  $payments
     * @param  PenaltySettlement|null  $settlement  la clôture de sa pénalité, null tant qu'elle est due
     */
    public function add(
        int $insurerId,
        int $pharmacyId,
        int $periodYear,
        int $periodMonth,
        int $amountInvoiced,
        int $amountReceived,
        int $depositedDay,
        ?int $paidDay,
        int $triggerDays,
        int $rateBp,
        array $payments,
        ?PenaltySettlement $settlement = null,
    ): void {
        $this->insurers[$insurerId] = true;
        $this->settled[$insurerId] ??= ['paid' => [], 'waived' => [], 'declared' => []];
        $bucket = $settlement === PenaltySettlement::Waived ? 'waived' : 'paid';

        $tranches = $this->penalties->tranches(
            $amountInvoiced, $amountReceived, $depositedDay, $paidDay, $triggerDays, $rateBp, $payments, $this->today,
        );

        $ordinal = $periodYear * 12 + $periodMonth;

        if ($ordinal >= $this->from->toOrdinal() && $ordinal <= $this->to->toOrdinal()) {
            $month = sprintf('%04d-%02d', $periodYear, $periodMonth);
            $sum = 0;

            foreach ($tranches as [, $amount]) {
                $sum += $amount;
            }

            $this->declared[$insurerId][$month] = ($this->declared[$insurerId][$month] ?? 0) + $sum;
            $this->declaredPharmacies[$insurerId][$month][$pharmacyId] = true;
            $this->totalDeclared[$month] = ($this->totalDeclared[$month] ?? 0) + $sum;
            $this->totalDeclaredPharmacies[$month][$pharmacyId] = true;

            if ($settlement !== null) {
                $this->settled[$insurerId]['declared'][$month] = ($this->settled[$insurerId]['declared'][$month] ?? 0) + $sum;
                $this->totalSettled['declared'][$month] = ($this->totalSettled['declared'][$month] ?? 0) + $sum;
            }
        }

        foreach ($tranches as [$day, $amount]) {
            // Optimisation, pas règle : une tranche hors période irait à un mois
            // que series() ne parcourt jamais, donc invisible dans les deux cas.
            // Aucun test ne peut distinguer les deux — ne pas en écrire un.
            if ($day < $this->firstDay || $day > $this->lastDay) {
                continue;
            }

            $month = DayNumber::monthKey($day);

            $this->accrued[$insurerId][$month] = ($this->accrued[$insurerId][$month] ?? 0) + $amount;
            $this->accruedPharmacies[$insurerId][$month][$pharmacyId] = true;
            $this->totalAccrued[$month] = ($this->totalAccrued[$month] ?? 0) + $amount;
            $this->totalAccruedPharmacies[$month][$pharmacyId] = true;

            if ($settlement !== null) {
                $this->settled[$insurerId][$bucket][$month] = ($this->settled[$insurerId][$bucket][$month] ?? 0) + $amount;
                $this->totalSettled[$bucket][$month] = ($this->totalSettled[$bucket][$month] ?? 0) + $amount;
            }
        }
    }

    /**
     * Les assureurs dont au moins une déclaration est entrée dans le décompte.
     *
     * @return list<int>
     */
    public function insurerIds(): array
    {
        return array_keys($this->insurers);
    }

    /**
     * Le journal : une série par assureur de `$names`, dans cet ordre, et le total.
     *
     * La closure reçoit, pour la série totale seulement, les officines derrière
     * sa **part cachée** : les mois déjà retenus des séries publiées. Le total
     * moins les séries visibles rend exactement cette part ; l'appelant peut
     * donc retenir le total quand elle ne repose que sur quelques officines.
     * Pour une série d'assureur, ces deux compteurs valent zéro.
     *
     * @param  array<int, string>  $names  les assureurs à publier, par identifiant
     * @param  (Closure(?int, int, int, int, int): bool)|null  $withheld  assureur (null = total), officines du couru, du déclaré, puis celles de la part cachée du couru et du déclaré
     */
    public function ledger(array $names, ?Closure $withheld = null, int $maskedInsurers = 0): PenaltyLedger
    {
        $series = [];
        $hiddenAccrued = [];
        $hiddenDeclared = [];

        foreach ($names as $insurerId => $name) {
            $one = $this->series(
                $insurerId,
                $name,
                $this->accrued[$insurerId] ?? [],
                $this->declared[$insurerId] ?? [],
                $this->accruedPharmacies[$insurerId] ?? [],
                $this->declaredPharmacies[$insurerId] ?? [],
                $this->settled[$insurerId] ?? ['paid' => [], 'waived' => [], 'declared' => []],
                $withheld,
            );

            foreach ($one->months as $month) {
                if ($month->withheld) {
                    $hiddenAccrued[$month->month] = ($hiddenAccrued[$month->month] ?? []) + ($this->accruedPharmacies[$insurerId][$month->month] ?? []);
                    $hiddenDeclared[$month->month] = ($hiddenDeclared[$month->month] ?? []) + ($this->declaredPharmacies[$insurerId][$month->month] ?? []);
                }
            }

            $series[] = $one;
        }

        $total = $this->series(
            null,
            'Tous assureurs',
            $this->totalAccrued,
            $this->totalDeclared,
            $this->totalAccruedPharmacies,
            $this->totalDeclaredPharmacies,
            $this->totalSettled,
            $withheld,
            $hiddenAccrued,
            $hiddenDeclared,
        );

        return new PenaltyLedger($series, $total, $maskedInsurers);
    }

    /**
     * Un mois retenu vide aussi **tous les cumuls suivants** : cumul(M) moins
     * cumul(M−1) rendrait exactement le mois que la rétention cache.
     *
     * @param  array<string, int>  $accrued
     * @param  array<string, int>  $declared
     * @param  array<string, array<int, true>>  $accruedPharmacies
     * @param  array<string, array<int, true>>  $declaredPharmacies
     * @param  array{paid: array<string, int>, waived: array<string, int>, declared: array<string, int>}  $settled
     * @param  array<string, array<int, true>>  $hiddenAccrued
     * @param  array<string, array<int, true>>  $hiddenDeclared
     */
    protected function series(
        ?int $insurerId,
        string $name,
        array $accrued,
        array $declared,
        array $accruedPharmacies,
        array $declaredPharmacies,
        array $settled,
        ?Closure $withheld,
        array $hiddenAccrued = [],
        array $hiddenDeclared = [],
    ): PenaltyLedgerSeries {
        $currentKey = now()->format('Y-m');
        $cumulative = 0;
        $broken = false;
        $months = [];

        for ($ordinal = $this->from->toOrdinal(); $ordinal <= $this->to->toOrdinal(); $ordinal++) {
            // toOrdinal() vaut année × 12 + mois, décembre compris : d'où le − 1.
            $year = intdiv($ordinal - 1, 12);
            $month = $ordinal - $year * 12;
            $key = sprintf('%04d-%02d', $year, $month);
            $label = MonthLabel::short($month, $year);

            if ($key > $currentKey) {
                $months[] = new PenaltyLedgerMonth($key, $label, current: false, future: true, accrued: null, accruedCumulative: null, declared: null);

                continue;
            }

            $isWithheld = $withheld !== null && $withheld(
                $insurerId,
                count($accruedPharmacies[$key] ?? []),
                count($declaredPharmacies[$key] ?? []),
                count($hiddenAccrued[$key] ?? []),
                count($hiddenDeclared[$key] ?? []),
            );

            if ($isWithheld) {
                $broken = true;
                $months[] = new PenaltyLedgerMonth($key, $label, $key === $currentKey, false, null, null, null, withheld: true);

                continue;
            }

            $cumulative += $accrued[$key] ?? 0;
            $paid = $settled['paid'][$key] ?? 0;
            $waived = $settled['waived'][$key] ?? 0;

            $months[] = new PenaltyLedgerMonth(
                $key,
                $label,
                current: $key === $currentKey,
                future: false,
                accrued: $accrued[$key] ?? 0,
                accruedCumulative: $broken ? null : $cumulative,
                declared: $declared[$key] ?? 0,
                accruedPaid: $paid,
                accruedWaived: $waived,
                accruedDue: ($accrued[$key] ?? 0) - $paid - $waived,
                declaredDue: ($declared[$key] ?? 0) - ($settled['declared'][$key] ?? 0),
            );
        }

        return new PenaltyLedgerSeries($insurerId, $name, $months);
    }
}
