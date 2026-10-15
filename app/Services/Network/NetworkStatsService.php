<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\InsurerAmounts;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Data\RegionScope;
use App\Enums\DeclarationStatus;
use App\Models\Insurer;
use App\Services\Settings\SettingsRepository;
use App\Support\MonthLabel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single path by which an APhaSPB account reads declarations.
 *
 * Concentrating every aggregate here is what makes the anonymity rule
 * verifiable: one place enforces the threshold, one test covers it. No
 * controller may query the declarations table directly.
 *
 * Reads go through the query builder rather than Eloquent on purpose: an
 * aggregate row is not a Declaration, and hydrating models would both mislead
 * the type system and load columns — private notes among them — that no
 * aggregate is allowed to touch.
 */
class NetworkStatsService
{
    /**
     * Count settled declarations paid within their own insurer's standard delay.
     *
     * A constant rather than a method: selectRaw() takes a literal string, and
     * this fragment is shared by the per-insurer and network aggregates, which
     * must never drift into judging the same delay by two different rules.
     */
    /**
     * Distinct officines an aggregate still owes money to.
     *
     * Since the insurer sheet names them (15/10/2026), any amount derived
     * from what is still owed — received, outstanding, recovery rate — must
     * rest on at least the threshold of them, not merely on the declarants.
     */
    protected const CREDITORS_COUNT = 'COUNT(DISTINCT CASE WHEN declarations.amount_invoiced > declarations.amount_received THEN declarations.pharmacy_id END) as creditors';

    protected const WITHIN_STANDARD_DELAY_SUM = "SUM(CASE WHEN status IN ('paid', 'partial') AND delay_days <= insurers.standard_delay_days THEN 1 ELSE 0 END) as within_threshold";

    public function __construct(
        protected SettingsRepository $settings,
        protected DeclarationWindow $window,
    ) {
        //
    }

