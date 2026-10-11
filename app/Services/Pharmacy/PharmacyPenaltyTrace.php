<?php

namespace App\Services\Pharmacy;

use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Declarations\PenaltyCalculator;
use App\Support\DayNumber;
use App\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * La pénalité exigible aujourd'hui, facture par facture, et comment on y est arrivé.
 *
 * Le journal mensuel dit combien a couru chaque mois ; cette trace dit d'où
 * vient chaque franc : dépôt, déclenchement, chaque tranche avec son calcul,
 * chaque versement, le solde ou la clôture. Les tranches sortent de
 * PenaltyCalculator::tranches() — le même algorithme que partout ailleurs, pour
 * que la trace et le tableau de bord ne puissent pas diverger.
 *
 * Indépendante de la période : la pénalité exigible se lit à la date du jour.
 * Query builder et non Eloquent, comme PharmacyPenaltyLedger : `private_note`
 * n'a rien à faire ici. Trois requêtes quel que soit le nombre de factures.
 *
 * @phpstan-type TraceEvent array{kind: 'deposit'|'trigger'|'tranche'|'payment'|'paidOff'|'settled', on: string, date: string, amount: int|null, number: int|null, cumulative: int|null, remaining: int|null, label: string|null}
 * @phpstan-type TraceInvoice array{id: int, period: string, periodLabel: string, amountInvoiced: int, amountReceived: int, depositedOn: string, triggerOn: string, trancheAmount: int, tranches: int, accrued: int, due: int, running: bool, nextTrancheOn: string|null, settlement: array{label: string, amount: int|null, on: string|null}|null, events: list<TraceEvent>}
 * @phpstan-type TraceInsurer array{id: int, name: string, triggerDays: int, ratePercent: float, trancheDays: int, accrued: int, due: int, invoices: list<TraceInvoice>}
 */
class PharmacyPenaltyTrace
{
    public function __construct(
        protected PenaltyCalculator $penalties,
        protected PharmacyPenaltyLedger $ledger,
    ) {
        //
    }

    /**
     * @return array{asOf: string, asOfDate: string, due: int, insurers: list<TraceInsurer>}
     */
    public function for(Pharmacy $pharmacy, ?int $insurerId = null): array
    {
        $today = DayNumber::today();

        $clauses = $this->ledger->clauseInsurers($pharmacy)
            ->when($insurerId !== null, fn (Collection $all) => $all->where('id', $insurerId))
            ->keyBy('id');

        $declarations = $clauses->isEmpty() ? collect() : DB::table('declarations')
            ->where('pharmacy_id', $pharmacy->id)
            ->whereIn('insurer_id', $clauses->keys()->all())
            ->whereNotNull('invoice_deposited_on')
            ->where('status', '!=', DeclarationStatus::Rejected->value)
            ->orderBy('invoice_deposited_on')
            ->get([
                'id', 'insurer_id', 'period_year', 'period_month', 'amount_invoiced', 'amount_received',
                'invoice_deposited_on', 'paid_on', 'penalty_settlement', 'penalty_settled_amount', 'penalty_settled_on',
            ]);

        $payments = DB::table('declaration_payments')
            ->whereIn('declaration_id', $declarations->pluck('id'))
            ->orderBy('paid_on')
            ->get(['declaration_id', 'amount', 'paid_on'])
            ->groupBy('declaration_id');

        $insurers = [];

        foreach ($declarations as $declaration) {
            $invoice = $this->invoice($declaration, $clauses[(int) $declaration->insurer_id], $payments[$declaration->id] ?? collect(), $today);

            if ($invoice !== null) {
                $insurers[(int) $declaration->insurer_id][] = $invoice;
            }
        }

        $rows = [];

        foreach ($insurers as $id => $invoices) {
            $clause = $clauses[$id];

            // L'exigible d'abord : c'est ce qu'on vient chercher. Les factures
            // closes suivent, dans l'ordre de leur dépôt.
            usort($invoices, fn (array $a, array $b): int => ($b['due'] > 0) <=> ($a['due'] > 0));

            $rows[] = [
                'id' => $id,
                'name' => (string) $clause->name,
                'triggerDays' => (int) $clause->penalty_trigger_days,
                'ratePercent' => (int) $clause->penalty_rate_bp / 100,
                'trancheDays' => Insurer::PENALTY_TRANCHE_DAYS,
                'accrued' => array_sum(array_column($invoices, 'accrued')),
                'due' => array_sum(array_column($invoices, 'due')),
                'invoices' => $invoices,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['due'], $a['name']] <=> [$a['due'], $b['name']]);

        return [
            'asOf' => $this->label($today),
            'asOfDate' => $this->date($today)->toDateString(),
            'due' => array_sum(array_column($rows, 'due')),
            'insurers' => $rows,
        ];
    }

