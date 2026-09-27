<?php

namespace App\Services\Pharmacy;

use App\Data\PenaltyLedger;
use App\Data\Period;
use App\Models\Pharmacy;
use App\Services\Declarations\PenaltyCalculator;
use App\Services\Declarations\PenaltyLedgerWindow;
use App\Services\Declarations\PenaltyTally;
use App\Support\DayNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Le journal des pénalités d'une officine, mois par mois.
 *
 * Query builder et non Eloquent : aucun besoin de `private_note`, et
 * l'hydrater élargirait la surface pour rien. À l'échelle d'une officine —
 * une centaine de lignes —, un `whereIn` sur les identifiants suffit.
 *
 * Rien n'est retenu : l'officine lit ses propres chiffres.
 */
class PharmacyPenaltyLedger
{
    public function __construct(
        protected PenaltyCalculator $penalties,
        protected PenaltyLedgerWindow $window,
    ) {
        //
    }

    public function for(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId = null): PenaltyLedger
    {
        $clauses = $this->clauseInsurers($pharmacy)
            ->when($insurerId !== null, fn (Collection $all) => $all->where('id', $insurerId))
            ->keyBy('id');

        $tally = new PenaltyTally($this->penalties, $from, $to);

        if ($clauses->isEmpty()) {
            return $tally->ledger([]);
        }

        $declarations = $this->window->apply(DB::table('declarations'), $from, $to)
            ->where('declarations.pharmacy_id', $pharmacy->id)
            ->whereIn('declarations.insurer_id', $clauses->keys()->all())
            ->get([
                'declarations.id', 'declarations.insurer_id', 'declarations.period_year', 'declarations.period_month',
                'declarations.amount_invoiced', 'declarations.amount_received',
                'declarations.invoice_deposited_on', 'declarations.paid_on',
            ]);

        $payments = DB::table('declaration_payments')
            ->whereIn('declaration_id', $declarations->pluck('id'))
            ->orderBy('paid_on')
            ->get(['declaration_id', 'amount', 'paid_on'])
            ->groupBy('declaration_id')
            ->map(fn (Collection $rows): array => array_values($rows
                ->map(fn (stdClass $row): array => [(int) $row->amount, DayNumber::fromDate((string) $row->paid_on)])
                ->all()));

        foreach ($declarations as $declaration) {
            $clause = $clauses[(int) $declaration->insurer_id];

            $tally->add(
                insurerId: (int) $declaration->insurer_id,
                pharmacyId: $pharmacy->id,
                periodYear: (int) $declaration->period_year,
                periodMonth: (int) $declaration->period_month,
                amountInvoiced: (int) $declaration->amount_invoiced,
                amountReceived: (int) $declaration->amount_received,
                depositedDay: DayNumber::fromDate((string) $declaration->invoice_deposited_on),
                paidDay: $declaration->paid_on === null ? null : DayNumber::fromDate((string) $declaration->paid_on),
                triggerDays: (int) $clause->penalty_trigger_days,
                rateBp: (int) $clause->penalty_rate_bp,
                payments: $payments[$declaration->id] ?? [],
            );
        }

        return $tally->ledger($clauses->mapWithKeys(fn (stdClass $row): array => [(int) $row->id => (string) $row->name])->all());
    }

    /**
     * Les assureurs sous convention que l'officine a déjà déclarés, par nom.
     *
     * Sert aussi la liste du filtre : un assureur sans convention n'a rien à
     * tracer, et le proposer ferait lire une courbe vide comme « aucun retard ».
     *
     * @return Collection<int, stdClass>
     */
    public function clauseInsurers(Pharmacy $pharmacy): Collection
    {
        return DB::table('insurers')
            ->whereNotNull('penalty_trigger_days')
            ->whereNotNull('penalty_rate_bp')
            ->whereIn('id', DB::table('declarations')->where('pharmacy_id', $pharmacy->id)->select('insurer_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'penalty_trigger_days', 'penalty_rate_bp']);
    }
}
