<?php

namespace App\Services\Network;

use App\Data\InsurerPenaltyFigures;
use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Services\Declarations\PenaltyCalculator;
use App\Support\DayNumber;
use Illuminate\Support\Facades\DB;

/**
 * La pénalité et le pire retard, assureur par assureur, à l'échelle du réseau.
 *
 * **L'anonymat est structurel.** forInsurers() reçoit les identifiants déjà
 * autorisés par NetworkStatsService::perInsurer(). Un assureur sous le seuil
 * n'est pas filtré à la sortie : il n'entre jamais dans la requête. C'est plus
 * sûr qu'une retenue de plus, et ça évite de calculer pour rien.
 *
 * **Pourquoi pas une méthode de NetworkStatsService**, dont l'en-tête demande
 * pourtant de concentrer les agrégats : perInsurer() est appelée par
 * maskedInsurerCount(), que ConsoleNavigation invoque sur *chaque* page admin.
 * Y greffer une boucle PHP ferait payer le calcul de pénalité à l'écran des
 * pharmacies inscrites. Et tout le reste de cette classe-là est du SQL — une
 * boucle sur des dizaines de milliers de lignes n'a pas la même nature.
 *
 * Quatre requêtes, quel que soit le volume : les clauses, l'agrégat de délai,
 * les versements, le curseur des déclarations.
 */
class InsurerPenaltyAggregates
{
    public function __construct(
        protected DeclarationWindow $window,
        protected PenaltyCalculator $penalties,
    ) {
        //
    }

    /**
     * @param  list<int>  $insurerIds  déjà autorisés par le seuil d'anonymat
     * @return array<int, InsurerPenaltyFigures>
     */
    public function forInsurers(array $insurerIds, Period $from, Period $to, ?string $city = null): array
    {
        if ($insurerIds === []) {
            return [];
        }

        $clauses = $this->clauses($insurerIds);
        $delays = $this->longestDelays($insurerIds, $from, $to, $city);
        $penalties = $this->penaltiesByInsurer(array_keys($clauses), $clauses, $from, $to, $city);

        $figures = [];

        foreach ($insurerIds as $insurerId) {
            $figures[$insurerId] = new InsurerPenaltyFigures(
                // La décision null/zéro se prend sur la convention, pas sur les
                // lignes : un assureur sous contrat dont rien n'a couru doit
                // lire « 0 », pas « — » qui signifierait « pas de contrat ».
                penalty: isset($clauses[$insurerId]) ? ($penalties[$insurerId] ?? 0) : null,
                longestDelayDays: $delays[$insurerId] ?? null,
            );
        }

        return $figures;
    }

