<?php

namespace App\Services\Declarations;

use App\Data\InsurerOverdueTotals;
use App\Data\OverdueLine;
use App\Enums\DeclarationStatus;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qui faut-il relancer, et sur quelles factures.
 *
 * Séparé de PharmacyStatsService, qui répond à « quels sont mes chiffres », et
 * de NetworkStatsService, qui n'agrège jamais un nom d'officine. Le retard est
 * une troisième question, et c'est ici qu'il est défini une seule fois.
 */
class OverduePaymentsService
{
    public function __construct(
        protected SettingsRepository $settings,
        protected PenaltyCalculator $penalties,
    ) {
        //
    }

    /**
     * Les factures en retard d'une officine, la plus ancienne en tête.
     *
     * @return list<OverdueLine>
     */
    public function forPharmacy(Pharmacy $pharmacy): array
    {
        $rows = $this->overdueQuery()
            ->where('declarations.pharmacy_id', $pharmacy->id)
            ->select(
                'declarations.id',
                'declarations.period_year',
                'declarations.period_month',
                'declarations.invoice_deposited_on',
                'declarations.amount_invoiced',
                'declarations.amount_received',
                'insurers.id as insurer_id',
                'insurers.name as insurer_name',
                'insurers.standard_delay_days',
                'insurers.penalty_trigger_days',
                'insurers.penalty_rate_bp',
            )
            ->selectRaw('declarations.amount_invoiced - declarations.amount_received as outstanding')
            ->get();

        $today = CarbonImmutable::now()->startOfDay();
        $instalments = $this->instalmentsOf($rows->pluck('id'));

        $lines = $rows->map(function (object $row) use ($today, $instalments): OverdueLine {
            $deposited = CarbonImmutable::parse((string) $row->invoice_deposited_on)->startOfDay();

            return new OverdueLine(
                declarationId: (int) $row->id,
                insurerId: (int) $row->insurer_id,
                insurerName: (string) $row->insurer_name,
                periodYear: (int) $row->period_year,
                periodMonth: (int) $row->period_month,
                invoiceDepositedOn: $deposited,
                ageDays: (int) $deposited->diffInDays($today),
                standardDelayDays: (int) $row->standard_delay_days,
                outstanding: (int) $row->outstanding,
                penalty: $this->penaltyOf(
                    triggerDays: $row->penalty_trigger_days === null ? null : (int) $row->penalty_trigger_days,
                    rateBp: $row->penalty_rate_bp === null ? null : (int) $row->penalty_rate_bp,
                    amountInvoiced: (int) $row->amount_invoiced,
                    amountReceived: (int) $row->amount_received,
                    depositedOn: $deposited,
                    payments: $instalments[$row->id] ?? [],
                ),
            );
        });

        return array_values($lines->sortByDesc('ageDays')->values()->all());
    }

    /**
     * La pénalité courue par une ligne en retard.
     *
     * `paidOn: null` n'est pas un oubli : une ligne en retard doit encore
     * quelque chose par définition — overdueQuery() impose
     * `amount_invoiced > amount_received` —, donc l'horloge court jusqu'à
     * aujourd'hui et la date de solde n'est jamais lue.
     *
     * @param  list<array{amount: int, paid_on: CarbonImmutable}>  $payments
     */
    protected function penaltyOf(
        ?int $triggerDays,
        ?int $rateBp,
        int $amountInvoiced,
        int $amountReceived,
        CarbonImmutable $depositedOn,
        array $payments,
    ): ?int {
        if ($triggerDays === null || $rateBp === null) {
            return null;
        }

        return $this->penalties->accrued(
            amountInvoiced: $amountInvoiced,
            amountReceived: $amountReceived,
            depositedOn: $depositedOn,
            paidOn: null,
            triggerDays: $triggerDays,
            rateBp: $rateBp,
            payments: $payments,
        );
    }

