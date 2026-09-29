<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Enums\PenaltySettlement;
use App\Services\Declarations\PenaltyCalculator;
use App\Services\Declarations\PenaltyLedgerWindow;
use App\Services\Declarations\PenaltyTally;
use App\Support\DayNumber;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Le décompte mensuel brut des pénalités du réseau. Ne décide d'aucune
 * rétention : NetworkPenaltyJournal le fait, qui détient le seuil.
 *
 * Même stratégie qu'InsurerPenaltyAggregates : numéros de jour entiers,
 * curseur, versements **joints** (jamais un whereIn sur des identifiants de
 * déclaration), restriction aux assureurs sous convention. Trois requêtes
 * quel que soit le volume : les clauses, les versements, les déclarations.
 */
class NetworkPenaltyLedger
{
    public function __construct(
        protected DeclarationWindow $window,
        protected PenaltyLedgerWindow $ledgerWindow,
        protected PenaltyCalculator $penalties,
    ) {
        //
    }

    public function tally(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): PenaltyTally
    {
        $tally = new PenaltyTally($this->penalties, $from, $to);

        $clauses = DB::table('insurers')
            ->whereNotNull('penalty_trigger_days')
            ->whereNotNull('penalty_rate_bp')
            ->when($insurerId, fn ($query, int $id) => $query->where('id', $id))
            ->get(['id', 'penalty_trigger_days', 'penalty_rate_bp'])
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->id => [(int) $row->penalty_trigger_days, (int) $row->penalty_rate_bp],
            ])
            ->all();

        if ($clauses === []) {
            return $tally;
        }

        $payments = $this->instalments(array_keys($clauses), $from, $to, $city);

        $declarations = $this->scope(DB::table('declarations'), array_keys($clauses), $from, $to, $city)
            // Jointe, pas une requête de plus : la ville de chaque officine
            // nourrit la règle de partition du journal non filtré.
            ->leftJoin('pharmacies as declaring_pharmacy', 'declaring_pharmacy.id', '=', 'declarations.pharmacy_id')
            ->select(
                'declarations.id', 'declarations.insurer_id', 'declarations.pharmacy_id',
                'declarations.period_year', 'declarations.period_month',
                'declarations.amount_invoiced', 'declarations.amount_received',
                'declarations.invoice_deposited_on', 'declarations.paid_on', 'declarations.penalty_settlement',

            )
            // La clé canonique (collation), pas la chaîne brute : CityPartition.
            ->selectRaw(CityPartition::canonicalCitySql('declaring_pharmacy').' as pharmacy_city')
            // cursor() et non get() : même raison qu'InsurerPenaltyAggregates.
            ->cursor();

        foreach ($declarations as $declaration) {
            [$triggerDays, $rateBp] = $clauses[(int) $declaration->insurer_id];

            $tally->add(
                insurerId: (int) $declaration->insurer_id,
                pharmacyId: (int) $declaration->pharmacy_id,
                periodYear: (int) $declaration->period_year,
                periodMonth: (int) $declaration->period_month,
                amountInvoiced: (int) $declaration->amount_invoiced,
                amountReceived: (int) $declaration->amount_received,
                depositedDay: DayNumber::fromDate((string) $declaration->invoice_deposited_on),
                paidDay: $declaration->paid_on === null ? null : DayNumber::fromDate((string) $declaration->paid_on),
                triggerDays: $triggerDays,
                rateBp: $rateBp,
                payments: $payments[$declaration->id] ?? [],
                settlement: PenaltySettlement::tryFrom((string) $declaration->penalty_settlement),
                city: $declaration->pharmacy_city === null ? null : (string) $declaration->pharmacy_city,
            );
        }

        return $tally;
    }

    /**
     * @param  list<int>  $insurerIds
     * @return array<int, list<array{0: int, 1: int}>>
     */
    protected function instalments(array $insurerIds, Period $from, Period $to, ?string $city): array
    {
        $query = DB::table('declaration_payments')
            ->join('declarations', 'declarations.id', '=', 'declaration_payments.declaration_id');

        $rows = $this->scope($query, $insurerIds, $from, $to, $city)
            ->orderBy('declaration_payments.paid_on')
            ->select('declaration_payments.declaration_id', 'declaration_payments.amount', 'declaration_payments.paid_on')
            ->cursor();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->declaration_id][] = [(int) $row->amount, DayNumber::fromDate((string) $row->paid_on)];
        }

        return $grouped;
    }

    /**
     * Un seul filtre pour les deux requêtes : les versements lus doivent être
     * exactement ceux des déclarations lues.
     *
     * @param  list<int>  $insurerIds
     */
    protected function scope(Builder $query, array $insurerIds, Period $from, Period $to, ?string $city): Builder
    {
        return $this->window->narrow(
            $this->ledgerWindow->apply($query, $from, $to)->whereIn('declarations.insurer_id', $insurerIds),
            $city,
        );
    }
}
