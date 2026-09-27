<?php

namespace App\Services\Declarations;

use App\Data\PenaltyLedger;
use App\Data\PenaltyLedgerMonth;
use App\Data\PenaltyLedgerSeries;
use App\Data\Period;
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

    /** @var array<string, int> */
    protected array $totalAccrued = [];

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
    ): void {
        $this->insurers[$insurerId] = true;

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
     * @param  array<int, string>  $names  les assureurs à publier, par identifiant
     * @param  (Closure(?int, int, int): bool)|null  $withheld  assureur (null = total), officines du couru, officines du déclaré
     */
    public function ledger(array $names, ?Closure $withheld = null, int $maskedInsurers = 0): PenaltyLedger
    {
        $series = [];

        foreach ($names as $insurerId => $name) {
            $series[] = $this->series(
                $insurerId,
                $name,
                $this->accrued[$insurerId] ?? [],
                $this->declared[$insurerId] ?? [],
                $this->accruedPharmacies[$insurerId] ?? [],
                $this->declaredPharmacies[$insurerId] ?? [],
                $withheld,
            );
        }

        $total = $this->series(
            null,
            'Tous assureurs',
            $this->totalAccrued,
            $this->totalDeclared,
            $this->totalAccruedPharmacies,
            $this->totalDeclaredPharmacies,
            $withheld,
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
     */
    protected function series(
        ?int $insurerId,
        string $name,
        array $accrued,
        array $declared,
        array $accruedPharmacies,
        array $declaredPharmacies,
        ?Closure $withheld,
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
            );

            if ($isWithheld) {
                $broken = true;
                $months[] = new PenaltyLedgerMonth($key, $label, $key === $currentKey, false, null, null, null, withheld: true);

                continue;
            }

            $cumulative += $accrued[$key] ?? 0;

            $months[] = new PenaltyLedgerMonth(
                $key,
                $label,
                current: $key === $currentKey,
                future: false,
                accrued: $accrued[$key] ?? 0,
                accruedCumulative: $broken ? null : $cumulative,
                declared: $declared[$key] ?? 0,
            );
        }

        return new PenaltyLedgerSeries($insurerId, $name, $months);
    }
}