    /**
     * Les clauses de pénalité des assureurs demandés, les sans-clause omis.
     *
     * @param  list<int>  $insurerIds
     * @return array<int, array{0: int, 1: int}> déclenchement, taux en points de base
     */
    protected function clauses(array $insurerIds): array
    {
        return DB::table('insurers')
            ->whereIn('id', $insurerIds)
            ->whereNotNull('penalty_trigger_days')
            ->whereNotNull('penalty_rate_bp')
            ->get(['id', 'penalty_trigger_days', 'penalty_rate_bp'])
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->id => [(int) $row->penalty_trigger_days, (int) $row->penalty_rate_bp],
            ])
            ->all();
    }

    /**
     * Le pire retard par assureur, entièrement en SQL.
     *
     * La définition prend, par déclaration, l'âge de l'encours si elle est
     * ouverte, sinon son delay_days, et ignore les rejetées. Regroupée par
     * assureur, elle se scinde en deux agrégats purs : le pire délai des mois
     * soldés, et la plus vieille facture encore due. Prendre le maximum dans
     * chaque groupe puis entre les groupes donne le maximum global — un test
     * confronte le résultat à LongestDelay, déclaration par déclaration.
     *
     * @param  list<int>  $insurerIds
     * @return array<int, int>
     */
    protected function longestDelays(array $insurerIds, Period $from, Period $to, ?string $city): array
    {
        $rows = $this->window->query($from, $to, $city)
            ->whereIn('declarations.insurer_id', $insurerIds)
            ->select('declarations.insurer_id')
            ->selectRaw(
                'MAX(CASE WHEN declarations.status != ? AND declarations.amount_invoiced <= declarations.amount_received THEN declarations.delay_days END) as worst_settled',
                [DeclarationStatus::Rejected->value],
            )
            ->selectRaw(
                'MIN(CASE WHEN declarations.status != ? AND declarations.amount_invoiced > declarations.amount_received AND declarations.invoice_deposited_on IS NOT NULL THEN declarations.invoice_deposited_on END) as oldest_open',
                [DeclarationStatus::Rejected->value],
            )
            ->groupBy('declarations.insurer_id')
            ->get();

        $today = DayNumber::today();
        $longest = [];

        foreach ($rows as $row) {
            $candidates = [];

            if ($row->worst_settled !== null) {
                $candidates[] = (int) $row->worst_settled;
            }

            if ($row->oldest_open !== null) {
                // L'âge se compte depuis le dépôt, même horloge que delay_days
                // et OverdueLine::ageDays — surtout pas depuis la fin du mois
                // déclaré, qui est celle des tranches d'ancienneté.
                $candidates[] = $today - DayNumber::fromDate((string) $row->oldest_open);
            }

            if ($candidates !== []) {
                $longest[(int) $row->insurer_id] = max($candidates);
            }
        }

        return $longest;
    }

    /**
     * La pénalité cumulée par assureur, boucle restreinte aux conventions.
     *
     * Un assureur sans clause n'entre pas dans la requête : si deux assureurs
     * sur huit ont une convention pénalisante, on lit un quart des lignes.
     *
     * @param  list<int>  $insurerIds  ceux qui portent une clause
     * @param  array<int, array{0: int, 1: int}>  $clauses
     * @return array<int, int>
     */
    protected function penaltiesByInsurer(array $insurerIds, array $clauses, Period $from, Period $to, ?string $city): array
    {
        if ($insurerIds === []) {
            return [];
        }

        $payments = $this->instalments($insurerIds, $from, $to, $city);

        // Hissé hors de la boucle : DayNumber::today() traverse Carbon, et le
        // laisser se recalculer par ligne coûtait 406 ms sur 40 000
        // déclarations contre 52 une fois sorti.
        $today = DayNumber::today();
        $totals = [];

        $declarations = $this->window->query($from, $to, $city)
            ->whereIn('declarations.insurer_id', $insurerIds)
            ->where('declarations.status', '!=', DeclarationStatus::Rejected->value)
            ->whereNotNull('declarations.invoice_deposited_on')
            ->select(
                'declarations.id',
                'declarations.insurer_id',
                'declarations.amount_invoiced',
                'declarations.amount_received',
                'declarations.invoice_deposited_on',
                'declarations.paid_on',
            )
            // cursor() et non get() : à 40 000 lignes, la collection
            // matérialisée coûte plus que tout le reste du calcul.
            ->cursor();

        foreach ($declarations as $declaration) {
            $insurerId = (int) $declaration->insurer_id;
            [$triggerDays, $rateBp] = $clauses[$insurerId];

            $totals[$insurerId] = ($totals[$insurerId] ?? 0) + $this->penalties->accruedInDays(
                amountInvoiced: (int) $declaration->amount_invoiced,
                amountReceived: (int) $declaration->amount_received,
                depositedDay: DayNumber::fromDate((string) $declaration->invoice_deposited_on),
                paidDay: $declaration->paid_on === null
                    ? null
                    : DayNumber::fromDate((string) $declaration->paid_on),
                triggerDays: $triggerDays,
                rateBp: $rateBp,
                payments: $payments[$declaration->id] ?? [],
                today: $today,
            );
        }

        return $totals;
    }

    /**
     * Les versements des déclarations concernées, groupés, en une requête.
     *
     * Jointe plutôt que filtrée par whereIn : à 40 000 déclarations, la liste
     * d'identifiants dépasserait ce que MySQL accepte dans une clause IN, et
     * la construire coûterait déjà plus que la requête.
     *
     * @param  list<int>  $insurerIds
     * @return array<int, list<array{0: int, 1: int}>>
     */
    protected function instalments(array $insurerIds, Period $from, Period $to, ?string $city): array
    {
        $query = DB::table('declaration_payments')
            ->join('declarations', 'declarations.id', '=', 'declaration_payments.declaration_id')
            ->whereIn('declarations.insurer_id', $insurerIds);

        $rows = $this->window->apply($query, $from, $to, $city)
            ->orderBy('declaration_payments.paid_on')
            ->select(
                'declaration_payments.declaration_id',
                'declaration_payments.amount',
                'declaration_payments.paid_on',
            )
            ->cursor();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->declaration_id][] = [
                (int) $row->amount,
                DayNumber::fromDate((string) $row->paid_on),
            ];
        }

        return $grouped;
    }
}