    /**
     * Aggregate each insurer's indicators over an inclusive period range.
     *
     * @return array<int, InsurerIndicators|InsufficientData> keyed by insurer id
     */
    public function perInsurer(Period $from, Period $to, string|RegionScope|null $city = null, ?int $insurerId = null): array
    {
        $minimum = $this->settings->anonymityMinPharmacies();

        $rows = $this->withStandardDelay($this->baseQuery($from, $to, $city, $insurerId))
            ->select('insurer_id', 'insurers.standard_delay_days', 'insurers.penalty_trigger_days', 'insurers.penalty_rate_bp')
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(amount_invoiced) as amount_invoiced')
            ->selectRaw('SUM(amount_received) as amount_received')
            ->selectRaw(self::CREDITORS_COUNT)
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN 1 ELSE 0 END) as settled")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days ELSE 0 END) as delay_total")
            ->selectRaw(self::WITHIN_STANDARD_DELAY_SUM)
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw("SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) as unpaid")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days * amount_received ELSE 0 END) as delay_weighted")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN amount_received ELSE 0 END) as delay_basis")
            ->groupBy('insurer_id', 'insurers.standard_delay_days', 'insurers.penalty_trigger_days', 'insurers.penalty_rate_bp')
            ->get();

        $names = Insurer::query()
            ->whereIn('id', $rows->pluck('insurer_id'))
            ->pluck('name', 'id');

        $instalments = $this->instalmentAggregates($from, $to, $city, $insurerId);
        $cells = is_string($city) ? [] : $this->partitionCells($from, $to, $insurerId);
        $partition = $this->declaringPerCity($cells);
        $creditors = $this->creditorsPerCity($cells);

        $indicators = [];

        foreach ($rows as $row) {
            $declaring = (int) $row->declaring_pharmacies;
            $insurerId = (int) $row->insurer_id;

            if (! $this->isSufficient($declaring, $minimum)) {
                $indicators[$insurerId] = new InsufficientData(
                    declaringPharmacies: $declaring,
                    required: $minimum,
                );

                continue;
            }

            if (CityPartition::withholdsIn($city, $partition[$insurerId] ?? [], $minimum)) {
                $indicators[$insurerId] = new InsufficientData($declaring, $minimum, cityShare: true);

                continue;
            }

            $total = (int) $row->total;
            $settled = (int) $row->settled;
            $invoiced = (int) $row->amount_invoiced;
            $received = (int) $row->amount_received;
            $basis = (int) $row->delay_basis;
            $owedWithheld = $this->owedWithheld((int) $row->creditors, $creditors[$insurerId] ?? [], $city, $minimum);

            $indicators[$insurerId] = new InsurerIndicators(
                insurerName: (string) $names[$insurerId],
                declaringPharmacies: $declaring,
                declarations: $total,
                averageDelayDays: $settled > 0 ? round((int) $row->delay_total / $settled, 1) : null,
                weightedDelayDays: $basis > 0 ? round((int) $row->delay_weighted / $basis, 1) : null,
                standardDelayDays: (int) $row->standard_delay_days,
                // Deux colonnes SQL de plus, pas une boucle : maskedInsurerCount(),
                // que ConsoleNavigation appelle sur chaque page admin, reste
                // aussi rapide qu'avant.
                penaltyTriggerDays: $row->penalty_trigger_days === null ? null : (int) $row->penalty_trigger_days,
                penaltyRatePercent: $row->penalty_rate_bp === null ? null : (float) ((int) $row->penalty_rate_bp / 100),
                withinThresholdShare: $settled > 0 ? round((int) $row->within_threshold / $settled * 100, 1) : null,
                rejectionRate: $total > 0 ? round((int) $row->rejected / $total * 100, 1) : null,
                unpaidRate: $total > 0 ? round((int) $row->unpaid / $total * 100, 1) : null,
                amountInvoiced: $invoiced,
                amountReceived: $owedWithheld ? null : $received,
                amountOutstanding: $owedWithheld ? null : max(0, $invoiced - $received),
                recoveryRate: $owedWithheld || $invoiced <= 0 ? null : round($received / $invoiced * 100, 1),
                recoveredWithinDelayShare: $this->recoveredShare(
                    $instalments[$insurerId] ?? null,
                    $invoiced,
                    $received,
                ),
                instalments: (int) ($instalments[$insurerId]->instalments ?? 0),
                instalmentsPerDeclaration: $this->perSettled($instalments[$insurerId] ?? null, 'instalments'),
                multiInstalmentShare: $this->shareOfSettled($instalments[$insurerId] ?? null, 'multi_instalment'),
                averageFirstInstalmentDelayDays: $this->perDated($instalments[$insurerId] ?? null, 'first_delay_total'),
            );
        }

        return $indicators;
    }

    /**
     * What the instalments say about each insurer, beyond the declarations.
     *
     * Read from the payment rows, not from the declarations: a month settled in
     * two transfers is on time for the first and late for the second, and only
     * the rows know which share fell on which side. `declarations.delay_days`
     * cannot answer that — it holds the delay of the **last** transfer, so it
     * marks a whole month late over a residual payment.
     *
     * Two levels of grouping. The inner one folds together the payments of a
     * single declaration, which is the only place « how many transfers did this
     * month take » and « when did the money first move » exist at all; the
     * outer one then averages those over the insurer.
     *
     * A separate query rather than a join into the main aggregate: joining the
     * payments would multiply the declaration rows and quietly inflate every
     * COUNT and every SUM of an invoiced amount beside it.
     *
     * @return Collection<int|string, \stdClass> aggregates keyed by insurer id
     */
    protected function instalmentAggregates(Period $from, Period $to, string|RegionScope|null $city = null, ?int $insurerId = null): Collection
    {
        $perDeclaration = $this->withStandardDelay($this->baseQuery($from, $to, $city, $insurerId))
            ->join('declaration_payments', 'declaration_payments.declaration_id', '=', 'declarations.id')
            ->select('declarations.insurer_id', 'declaration_payments.declaration_id')
            ->selectRaw('COUNT(*) as instalments')
            ->selectRaw('MIN(declaration_payments.delay_days) as first_delay')
            ->selectRaw('SUM(declaration_payments.amount) as recorded_amount')
            ->selectRaw('SUM(CASE WHEN declaration_payments.delay_days <= insurers.standard_delay_days THEN declaration_payments.amount ELSE 0 END) as recovered_within')
            ->groupBy('declarations.insurer_id', 'declaration_payments.declaration_id');

        return DB::query()
            ->fromSub($perDeclaration, 'per_declaration')
            ->select('insurer_id')
            ->selectRaw('SUM(instalments) as instalments')
            ->selectRaw('SUM(recovered_within) as recovered_within')
            ->selectRaw('COUNT(*) as settled_declarations')
            ->selectRaw('SUM(CASE WHEN instalments > 1 THEN 1 ELSE 0 END) as multi_instalment')
            ->selectRaw('SUM(first_delay) as first_delay_total')
            // COUNT sur la colonne, pas sur les lignes : un versement repris
            // par la migration depuis une déclaration sans date de dépôt porte
            // un délai NULL, que SUM ignore. Compter la ligne quand même
            // rabaisserait le délai du premier versement de cet assureur.
            ->selectRaw('COUNT(first_delay) as first_delay_count')
            ->selectRaw('SUM(recorded_amount) as recorded_amount')
            ->groupBy('insurer_id')
            ->get()
            ->keyBy('insurer_id');
    }

    /**
     * The share of the invoiced amount that arrived within the standard delay.
     *
     * Null as soon as some of the money received is not backed by instalments.
     * That is the state of every declaration filed before payment dates
     * existed: reporting « 0 % recouvré dans les délais » for an insurer that
     * did pay would be an accusation made out of missing data.
     *
     * Compared amount to amount rather than « has it any instalment at all »:
     * one undocumented month among documented ones is exactly the case where a
     * per-insurer flag would let the figure through, silently crediting the
     * insurer with none of that money. An insurer that genuinely received
     * nothing keeps its honest zero.
     */
    protected function recoveredShare(?object $row, int $invoiced, int $received): ?float
    {
        if ($invoiced <= 0) {
            return null;
        }

        if ($received > (int) ($row->recorded_amount ?? 0)) {
            return null;
        }

        return round((int) ($row->recovered_within ?? 0) / $invoiced * 100, 1);
    }

    /**
     * A per-declaration average of one instalment aggregate, to one decimal.
     *
     * The denominator is the number of declarations that received money at all:
     * an unpaid month has no transfer to average, and counting it would report
     * « 0,4 transfer per declaration » as though insurers paid in fractions.
     */
    protected function perSettled(?object $row, string $column): ?float
    {
        $settled = (int) ($row->settled_declarations ?? 0);

        return $settled > 0 ? round((int) $row->{$column} / $settled, 1) : null;
    }

    /**
     * The same, over the declarations that actually carry a first delay.
     *
     * Kept apart from perSettled() on purpose: a declaration whose transfers
     * have no delay contributes nothing to the sum, so counting it in the
     * denominator would report a shorter delay than any transfer took.
     */
    protected function perDated(?object $row, string $column): ?float
    {
        $dated = (int) ($row->first_delay_count ?? 0);

        return $dated > 0 ? round((int) $row->{$column} / $dated, 1) : null;
    }

    /**
     * The same denominator, read as a percentage.
     */
    protected function shareOfSettled(?object $row, string $column): ?float
    {
        $settled = (int) ($row->settled_declarations ?? 0);

        return $settled > 0 ? round((int) $row->{$column} / $settled * 100, 1) : null;
    }

    /**
     * How many insurers the anonymity threshold currently hides.
     *
     * Son seul appelant de production était l'encart « Seuil d'affichage » de
     * la barre latérale, supprimé le 22/09/2026 : la méthode n'est plus lue
     * que par ses tests, en attendant qu'un écran redise la règle.
     */
    public function maskedInsurerCount(): int
    {
        [$from, $to] = Period::currentQuarter();

        return count(array_filter(
            $this->perInsurer($from, $to),
            fn (InsurerIndicators|InsufficientData $entry): bool => $entry instanceof InsufficientData,
        ));
    }

    /**
     * Monthly average delay per insurer, plus the network average.
     *
     * The clearance of perInsurer() holds for the period only: an insurer
     * declared by five officines over the year may have had a single one in
     * March, and that point would be its delay. Each insurer-month therefore
     * carries its own distinct count, and a point resting on fewer officines
     * than the threshold is withheld — listed under `withheld`, never drawn.
     *
     * The network line averages the **published** points only. A month whose
     * points are all withheld is withheld as well (`withheldMonths`); and
     * since each published point rests on the threshold, so does every
     * network point. Averaging the hidden points in would let the network
     * line, minus the visible ones, give a withheld point back.
     *
     * @return array{insurers: array<int, array{name: string, points: array<string, float>, withheld: list<string>}>, network: array<string, float>, withheldMonths: list<string>, threshold: int, required: int}
     */
    public function delayTrend(Period $from, Period $to, string|RegionScope|null $city = null, ?int $insurerId = null): array
    {
        $minimum = $this->settings->anonymityMinPharmacies();

        $eligible = array_keys(array_filter(
            $this->perInsurer($from, $to, $city),
            fn (InsurerIndicators|InsufficientData $entry): bool => $entry instanceof InsurerIndicators,
        ));

        $rows = $this->baseQuery($from, $to, $city, $insurerId)
            ->whereIn('insurer_id', $eligible)
            ->whereIn('status', DeclarationStatus::settledValues())
            ->select('insurer_id', 'period_year', 'period_month')
            ->selectRaw('AVG(delay_days) as average_delay')
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->groupBy('insurer_id', 'period_year', 'period_month')
            ->get();

        $names = Insurer::query()->whereIn('id', $eligible)->pluck('name', 'id');

        // Non filtré par ville (ou filtré par régions), un point est aussi
        // publié ville par ville : il suit la règle de partition, au même
        // grain (assureur × mois). Comptes de tout le réseau, dans les deux cas.
        $partition = [];

        if (! is_string($city) && $eligible !== []) {
            $counts = $this->perCity(
                $this->baseQuery($from, $to, null, $insurerId)
                    ->whereIn('declarations.insurer_id', $eligible)
                    ->whereIn('declarations.status', DeclarationStatus::settledValues()),
                ['declarations.insurer_id', 'declarations.period_year', 'declarations.period_month'],
            );

            foreach ($counts as $count) {
                $key = sprintf('%04d-%02d', $count->period_year, $count->period_month);
                // += : NULL et '' sont deux groupes SQL, une seule clé « sans ville ».
                $partition[(int) $count->insurer_id][$key][CityPartition::key($count->city)] = ($partition[(int) $count->insurer_id][$key][CityPartition::key($count->city)] ?? 0) + (int) $count->pharmacies;
            }
        }

        $insurers = [];
        $networkTotals = [];
        $months = [];

        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', $row->period_year, $row->period_month);
            $insurerId = (int) $row->insurer_id;
            $average = (float) $row->average_delay;

            $insurers[$insurerId]['name'] ??= (string) $names[$insurerId];
            $insurers[$insurerId]['points'] ??= [];
            $insurers[$insurerId]['withheld'] ??= [];
            $months[$key] = true;

            if (! $this->isSufficient((int) $row->declaring_pharmacies, $minimum)
                || CityPartition::withholdsIn($city, $partition[$insurerId][$key] ?? [], $minimum)) {
                $insurers[$insurerId]['withheld'][] = $key;

                continue;
            }

            $insurers[$insurerId]['points'][$key] = round($average, 1);

            $networkTotals[$key][] = $average;
        }

        $network = [];

        foreach ($networkTotals as $key => $values) {
            $network[$key] = round(array_sum($values) / count($values), 1);
        }

        ksort($network);

        foreach ($insurers as $id => $series) {
            sort($series['withheld']);
            $insurers[$id]['withheld'] = $series['withheld'];
        }

        $withheldMonths = array_values(array_diff(array_keys($months), array_keys($network)));
        sort($withheldMonths);

        return [
            'insurers' => $insurers,
            'network' => $network,
            'withheldMonths' => $withheldMonths,
            'threshold' => $this->averageStandardDelayDays(),
            'required' => $minimum,
        ];
    }

    /**
     * Le détail mois par mois des assureurs demandés, le plus récent en tête.
     *
     * Alimente les pages par assureur du rapport PDF. Voisine de delayTrend()
     * sans la remplacer : celle-là ne rend qu'une moyenne de délai parce
     * qu'elle alimente un graphique, et lui ajouter quatre colonnes ferait
     * payer ce poids à l'écran des tendances.
     *
     * Les identifiants passés sont ceux que perInsurer() a déjà autorisés.
     *
     * **Mais l'autorisation de l'assureur ne vaut pas pour chacun de ses mois.**
     * Le seuil de perInsurer() porte sur les officines déclarantes de toute la
     * période ; un assureur qui en compte cinq peut n'en avoir qu'une sur un
     * mois donné, et cette ligne-là rendrait ses chiffres exacts. Chaque ligne
     * porte donc son propre `declaringPharmacies`, et c'est à l'appelant — qui
     * détient le seuil — de retenir ce qui doit l'être.
     *
     * Non filtré par ville, chaque ligne porte aussi ses officines ville par
     * ville (`cityPharmacies`, '' sans ville) : le même mois se publie dans
     * le rapport de chaque ville, et l'appelant applique la règle de
     * partition (CityPartition). Vide sous filtre ville.
     *
     * @param  list<int>  $insurerIds
     *                                 `creditorPharmacies` / `creditorCityPharmacies` : the same, counting the
     *                                 officines still owed money — the caller withholds received and
     *                                 outstanding on them (creditor rule, 15/10/2026).
     * @return array<int, list<array{year: int, month: int, monthLabel: string, declaringPharmacies: int, cityPharmacies: array<string, int>, creditorPharmacies: int, creditorCityPharmacies: array<string, int>, declarations: int, invoiced: int, received: int, outstanding: int, averageDelayDays: float|null}>>
     */
    public function monthlyByInsurer(array $insurerIds, Period $from, Period $to, string|RegionScope|null $city = null, ?int $insurerId = null): array
    {
        if ($insurerIds === []) {
            return [];
        }

        $rows = $this->baseQuery($from, $to, $city, $insurerId)
            ->whereIn('declarations.insurer_id', $insurerIds)
            ->select('declarations.insurer_id', 'declarations.period_year', 'declarations.period_month')
            ->selectRaw('COUNT(DISTINCT declarations.pharmacy_id) as declaring_pharmacies')
            ->selectRaw('COUNT(*) as declarations')
            ->selectRaw('SUM(declarations.amount_invoiced) as invoiced')
            ->selectRaw('SUM(declarations.amount_received) as received')
            ->selectRaw(self::CREDITORS_COUNT)
            ->selectRaw(
                'AVG(CASE WHEN declarations.status IN (?, ?) THEN declarations.delay_days END) as average_delay',
                DeclarationStatus::settledValues(),
            )
            ->groupBy('declarations.insurer_id', 'declarations.period_year', 'declarations.period_month')
            ->orderByDesc('declarations.period_year')
            ->orderByDesc('declarations.period_month')
            ->get();

        $cities = [];
        $creditorCities = [];

        // Tout le réseau, filtre régions compris : la règle régionale lit la
        // partition entière (CityPartition). Les créancières aussi, pour le
        // reçu et l'encours du mois.
        if (! is_string($city)) {
            $all = $this->baseQuery($from, $to, null, $insurerId)->whereIn('declarations.insurer_id', $insurerIds);

            $cities = $this->monthlyCityCounts(clone $all);
            $creditorCities = $this->monthlyCityCounts(
                $all->whereColumn('declarations.amount_invoiced', '>', 'declarations.amount_received'),
            );
        }

        $monthly = [];

        foreach ($rows as $row) {
            $invoiced = (int) $row->invoiced;
            $received = (int) $row->received;

            $monthly[(int) $row->insurer_id][] = [
                'year' => (int) $row->period_year,
                'month' => (int) $row->period_month,
                'monthLabel' => MonthLabel::short((int) $row->period_month, (int) $row->period_year),
                'declaringPharmacies' => (int) $row->declaring_pharmacies,
                'cityPharmacies' => $cities[sprintf('%d|%d|%d', $row->insurer_id, $row->period_year, $row->period_month)] ?? [],
                'creditorPharmacies' => (int) $row->creditors,
                'creditorCityPharmacies' => $creditorCities[sprintf('%d|%d|%d', $row->insurer_id, $row->period_year, $row->period_month)] ?? [],
                'declarations' => (int) $row->declarations,
                'invoiced' => $invoiced,
                'received' => $received,
                'outstanding' => max(0, $invoiced - $received),
                'averageDelayDays' => $row->average_delay === null ? null : round((float) $row->average_delay, 1),
            ];
        }

        return $monthly;
    }

    /**
     * Distinct officines per city, for each insurer × month cell.
     *
     * @return array<string, array<string, int>> keyed « insurer|year|month », then by city key
     */
    protected function monthlyCityCounts(Builder $query): array
    {
        $cells = [];

        $counts = $this->perCity(
            $query,
            ['declarations.insurer_id', 'declarations.period_year', 'declarations.period_month'],
        );

        foreach ($counts as $count) {
            $cell = sprintf('%d|%d|%d', $count->insurer_id, $count->period_year, $count->period_month);
            $cityKey = CityPartition::key($count->city);
            // += : NULL et '' sont deux groupes SQL, une seule clé « sans ville ».
            $cells[$cell][$cityKey] = ($cells[$cell][$cityKey] ?? 0) + (int) $count->pharmacies;
        }

        return $cells;
    }

    /**
     * Network-wide delay indicators over a period.
     *
     * Not derivable from perInsurer(): averaging per-insurer averages would
     * weight a two-declaration insurer like a two-hundred-declaration one, and
     * the distinct pharmacy count cannot be summed across insurers because one
     * officine declares to several.
     *
     * **Withheld under the threshold, like any other aggregate.** Once filtered
     * on a city or an insurer — or simply at launch, with one declarant — this
     * summary may rest on a single officine, and the declaration follow-up
     * names the officines that declared. Every figure is then emptied
     * (`withheld`), the exact count included: « moins de N » is all that
     * leaves. Zero declarant is not withheld — it reads « rien déclaré ».
     *
     * @return array{withheld: bool, withheldReason: 'too-few'|'city-share'|null, required: int, declaringPharmacies: int|null, declarations: int|null, averageDelayDays: float|null, weightedDelayDays: float|null, withinThresholdShare: float|null, rejectionRate: float|null, outstandingBeyond90: int|null}
     */
    public function networkSummary(Period $from, Period $to, string|RegionScope|null $city = null, ?int $insurerId = null): array
    {
        $minimum = $this->settings->anonymityMinPharmacies();

        $row = $this->withStandardDelay($this->baseQuery($from, $to, $city, $insurerId))
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN 1 ELSE 0 END) as settled")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days ELSE 0 END) as delay_total")
            ->selectRaw(self::WITHIN_STANDARD_DELAY_SUM)
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days * amount_received ELSE 0 END) as delay_weighted")
            ->selectRaw("SUM(CASE WHEN status IN ('paid', 'partial') THEN amount_received ELSE 0 END) as delay_basis")
            ->selectRaw(self::CREDITORS_COUNT)
            ->first();

        $declaring = (int) ($row->declaring_pharmacies ?? 0);
        $cells = is_string($city) || $declaring < $minimum ? [] : $this->partitionCells($from, $to, $insurerId);
        $reason = $this->summaryWithheldReason($declaring, $minimum, $city, $cells);

        if ($reason !== null) {
            return [
                'withheld' => true,
                'withheldReason' => $reason,
                'required' => $minimum,
                'declaringPharmacies' => null,
                'declarations' => null,
                'averageDelayDays' => null,
                'weightedDelayDays' => null,
                'withinThresholdShare' => null,
                'rejectionRate' => null,
                'outstandingBeyond90' => null,
            ];
        }

        $total = (int) ($row->total ?? 0);
        $settled = (int) ($row->settled ?? 0);
        $basis = (int) ($row->delay_basis ?? 0);

        return [
            'withheld' => false,
            'withheldReason' => null,
            'required' => $minimum,
            'declaringPharmacies' => $declaring,
            'declarations' => $total,
            'averageDelayDays' => $settled > 0 ? round((int) $row->delay_total / $settled, 1) : null,
            'weightedDelayDays' => $basis > 0 ? round((int) $row->delay_weighted / $basis, 1) : null,
            'withinThresholdShare' => $settled > 0 ? round((int) $row->within_threshold / $settled * 100, 1) : null,
            'rejectionRate' => $total > 0 ? round((int) $row->rejected / $total * 100, 1) : null,
            // Une part de l'encours : elle tombe avec lui, et repose en plus
            // sur ses propres créancières anciennes.
            'outstandingBeyond90' => $this->summaryOwedWithheld((int) ($row->creditors ?? 0), $cells, $from, $to, $city, $insurerId, $minimum)
                ? null
                : $this->outstandingBeyond($from, $to, $city, 90, $insurerId, $minimum),
        ];
    }

    /**
     * Aggregated amounts per insurer, under the same anonymity threshold.
     *
     * A second per-insurer aggregation is a second place the rule could be
     * forgotten, so the decision is delegated to isSufficient() — the one
     * method both paths consult.
     *
     * @return array<int, InsurerAmounts|InsufficientData> keyed by insurer id
     */
    public function aggregatedByInsurer(Period $from, Period $to, string|RegionScope|null $city = null, ?int $insurerId = null): array
    {
        $minimum = $this->settings->anonymityMinPharmacies();

        $rows = $this->baseQuery($from, $to, $city, $insurerId)
            ->select('insurer_id')
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->selectRaw('SUM(amount_invoiced) as invoiced')
            ->selectRaw('SUM(amount_received) as received')
            ->selectRaw(self::CREDITORS_COUNT)
            ->groupBy('insurer_id')
            ->get();

        $names = Insurer::query()
            ->whereIn('id', $rows->pluck('insurer_id'))
            ->pluck('name', 'id');

        $cells = is_string($city) ? [] : $this->partitionCells($from, $to, $insurerId);
        $partition = $this->declaringPerCity($cells);
        $creditors = $this->creditorsPerCity($cells);

        $amounts = [];

        foreach ($rows as $row) {
            $insurerId = (int) $row->insurer_id;
            $declaring = (int) $row->declaring_pharmacies;

            if (! $this->isSufficient($declaring, $minimum)) {
                $amounts[$insurerId] = new InsufficientData($declaring, $minimum);

                continue;
            }

            // Même règle de partition que perInsurer() : les deux écrans
            // doivent retenir les mêmes assureurs.
            if (CityPartition::withholdsIn($city, $partition[$insurerId] ?? [], $minimum)) {
                $amounts[$insurerId] = new InsufficientData($declaring, $minimum, cityShare: true);

                continue;
            }

            $invoiced = (int) $row->invoiced;
            $received = (int) $row->received;
            $owedWithheld = $this->owedWithheld((int) $row->creditors, $creditors[$insurerId] ?? [], $city, $minimum);

            // Le facturé reste publié ; reçu, encours et taux tombent ensemble :
            // facturé − reçu rendrait l'encours (fiche assureur, 15/10/2026).
            $amounts[$insurerId] = new InsurerAmounts(
                insurerName: (string) $names[$insurerId],
                declaringPharmacies: $declaring,
                invoiced: $invoiced,
                received: $owedWithheld ? null : $received,
                outstanding: $owedWithheld ? null : max(0, $invoiced - $received),
                recoveryRate: $owedWithheld || $invoiced <= 0 ? null : round($received / $invoiced * 100, 1),
                owedWithheld: $owedWithheld,
            );
        }

        return $amounts;
    }

    /**
     * How much of the network's outstanding balance is older than $days.
     *
     * Age is counted from the end of the declared month, as on the officine
     * side: the CDC stores no invoice date.
     *
     * Protected: only published through networkSummary(), which withholds it
     * with the rest of the owed amounts. Null as well when the old debts
     * themselves rest on 1 to threshold − 1 creditors, or when their city
     * partition would give them back: the sheet names who is owed.
     *
     * Under a region filter the query runs on the whole network, so the same
     * rows give the scope's total and the whole partition.
     */
    protected function outstandingBeyond(Period $from, Period $to, string|RegionScope|null $city, int $days, ?int $insurerId, int $minimum): ?int
    {
        $rows = $this->baseQuery($from, $to, $city instanceof RegionScope ? null : $city, $insurerId)
            ->join('pharmacies', 'pharmacies.id', '=', 'declarations.pharmacy_id')
            ->whereColumn('declarations.amount_invoiced', '>', 'declarations.amount_received')
            ->select('declarations.period_year', 'declarations.period_month', 'declarations.pharmacy_id')
            ->selectRaw(CityPartition::canonicalCitySql('pharmacies').' as city')
            ->selectRaw('SUM(declarations.amount_invoiced - declarations.amount_received) as outstanding')
            ->groupBy('declarations.period_year', 'declarations.period_month', 'declarations.pharmacy_id', 'pharmacies.city')
            ->get();

        $today = now()->startOfDay();
        $total = 0;
        $creditors = [];
        $perCity = [];

        foreach ($rows as $row) {
            $age = (int) now()
                ->setDate((int) $row->period_year, (int) $row->period_month, 1)
                ->endOfMonth()
                ->startOfDay()
                ->diffInDays($today, absolute: false);

            if ($age <= $days) {
                continue;
            }

            $key = CityPartition::key($row->city);
            $perCity[$key][(int) $row->pharmacy_id] = true;

            if ($city instanceof RegionScope && ! $city->contains($key)) {
                continue;
            }

            $total += (int) $row->outstanding;
            $creditors[(int) $row->pharmacy_id] = true;
        }

        $owed = count($creditors);

        if (($owed > 0 && $owed < $minimum) || CityPartition::withholdsIn($city, array_map('count', $perCity), $minimum)) {
            return null;
        }

        return $total;
    }

    /**
     * The single place that decides whether an insurer's figures may be shown.
     */
    protected function isSufficient(int $declaringPharmacies, int $minimum): bool
    {
        return $declaringPharmacies >= $minimum;
    }

    /**
     * Network-wide totals over a period, in FCFA and as shares.
     *
     * Same rule as networkSummary(): resting on 1 to threshold − 1 officines,
     * the totals are withheld — a city of one declarant would otherwise print
     * that officine's exact invoice.
     *
     * Received, outstanding and recovery rate also fall, together, when what
     * is owed rests on too few creditors (summaryOwedWithheld()).
     *
     * @return array{withheld: bool, withheldReason: 'too-few'|'city-share'|null, required: int, invoiced: int|null, received: int|null, outstanding: int|null, recoveryRate: float|null, owedWithheld: bool, declaringPharmacies: int|null}
     */
    public function aggregatedAmounts(Period $from, Period $to, string|RegionScope|null $city = null, ?int $insurerId = null): array
    {
        $minimum = $this->settings->anonymityMinPharmacies();

        $row = $this->baseQuery($from, $to, $city, $insurerId)
            ->selectRaw('COUNT(DISTINCT pharmacy_id) as declaring_pharmacies')
            ->selectRaw('SUM(amount_invoiced) as invoiced')
            ->selectRaw('SUM(amount_received) as received')
            ->selectRaw(self::CREDITORS_COUNT)
            ->first();

        $declaring = (int) ($row->declaring_pharmacies ?? 0);
        $cells = is_string($city) || $declaring < $minimum ? [] : $this->partitionCells($from, $to, $insurerId);
        $reason = $this->summaryWithheldReason($declaring, $minimum, $city, $cells);

        if ($reason !== null) {
            return [
                'withheld' => true,
                'withheldReason' => $reason,
                'required' => $minimum,
                'invoiced' => null,
                'received' => null,
                'outstanding' => null,
                'recoveryRate' => null,
                'owedWithheld' => true,
                'declaringPharmacies' => null,
            ];
        }

        $invoiced = (int) ($row->invoiced ?? 0);
        $received = (int) ($row->received ?? 0);
        $owedWithheld = $this->summaryOwedWithheld((int) ($row->creditors ?? 0), $cells, $from, $to, $city, $insurerId, $minimum);

        return [
            'withheld' => false,
            'withheldReason' => null,
            'required' => $minimum,
            'invoiced' => $invoiced,
            'received' => $owedWithheld ? null : $received,
            'outstanding' => $owedWithheld ? null : max(0, $invoiced - $received),
            'recoveryRate' => $owedWithheld || $invoiced <= 0 ? null : round($received / $invoiced * 100, 1),
            'owedWithheld' => $owedWithheld,
            'declaringPharmacies' => $declaring,
        ];
    }

    /**
     * Why a period-level network summary must be withheld, null if it may show.
     *
     * « too-few » : 1 to threshold − 1 declarants. Zero is published — a
     * summary of nothing names no one, and reads « rien déclaré » rather than
     * « chiffres cachés ».
     *
     * « city-share », unfiltered by city only, in two ways (CityPartition):
     *
     * - the summary minus the published cities gives the unpublishable ones
     *   back (round 2) ;
     * - an insurer withheld by the city partition stays inside the summary.
     *   Summary − published insurer rows − that insurer's published city rows
     *   gives its hidden city cells back (round 3). The union of those cells,
     *   over every such insurer, in distinct officines, must not rest on 1 to
     *   threshold − 1 of them.
     *
     * The cells come from the caller, read once for every rule of the summary.
     * Under a region filter, the same two checks follow the region partition
     * rule (CityPartition::*In()).
     *
     * @param  array<int, array<string, array<int, bool>>>  $cells  partitionCells(), empty under a city filter
     * @return 'too-few'|'city-share'|null
     */
    protected function summaryWithheldReason(int $declaringPharmacies, int $minimum, string|RegionScope|null $city, array $cells): ?string
    {
        if ($declaringPharmacies === 0) {
            return null;
        }

        if (! $this->isSufficient($declaringPharmacies, $minimum)) {
            return 'too-few';
        }

        if (is_string($city)) {
            return null;
        }

        $perCity = [];

        foreach ($cells as $pharmacies) {
            foreach ($pharmacies as $key => $members) {
                $perCity[$key] = ($perCity[$key] ?? []) + $members;
            }
        }

        if (CityPartition::withholdsIn($city, array_map('count', $perCity), $minimum)) {
            return 'city-share';
        }

        $hidden = CityPartition::hiddenCellsOfCityShareInsurersIn($city, $cells, $minimum);

        return $hidden > 0 && $hidden < $minimum ? 'city-share' : null;
    }

    /**
     * Declaring pharmacies per insurer and city, for the city partition rule.
     *
     * Empty under a city filter: a single city is published whole, there is
     * nothing to subtract it from. Under a region filter, the whole network.
     *
     * @param  array<int, array<string, array<int, bool>>>  $cells
     * @return array<int, array<string, int>>
     */
    protected function declaringPerCity(array $cells): array
    {
        return array_map(fn (array $cities): array => array_map('count', $cities), $cells);
    }

    /**
     * The same, counting only the officines still owed money.
     *
     * @param  array<int, array<string, array<int, bool>>>  $cells
     * @return array<int, array<string, int>>
     */
    protected function creditorsPerCity(array $cells): array
    {
        return array_map(
            fn (array $cities): array => array_map(fn (array $pharmacies): int => count(array_filter($pharmacies)), $cities),
            $cells,
        );
    }

    /**
     * Whether an entry's owed amounts must be withheld.
     *
     * The insurer sheet names whom an insurer owes (InsurerCreditors). An
     * outstanding figure resting on one creditor would then be that named
     * officine's debt; and unfiltered (or by regions), the figure minus the
     * published cities would give the creditors of the small cities back.
     * Zero creditors publish: « nothing owed » names no one.
     *
     * @param  array<string, int>  $creditorsPerCity  whole network, empty under a city filter
     */
    protected function owedWithheld(int $creditors, array $creditorsPerCity, string|RegionScope|null $city, int $minimum): bool
    {
        return ($creditors > 0 && $creditors < $minimum)
            || CityPartition::withholdsIn($city, $creditorsPerCity, $minimum);
    }

    /**
     * owedWithheld() for a network summary, which sums every insurer.
     *
     * Two more ways back to a creditor, both closed here:
     *
     * - the summary minus the published cities (union of creditors per city);
     * - the summary minus the published insurer rows gives the owed amounts of
     *   the insurers whose rows do not publish them; minus their own published
     *   city rows, it gives their creditors in the cities that withhold them.
     *   The union of those creditors must not rest on 1 to threshold − 1.
     *
     * @param  array<int, array<string, array<int, bool>>>  $cells  whole network, empty under a city filter
     */
    protected function summaryOwedWithheld(int $creditors, array $cells, Period $from, Period $to, string|RegionScope|null $city, ?int $insurerId, int $minimum): bool
    {
        if ($creditors > 0 && $creditors < $minimum) {
            return true;
        }

        if (is_string($city)) {
            return $this->belowMinimum(count($this->hiddenCreditorsInCity($from, $to, $city, $insurerId, $minimum)), $minimum);
        }

        $union = [];

        foreach ($cells as $cities) {
            foreach ($cities as $key => $pharmacies) {
                $union[$key] = ($union[$key] ?? []) + array_filter($pharmacies);
            }
        }

        if (CityPartition::withholdsIn($city, array_map('count', $union), $minimum)) {
            return true;
        }

        $hidden = [];

        foreach ($cells as $cities) {
            $inScope = $city instanceof RegionScope
                ? array_filter($cities, fn (string|int $key): bool => $city->contains((string) $key), ARRAY_FILTER_USE_KEY)
                : $cities;

            $declaring = count(array_replace(...array_values($inScope ?: [[]])));
            $owing = count(array_filter(array_replace(...array_values($inScope ?: [[]]))));

            $published = $declaring >= $minimum
                && ! CityPartition::withholdsIn($city, array_map('count', $cities), $minimum)
                && ! $this->owedWithheld($owing, array_map(fn (array $pharmacies): int => count(array_filter($pharmacies)), $cities), $city, $minimum);

            if ($published) {
                continue;
            }

            foreach ($inScope as $key => $pharmacies) {
                $owed = array_filter($pharmacies);

                if ((string) $key === CityPartition::NO_CITY || count($pharmacies) < $minimum || $this->belowMinimum(count($owed), $minimum)) {
                    $hidden += $owed;
                }
            }
        }

        return $this->belowMinimum(count($hidden), $minimum);
    }

    /**
     * Under a city filter: the creditors of the insurers whose city row does
     * not publish what they owe. The summary of the city minus the published
     * rows gives exactly their debts.
     *
     * @return array<int, true>
     */
    protected function hiddenCreditorsInCity(Period $from, Period $to, string $city, ?int $insurerId, int $minimum): array
    {
        $rows = $this->baseQuery($from, $to, $city, $insurerId)
            ->select('declarations.insurer_id', 'declarations.pharmacy_id')
            ->selectRaw('MAX(CASE WHEN declarations.amount_invoiced > declarations.amount_received THEN 1 ELSE 0 END) as owed')
            ->groupBy('declarations.insurer_id', 'declarations.pharmacy_id')
            ->get()
            ->groupBy('insurer_id');

        $hidden = [];

        foreach ($rows as $pharmacies) {
            $owed = $pharmacies->where('owed', 1)->pluck('pharmacy_id')->map(fn (mixed $id): int => (int) $id);

            if ($pharmacies->count() >= $minimum && ! $this->belowMinimum($owed->count(), $minimum)) {
                continue;
            }

            foreach ($owed as $id) {
                $hidden[$id] = true;
            }
        }

        return $hidden;
    }

    protected function belowMinimum(int $count, int $minimum): bool
    {
        return $count > 0 && $count < $minimum;
    }

    /**
     * The declaring pharmacies of each insurer × city cell, by identifier.
     *
     * Identifiers, not counts: the hidden cells of several insurers overlap
     * (one officine declares to several), and only a union counts distinct
     * officines. Rows are distinct (insurer, pharmacy) pairs — a few thousand
     * at most. NULL and '' cities share the « sans ville » key.
     *
     * Each officine maps to whether it is still owed money in the cell: the
     * same rows feed the creditor rule, without a query of their own.
     *
     * @return array<int, array<string, array<int, bool>>>
     */
    protected function partitionCells(Period $from, Period $to, ?int $insurerId): array
    {
        $rows = $this->baseQuery($from, $to, null, $insurerId)
            ->join('pharmacies', 'pharmacies.id', '=', 'declarations.pharmacy_id')
            ->select('declarations.insurer_id', 'declarations.pharmacy_id')
            // La clé canonique, pas la chaîne : les compartiments doivent
            // suivre la collation, comme le filtre ville.
            ->selectRaw(CityPartition::canonicalCitySql('pharmacies').' as city')
            ->selectRaw('MAX(CASE WHEN declarations.amount_invoiced > declarations.amount_received THEN 1 ELSE 0 END) as owed')
            ->groupBy('declarations.insurer_id', 'declarations.pharmacy_id', 'pharmacies.city')
            ->get();

        $cells = [];

        foreach ($rows as $row) {
            $cells[(int) $row->insurer_id][CityPartition::key($row->city)][(int) $row->pharmacy_id] = (int) $row->owed === 1;
        }

        return $cells;
    }

    /**
     * Distinct declaring pharmacies per city, within further groups.
     *
     * @param  list<string>  $groups
     * @return Collection<int, \stdClass>
     */
    protected function perCity(Builder $query, array $groups = []): Collection
    {
        return $query
            ->join('pharmacies', 'pharmacies.id', '=', 'declarations.pharmacy_id')
            ->select($groups)
            // Groupé sur la clé canonique : même compartiment que partout
            // ailleurs, quelle que soit la graphie (CityPartition).
            ->selectRaw(CityPartition::canonicalCitySql('pharmacies').' as city')
            ->selectRaw('COUNT(DISTINCT declarations.pharmacy_id) as pharmacies')
            ->groupBy($groups)
            ->groupByRaw(CityPartition::canonicalCitySql('pharmacies'))
            ->get();
    }

    /**
     * Build the shared filter: period range, optional city.
     */
    /**
     * The reference line the twelve-month curve is read against.
     *
     * With a delay agreed insurer by insurer, no single number is the network's
     * threshold any more. The average of what the APhaSPB records is the
     * honest stand-in, and the screens label it as such.
     */
    public function averageStandardDelayDays(): int
    {
        $average = Insurer::query()->active()->avg('standard_delay_days');

        return $average === null
            ? Insurer::DEFAULT_STANDARD_DELAY_DAYS
            : (int) round((float) $average);
    }

    /**
     * Join the insurer so its own standard delay can be compared in SQL.
     */
    protected function withStandardDelay(Builder $query): Builder
    {
        return $query->join('insurers', 'insurers.id', '=', 'declarations.insurer_id');
    }

    /**
     * Le socle de tout agrégat réseau : la période, et la ville s'il y en a une.
     *
     * Délègue à DeclarationWindow, que partagent les requêtes parties d'une
     * autre table — voir InsurerPenaltyAggregates, qui filtre
     * `declaration_payments` sur la même fenêtre.
     */
    protected function baseQuery(Period $from, Period $to, string|RegionScope|null $city = null, ?int $insurerId = null): Builder
    {
        return $this->window->query($from, $to, $city, $insurerId);
    }
}
