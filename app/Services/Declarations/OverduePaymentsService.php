<?php

namespace App\Services\Declarations;

use App\Data\OverdueLine;
use App\Enums\DeclarationStatus;
use App\Models\Insurer;
use App\Models\Pharmacy;
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
                'insurers.name as insurer_name',
                'insurers.standard_delay_days',
            )
            ->selectRaw('declarations.amount_invoiced - declarations.amount_received as outstanding')
            ->get();

        $today = CarbonImmutable::now()->startOfDay();

        $lines = $rows->map(function (object $row) use ($today): OverdueLine {
            $deposited = CarbonImmutable::parse((string) $row->invoice_deposited_on)->startOfDay();

            return new OverdueLine(
                declarationId: (int) $row->id,
                insurerName: (string) $row->insurer_name,
                periodYear: (int) $row->period_year,
                periodMonth: (int) $row->period_month,
                invoiceDepositedOn: $deposited,
                ageDays: (int) $deposited->diffInDays($today),
                standardDelayDays: (int) $row->standard_delay_days,
                outstanding: (int) $row->outstanding,
            );
        });

        return array_values($lines->sortByDesc('ageDays')->values()->all());
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