    /**
     * Une facture et sa chronologie, ou null si aucune tranche n'a encore couru.
     *
     * @param  Collection<int, stdClass>  $payments
     * @return TraceInvoice|null
     */
    protected function invoice(stdClass $declaration, stdClass $clause, Collection $payments, int $today): ?array
    {
        $invoiced = (int) $declaration->amount_invoiced;
        $received = (int) $declaration->amount_received;
        $depositedDay = DayNumber::fromDate((string) $declaration->invoice_deposited_on);
        $paidDay = $declaration->paid_on === null ? null : DayNumber::fromDate((string) $declaration->paid_on);
        $triggerDay = $depositedDay + (int) $clause->penalty_trigger_days;
        $paymentDays = array_values($payments->map(fn (stdClass $payment): array => [
            (int) $payment->amount,
            DayNumber::fromDate((string) $payment->paid_on),
        ])->all());

        $tranches = $this->penalties->tranches(
            amountInvoiced: $invoiced,
            amountReceived: $received,
            depositedDay: $depositedDay,
            paidDay: $paidDay,
            triggerDays: (int) $clause->penalty_trigger_days,
            rateBp: (int) $clause->penalty_rate_bp,
            payments: $paymentDays,
            today: $today,
        );

        if ($tranches === []) {
            return null;
        }

        // [jour, ordre dans la journée, événement] : à date égale, le dépôt
        // précède le déclenchement, qui précède la tranche, puis les versements.
        $timeline = [
            [$depositedDay, 0, $this->event('deposit', $depositedDay, amount: $invoiced)],
            [$triggerDay, 1, $this->event('trigger', $triggerDay)],
        ];

        $cumulative = 0;

        foreach ($tranches as $index => [$day, $amount]) {
            $cumulative += $amount;
            $timeline[] = [$day, 2, $this->event('tranche', $day, amount: $amount, number: $index + 1, cumulative: $cumulative)];
        }

        $remaining = $invoiced;

        foreach ($paymentDays as [$amount, $day]) {
            $remaining -= $amount;
            $timeline[] = [$day, 3, $this->event('payment', $day, amount: $amount, remaining: max($remaining, 0))];
        }

        $running = $received < $invoiced;
        $paidOffDay = $running ? null : ($this->penalties->paidOffDay($invoiced, $paymentDays) ?? $paidDay);

        if ($paidOffDay !== null) {
            $timeline[] = [$paidOffDay, 4, $this->event('paidOff', $paidOffDay)];
        }

        $settlement = PenaltySettlement::tryFrom((string) $declaration->penalty_settlement);

        if ($settlement !== null && $declaration->penalty_settled_on !== null) {
            $day = DayNumber::fromDate((string) $declaration->penalty_settled_on);
            $timeline[] = [$day, 5, $this->event('settled', $day, amount: (int) $declaration->penalty_settled_amount, label: $settlement->label())];
        }

        usort($timeline, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $lastTranche = $tranches[array_key_last($tranches)][0];

        return [
            'id' => (int) $declaration->id,
            'period' => sprintf('%04d-%02d', (int) $declaration->period_year, (int) $declaration->period_month),
            'periodLabel' => MonthLabel::short((int) $declaration->period_month, (int) $declaration->period_year),
            'amountInvoiced' => $invoiced,
            'amountReceived' => $received,
            'depositedOn' => $this->label($depositedDay),
            'triggerOn' => $this->label($triggerDay),
            'trancheAmount' => $tranches[0][1],
            'tranches' => count($tranches),
            'accrued' => $cumulative,
            'due' => $settlement === null ? $cumulative : 0,
            'running' => $running,
            'nextTrancheOn' => $running && $settlement === null
                ? $this->label($lastTranche + Insurer::PENALTY_TRANCHE_DAYS)
                : null,
            'settlement' => $settlement === null ? null : [
                'label' => $settlement->label(),
                'amount' => $declaration->penalty_settled_amount === null ? null : (int) $declaration->penalty_settled_amount,
                'on' => $declaration->penalty_settled_on === null ? null : $this->label(DayNumber::fromDate((string) $declaration->penalty_settled_on)),
            ],
            'events' => array_column($timeline, 2),
        ];
    }

    /**
     * @param  'deposit'|'trigger'|'tranche'|'payment'|'paidOff'|'settled'  $kind
     * @return TraceEvent
     */
    protected function event(
        string $kind,
        int $day,
        ?int $amount = null,
        ?int $number = null,
        ?int $cumulative = null,
        ?int $remaining = null,
        ?string $label = null,
    ): array {
        return [
            'kind' => $kind,
            'on' => $this->label($day),
            'date' => $this->date($day)->toDateString(),
            'amount' => $amount,
            'number' => $number,
            'cumulative' => $cumulative,
            'remaining' => $remaining,
            'label' => $label,
        ];
    }

    /** « 14 juin 2026 » */
    protected function label(int $day): string
    {
        return $this->date($day)->translatedFormat('j M Y');
    }

    protected function date(int $day): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($day * 86400);
    }
}