    /**
     * Les versements de ces déclarations, groupés, en une requête.
     *
     * Une par déclaration ferait un N+1 sur l'écran qui ouvre le tableau de
     * bord — première cause de lenteur perçue de cette application.
     *
     * @param  Collection<int, mixed>  $declarationIds
     * @return array<int, list<array{amount: int, paid_on: CarbonImmutable}>>
     */
    protected function instalmentsOf(Collection $declarationIds): array
    {
        if ($declarationIds->isEmpty()) {
            return [];
        }

        return DB::table('declaration_payments')
            ->whereIn('declaration_id', $declarationIds)
            ->orderBy('paid_on')
            ->get(['declaration_id', 'amount', 'paid_on'])
            ->groupBy('declaration_id')
            ->map(fn (Collection $payments): array => array_values($payments->map(fn (object $payment): array => [
                'amount' => (int) $payment->amount,
                'paid_on' => CarbonImmutable::parse((string) $payment->paid_on),
            ])->all()))
            ->all();
    }

    /**
     * Les officines portant au moins une facture en retard.
     *
     * @return Collection<int, Pharmacy>
     */
    public function pharmaciesWithOverdue(): Collection
    {
        $ids = $this->overdueQuery()
            ->distinct()
            ->pluck('declarations.pharmacy_id');

        return Pharmacy::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    /**
     * Le retard du réseau, assureur par assureur.
     *
     * Les assureurs comptant moins d'officines déclarantes que le seuil
     * d'anonymat sont omis, exactement comme sur les écrans réseau : un digest
     * qui les listerait contournerait la règle par la porte de derrière.
     *
     * @return list<InsurerOverdueTotals>
     */
    public function networkTotals(): array
    {
        $minimum = $this->settings->anonymityMinPharmacies();

        $rows = $this->overdueQuery()
            ->select('insurers.id', 'insurers.name', 'insurers.standard_delay_days')
            ->selectRaw('COUNT(*) as declarations')
            ->selectRaw('COUNT(DISTINCT declarations.pharmacy_id) as pharmacies')
            ->selectRaw('SUM(declarations.amount_invoiced - declarations.amount_received) as outstanding')
            ->groupBy('insurers.id', 'insurers.name', 'insurers.standard_delay_days')
            ->get();

        $totals = $rows
            ->filter(fn (object $row): bool => (int) $row->pharmacies >= $minimum)
            ->map(fn (object $row): InsurerOverdueTotals => new InsurerOverdueTotals(
                insurerId: (int) $row->id,
                insurerName: (string) $row->name,
                standardDelayDays: (int) $row->standard_delay_days,
                declarations: (int) $row->declarations,
                pharmacies: (int) $row->pharmacies,
                outstanding: (int) $row->outstanding,
            ))
            ->sortByDesc('outstanding');

        return array_values($totals->values()->all());
    }

    /**
     * Le socle commun : tout ce qui est en retard, sans restriction d'officine.
     *
     * Le dépassement se teste contre une date butoir calculée en PHP, une par
     * délai standard distinct — deux aujourd'hui. Comparer une date à un
     * intervalle porté par une colonne demanderait de l'arithmétique de dates
     * en SQL, que ce projet évite pour ne pas se lier à un moteur.
     */
    protected function overdueQuery(): Builder
    {
        $today = CarbonImmutable::now()->startOfDay();
        $delays = Insurer::query()->distinct()->pluck('standard_delay_days');

        return DB::table('declarations')
            ->join('insurers', 'insurers.id', '=', 'declarations.insurer_id')
            ->whereColumn('declarations.amount_invoiced', '>', 'declarations.amount_received')
            ->where('declarations.status', '!=', DeclarationStatus::Rejected->value)
            ->whereNotNull('declarations.invoice_deposited_on')
            ->where(function (Builder $outer) use ($delays, $today) {
                // Sans assureur, la clause resterait vide et le groupe
                // n'imposerait plus rien : tout remonterait comme en retard.
                if ($delays->isEmpty()) {
                    $outer->whereRaw('1 = 0');

                    return;
                }

                foreach ($delays as $days) {
                    $outer->orWhere(function (Builder $inner) use ($days, $today) {
                        $inner
                            ->where('insurers.standard_delay_days', $days)
                            // Strict : déposée pile il y a $days jours, la
                            // facture est encore dans les clous.
                            ->where(
                                'declarations.invoice_deposited_on',
                                '<',
                                $today->subDays((int) $days)->toDateString(),
                            );
                    });
                }
            });
    }
}
