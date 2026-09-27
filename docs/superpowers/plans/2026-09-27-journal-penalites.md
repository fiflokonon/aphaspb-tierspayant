# Journal des pénalités — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** déplier la pénalité de retard mois par mois — « courue » (mois calendaire de chaque tranche) et « par mois déclaré » —, la tracer sur `/dashboard` et `/admin/trends`, et lui donner une page Journal avec exports CSV/XLSX/PDF dans chaque espace.

**Architecture :** `PenaltyCalculator` expose ses tranches (`tranches()`), dont `accruedInDays()` devient la somme. Un accumulateur pur, `PenaltyTally`, range ces tranches par assureur et par mois, compte les officines contributrices et produit des DTO. Deux lecteurs l'alimentent — `PharmacyPenaltyLedger` (une officine) et `NetworkPenaltyLedger` (réseau, nombre de requêtes fixe) —, et `NetworkPenaltyJournal` applique le seuil d'anonymat. Écrans et exports ne consomment que les DTO.

**Tech Stack :** Laravel 13, PHP 8.4, Pest, Inertia 3, Vue 3 `<script setup>`, Tailwind 4, `@unovis/vue`, OpenSpout (`XlsxWriter`), `barryvdh/laravel-dompdf`.

**Spec :** `docs/superpowers/specs/2026-09-27-journal-penalites-design.md`

## Global Constraints

- Tout montant est un entier FCFA ; la pénalité ne passe jamais par un flottant (`intdiv($base * $rateBp, 10_000)`).
- Aucune colonne ni table de cache de pénalité, aucune tâche planifiée : calcul à la volée.
- Le calcul réseau travaille en numéros de jour (`DayNumber`), jamais en objets Carbon dans la boucle ; `$today` est hissé hors de toute boucle.
- La pénalité se compte depuis `invoice_deposited_on`. Les déclarations `rejected` et celles sans date de dépôt ne courent pas.
- Null ≠ zéro : « — » = pas de convention (ou pas encore / retenu), « 0 » = convention sans rien couru. Décision sur `hasPenaltyClause()` / colonnes de clause, jamais sur un retour de calcul.
- Réseau : séries par assureur limitées aux assureurs autorisés par `NetworkStatsService::perInsurer()`, réévaluées **mois par mois** ; ligne retenue **conservée et vidée**. Série totale sans filtre : tous les assureurs sous convention, **sans seuil** (décision du 27/09/2026).
- Le seuil par défaut vaut **5** (`SettingsRepository::DEFAULTS`) : tout décor réseau « autorisé » crée au moins 5 officines.
- Sources de lignes d'export officine et réseau : **deux classes distinctes**, rendus partagés (`CsvRenderer`, `XlsxWriter`).
- CSV : BOM `\xEF\xBB\xBF` posé par le contrôleur, `fputcsv($handle, $row, ';', '"', '')`. XLSX/PDF : `tempnam()` sans extension ajoutée + `deleteFileAfterSend()`.
- Front : `<script setup lang="ts">`, `<style scoped>` dans les pages, aucun littéral de couleur (jetons `var(--…)` / classes Tailwind du thème), `formatAmount()` jamais `formatFcfa()` nu.
- Toute prop `Vis*` est vérifiée contre `node_modules/@unovis/ts/components/<composant>/config.d.ts` (vue-tsc ne la contrôle pas).
- Noms de route : jamais un dernier segment réservé JS (`exports`, `default`…).
- Libellés UI et commentaires en français, dans le ton des fichiers voisins. PHPDoc plutôt que commentaires en ligne.
- Chaque tâche finit par `vendor/bin/pint --dirty --format agent` avant commit ; la dernière lance `composer ci:check`.
- Commits : message en français à l'impératif (`feat: …`, `test: …`), terminé par `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.

## Review Focus

1. **Mois futurs d'une période** (« Année civile », « Trimestre en cours ») : ils doivent rendre null (« — »), pas 0 — sinon la courbe plonge à zéro et se lit « les assureurs se sont mis à payer ». Test : Task 2, `future months are null, not zero`.
2. **Cumul après un mois retenu** : cumul(M) − cumul(M−1) = couru(M), donc un cumul publié après un mois retenu rend ce mois déductible. Le cumul est vidé à partir du premier mois retenu. Tests : Task 2 (`a withheld month blanks every later cumulative`) et Task 4.
3. **Tranche tombant le dernier jour d'un mois ou le 31 décembre** : elle appartient à ce mois, pas au suivant. Test : Task 1 (`DayNumber::monthKey`) et Task 2 (`a tranche on the last day of a month stays in that month`).
4. **Période à cheval sur deux années** (« 12 derniers mois », décembre → janvier) : la conversion ordinal → (année, mois) doit rendre décembre, pas « mois 0 » de l'année suivante. Test : Task 2 (`a period across the new year lists its months in order`). Et une facture **soldée pendant** la période doit garder les tranches courues avant son solde : Task 3 (`a month settled during the period still counts the tranches before its payment`).
5. **Assureur forgé dans l'URL** (`?insurer=` d'un assureur que l'officine ne déclare pas, ou sans convention) : filtre ignoré, jamais les chiffres d'un autre. Test : Task 7 (`a forged insurer id is ignored`).

---

## Fichiers

| Fichier | Rôle |
|---|---|
| `app/Services/Declarations/PenaltyCalculator.php` (mod.) | + `tranches()` ; `accruedInDays()` en devient la somme |
| `app/Support/DayNumber.php` (mod.) | + `monthKey(int $day): string` |
| `app/Data/PenaltyLedgerMonth.php` | un mois d'une série |
| `app/Data/PenaltyLedgerSeries.php` | une série (un assureur, ou le total) |
| `app/Data/PenaltyLedger.php` | le journal : séries, total, assureurs masqués |
| `app/Services/Declarations/PenaltyTally.php` | accumulateur tranches → mois, officines contributrices, construction des DTO |
| `app/Services/Declarations/PenaltyLedgerWindow.php` | filtre SQL « déclarations qui comptent pour le journal de la période » |
| `app/Services/Network/DeclarationWindow.php` (mod.) | extraction de `narrow()` (ville + assureur) |
| `app/Services/Pharmacy/PharmacyPenaltyLedger.php` | journal d'une officine |
| `app/Services/Network/NetworkPenaltyLedger.php` | décompte réseau brut (3 requêtes) |
| `app/Services/Network/NetworkPenaltyJournal.php` | seuil d'anonymat appliqué au décompte |
| `app/Http/Controllers/Pharmacy/PaymentJourneyController.php` (mod.) | prop deferred `penaltyTrend` |
| `app/Http/Controllers/Admin/NetworkTrendsController.php` (mod.) | prop deferred `penaltyTrend` |
| `app/Http/Controllers/Pharmacy/PharmacyPenaltyLedgerController.php` | page + téléchargement officine |
| `app/Http/Controllers/Admin/NetworkPenaltyLedgerController.php` | page + téléchargement réseau |
| `app/Services/Pharmacy/PharmacyPenaltyLedgerRows.php` | lignes d'export officine |
| `app/Services/Pharmacy/PharmacyPenaltyLedgerPdf.php` | PDF officine |
| `app/Services/Network/NetworkPenaltyLedgerRows.php` | lignes d'export réseau |
| `app/Services/Network/NetworkPenaltyLedgerPdf.php` | PDF réseau |
| `resources/views/exports/penalty-ledger-pharmacy.blade.php` | vue dompdf officine |
| `resources/views/exports/penalty-ledger-network.blade.php` | vue dompdf réseau |
| `routes/web.php` (mod.) | 4 routes |
| `app/Support/ConsoleNavigation.php` (mod.) | 2 entrées « Journal des pénalités » |
| `resources/js/lib/navIcons.ts` (mod.) | clé `receipt` |
| `resources/js/types/aphaspb.ts` (mod.) | types du journal |
| `resources/js/components/aphaspb/charts/PenaltyTrendChart.vue` | le graphique (ligne / barres) |
| `resources/js/components/aphaspb/PenaltyTrendCard.vue` | carte : bascule, filtre, deferred, export PNG |
| `resources/js/components/aphaspb/PenaltyLedgerTable.vue` | tableau-journal dépliable |
| `resources/js/pages/pharmacy/Dashboard.vue` (mod.) | + carte |
| `resources/js/pages/admin/Trends.vue` (mod.) | + carte, `penaltyTrend` dans `only` |
| `resources/js/pages/pharmacy/PenaltyLedger.vue` | page Journal officine |
| `resources/js/pages/admin/PenaltyLedger.vue` | page Journal réseau |
| `tests/Unit/Services/PenaltyCalculatorTest.php` (mod.) | tranches |
| `tests/Unit/Support/DayNumberTest.php` (mod.) | `monthKey` |
| `tests/Unit/Services/PenaltyTallyTest.php` | accumulateur |
| `tests/Feature/Pharmacy/PharmacyPenaltyLedgerTest.php` | service officine |
| `tests/Feature/Network/NetworkPenaltyJournalTest.php` | services réseau |
| `tests/Feature/Pharmacy/PenaltyLedgerPageTest.php` | HTTP + exports officine |
| `tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php` | HTTP + exports réseau |
| `tests/Feature/Pharmacy/PaymentJourneyTest.php`, `tests/Feature/Admin/NetworkTrendsTest.php`, `tests/Feature/Console/ConsoleShellTest.php` (mod.) | props deferred, navigation |

---

### Task 1 : les tranches de `PenaltyCalculator`, et le mois d'un numéro de jour

**Files :**
- Modify : `app/Services/Declarations/PenaltyCalculator.php` (méthode `accruedInDays`, ~l. 150-195)
- Modify : `app/Support/DayNumber.php`
- Test : `tests/Unit/Services/PenaltyCalculatorTest.php`, `tests/Unit/Support/DayNumberTest.php`

**Interfaces :**
- Produces :
  - `PenaltyCalculator::tranches(int $amountInvoiced, int $amountReceived, int $depositedDay, ?int $paidDay, int $triggerDays, int $rateBp, array $payments, ?int $today = null): array` — `list<array{0: int, 1: int}>` : `[numéro de jour de la tranche, montant]`, dans l'ordre chronologique.
  - `DayNumber::monthKey(int $day): string` — `'AAAA-MM'`.

- [ ] **Step 1 : tests qui échouent**

Ajouter à la fin de `tests/Unit/Services/PenaltyCalculatorTest.php` :

```php
test('the tranches of an unpaid invoice fall every thirty days from the trigger', function () {
    // Déposée le 31/03, déclenchement à 60 jours, 2 %, jamais réglée, on est le 19/09.
    $tranches = $this->calculator->tranches(
        amountInvoiced: 1_000_000,
        amountReceived: 0,
        depositedDay: DayNumber::fromDate('2026-03-31'),
        paidDay: null,
        triggerDays: 60,
        rateBp: 200,
        payments: [],
    );

    expect(array_map(fn (array $tranche): string => gmdate('Y-m-d', $tranche[0] * 86400), $tranches))
        ->toBe(['2026-05-30', '2026-06-29', '2026-07-29', '2026-08-28'])
        ->and(array_column($tranches, 1))->toBe([20_000, 20_000, 20_000, 20_000]);
});

test('the tranches always sum to the accrued penalty', function (int $received, ?string $paidOn, array $payments) {
    $arguments = [
        'amountInvoiced' => 1_000_000,
        'amountReceived' => $received,
        'depositedDay' => DayNumber::fromDate('2026-01-15'),
        'paidDay' => $paidOn === null ? null : DayNumber::fromDate($paidOn),
        'triggerDays' => 45,
        'rateBp' => 250,
        'payments' => array_map(
            fn (array $payment): array => [$payment[0], DayNumber::fromDate($payment[1])],
            $payments,
        ),
    ];

    expect(array_sum(array_column($this->calculator->tranches(...$arguments), 1)))
        ->toBe($this->calculator->accruedInDays(...$arguments));
})->with([
    'jamais réglée' => [0, null, []],
    'acompte puis rien' => [300_000, '2026-03-10', [[300_000, '2026-03-10']]],
    'soldée tard' => [1_000_000, '2026-06-20', [[400_000, '2026-03-01'], [600_000, '2026-06-20']]],
    'versement le jour d\'une tranche' => [1_000_000, '2026-04-30', [[1_000_000, '2026-04-30']]],
]);

test('a settled month stops producing tranches at its last payment', function () {
    $tranches = $this->calculator->tranches(
        amountInvoiced: 1_000_000,
        amountReceived: 1_000_000,
        depositedDay: DayNumber::fromDate('2026-03-31'),
        paidDay: DayNumber::fromDate('2026-07-01'),
        triggerDays: 60,
        rateBp: 200,
        payments: [[1_000_000, DayNumber::fromDate('2026-07-01')]],
    );

    expect($tranches)->toHaveCount(2);
});
```

Ajouter à `tests/Unit/Support/DayNumberTest.php` :

```php
test('a day number knows its calendar month, at both ends of the month and of the year', function (string $date, string $month) {
    expect(DayNumber::monthKey(DayNumber::fromDate($date)))->toBe($month);
})->with([
    ['2026-05-31', '2026-05'],
    ['2026-06-01', '2026-06'],
    ['2025-12-31', '2025-12'],
    ['2026-01-01', '2026-01'],
    ['2028-02-29', '2028-02'],
]);
```

- [ ] **Step 2 : les lancer, les voir échouer**

Run : `php artisan test --compact tests/Unit/Services/PenaltyCalculatorTest.php tests/Unit/Support/DayNumberTest.php`
Attendu : FAIL — `Call to undefined method App\Services\Declarations\PenaltyCalculator::tranches()` et `DayNumber::monthKey()`.

- [ ] **Step 3 : implémenter**

Dans `PenaltyCalculator`, remplacer le corps de `accruedInDays()` et ajouter `tranches()` juste après. Le docblock actuel d'`accruedInDays()` (paires indexées, `$today` hissé) passe sur `tranches()` ; `accruedInDays()` garde une phrase qui renvoie à `tranches()`.

```php
    /**
     * La somme des tranches : ce que le reste du code appelle « la pénalité ».
     *
     * Un seul algorithme pour le total et pour le journal mensuel : deux
     * boucles finiraient par diverger, et l'écart ne se verrait que le jour où
     * le journal et le tableau de bord afficheraient deux chiffres.
     *
     * @param  list<array{0: int, 1: int}>  $payments
     */
    public function accruedInDays(
        int $amountInvoiced,
        int $amountReceived,
        int $depositedDay,
        ?int $paidDay,
        int $triggerDays,
        int $rateBp,
        array $payments,
        ?int $today = null,
    ): int {
        $total = 0;

        foreach ($this->tranches($amountInvoiced, $amountReceived, $depositedDay, $paidDay, $triggerDays, $rateBp, $payments, $today) as [, $amount]) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * Chaque tranche facturée, datée : le cœur, sans aucun objet date.
     *
     * (reprendre ici, tel quel, le docblock actuel d'accruedInDays() sur les
     * paires indexées et le `$today` hissé)
     *
     * @param  list<array{0: int, 1: int}>  $payments
     * @return list<array{0: int, 1: int}> numéro de jour de la tranche, montant
     */
    public function tranches(
        int $amountInvoiced,
        int $amountReceived,
        int $depositedDay,
        ?int $paidDay,
        int $triggerDays,
        int $rateBp,
        array $payments,
        ?int $today = null,
    ): array {
        // Un mois entièrement soldé cesse de courir au dernier versement, et ce
        // qu'il avait accumulé lui reste acquis. Un mois qui doit encore quelque
        // chose court jusqu'à aujourd'hui.
        $end = $amountReceived < $amountInvoiced
            ? ($today ?? DayNumber::today())
            : $paidDay;

        if ($end === null) {
            return [];
        }

        $tranches = [];
        $tranche = $depositedDay + $triggerDays;

        while ($tranche <= $end) {
            $base = $amountInvoiced;

            foreach ($payments as [$amount, $day]) {
                // Comparaison inclusive : de l'argent viré le jour même allège
                // cette tranche-là plutôt que la suivante.
                if ($day <= $tranche) {
                    $base -= $amount;
                }
            }

            // (garder ici le commentaire actuel sur le break : optimisation, pas règle)
            if ($base <= 0) {
                break;
            }

            $tranches[] = [$tranche, intdiv($base * $rateBp, 10_000)];
            $tranche += Insurer::PENALTY_TRANCHE_DAYS;
        }

        return $tranches;
    }
```

Les deux « (reprendre / garder …) » ci-dessus désignent des commentaires **existants** du fichier, à déplacer mot pour mot, pas à réécrire.

Dans `DayNumber`, après `today()` :

```php
    /**
     * Le mois calendaire d'un numéro de jour, au format `AAAA-MM`.
     *
     * gmdate() et non Carbon : appelé une fois par tranche dans la boucle du
     * journal réseau, pour la même raison que tout ce fichier existe. En UTC,
     * comme fromDate() : le numéro de jour a été compté depuis minuit UTC.
     */
    public static function monthKey(int $day): string
    {
        return gmdate('Y-m', $day * self::SECONDS_PER_DAY);
    }
```

- [ ] **Step 4 : les tests passent, anciens compris**

Run : `php artisan test --compact tests/Unit/Services/PenaltyCalculatorTest.php tests/Unit/Support/DayNumberTest.php tests/Feature/Network/InsurerPenaltyAggregatesTest.php`
Attendu : PASS (la suite existante prouve que le total n'a pas bougé).

- [ ] **Step 5 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Declarations/PenaltyCalculator.php app/Support/DayNumber.php tests/Unit/Services/PenaltyCalculatorTest.php tests/Unit/Support/DayNumberTest.php
git commit -m "feat: exposer les tranches datées de la pénalité

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2 : les DTO du journal et l'accumulateur `PenaltyTally`

**Files :**
- Create : `app/Data/PenaltyLedgerMonth.php`, `app/Data/PenaltyLedgerSeries.php`, `app/Data/PenaltyLedger.php`, `app/Services/Declarations/PenaltyTally.php`
- Test : `tests/Unit/Services/PenaltyTallyTest.php`

**Interfaces :**
- Consumes : `PenaltyCalculator::tranches()`, `DayNumber::monthKey()` (Task 1), `App\Data\Period`, `App\Support\MonthLabel::short()`.
- Produces :
  - `PenaltyLedgerMonth(string $month, string $label, bool $current, bool $future, ?int $accrued, ?int $accruedCumulative, ?int $declared, bool $withheld = false)` + `toArray(): array{month, label, current, future, accrued, accruedCumulative, declared, withheld}`.
  - `PenaltyLedgerSeries(?int $insurerId, string $name, list<PenaltyLedgerMonth> $months)` + `toArray()` + `month(string $key): ?PenaltyLedgerMonth`.
  - `PenaltyLedger(list<PenaltyLedgerSeries> $insurers, PenaltyLedgerSeries $total, int $maskedInsurers = 0)` + `toArray(): array{insurers, total, maskedInsurers}`.
  - `new PenaltyTally(PenaltyCalculator $penalties, Period $from, Period $to)`
  - `PenaltyTally::add(int $insurerId, int $pharmacyId, int $periodYear, int $periodMonth, int $amountInvoiced, int $amountReceived, int $depositedDay, ?int $paidDay, int $triggerDays, int $rateBp, array $payments): void`
  - `PenaltyTally::ledger(array<int, string> $names, ?Closure $withheld = null, int $maskedInsurers = 0): PenaltyLedger` — `$withheld(?int $insurerId, int $accruedPharmacies, int $declaredPharmacies): bool`, `$insurerId` null pour la série totale.

- [ ] **Step 1 : tests qui échouent**

`tests/Unit/Services/PenaltyTallyTest.php` :

```php
<?php

use App\Data\Period;
use App\Services\Declarations\PenaltyCalculator;
use App\Services\Declarations\PenaltyTally;
use App\Support\DayNumber;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

/**
 * Une facture de 1 000 000 déposée le $depositedOn, jamais réglée, sous une
 * clause 60 jours / 2 % : une tranche de 20 000 tous les trente jours.
 */
function tallyUnpaid(PenaltyTally $tally, int $insurerId, int $pharmacyId, int $month, string $depositedOn): void
{
    $tally->add(
        insurerId: $insurerId,
        pharmacyId: $pharmacyId,
        periodYear: 2026,
        periodMonth: $month,
        amountInvoiced: 1_000_000,
        amountReceived: 0,
        depositedDay: DayNumber::fromDate($depositedOn),
        paidDay: null,
        triggerDays: 60,
        rateBp: 200,
        payments: [],
    );
}

test('accrued goes to the month each tranche falls in, declared to the invoice month', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 3), new Period(2026, 9));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');

    $series = $tally->ledger([1 => 'NSIA'])->insurers[0];

    expect($series->month('2026-03')->declared)->toBe(80_000)
        ->and($series->month('2026-03')->accrued)->toBe(0)
        ->and($series->month('2026-05')->accrued)->toBe(20_000)
        ->and($series->month('2026-08')->accrued)->toBe(20_000)
        ->and($series->month('2026-08')->accruedCumulative)->toBe(80_000)
        ->and($series->month('2026-06')->declared)->toBe(0)
        ->and($series->month('2026-09')->current)->toBeTrue();
});

test('a tranche outside the period is ignored, but the invoice still counts for its month', function () {
    // Période mai–juin seulement : les tranches de juillet et août sortent.
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 6));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');

    $series = $tally->ledger([1 => 'NSIA'])->insurers[0];

    expect(array_map(fn ($month) => $month->month, $series->months))->toBe(['2026-05', '2026-06'])
        ->and($series->month('2026-06')->accruedCumulative)->toBe(40_000)
        // Mars n'est pas dans la période : la vue « mois déclaré » ne le voit pas.
        ->and(array_sum(array_map(fn ($month) => $month->declared, $series->months)))->toBe(0);
});

test('a tranche on the last day of a month stays in that month', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 6));
    // Déposée le 01/04 : première tranche le 31/05.
    tallyUnpaid($tally, 1, 10, 3, '2026-04-01');

    $series = $tally->ledger([1 => 'NSIA'])->insurers[0];

    expect($series->month('2026-05')->accrued)->toBe(20_000)
        ->and($series->month('2026-06')->accrued)->toBe(20_000);
});

test('a period across the new year lists its months in order', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2025, 11), new Period(2026, 2));

    expect(array_map(fn ($month) => $month->month, $tally->ledger([])->total->months))
        ->toBe(['2025-11', '2025-12', '2026-01', '2026-02']);
});

test('future months are null, not zero', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 1), new Period(2026, 12));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');

    $october = $tally->ledger([1 => 'NSIA'])->insurers[0]->month('2026-10');

    expect($october->future)->toBeTrue()
        ->and($october->accrued)->toBeNull()
        ->and($october->accruedCumulative)->toBeNull()
        ->and($october->declared)->toBeNull();
});

test('an insurer named without any declaration reads zeros, not an absence', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 6));

    $series = $tally->ledger([7 => 'Sans rien couru'])->insurers[0];

    expect($series->name)->toBe('Sans rien couru')
        ->and($series->month('2026-05')->accrued)->toBe(0);
});

test('the total sums every insurer added, named or not', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 5));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');
    tallyUnpaid($tally, 2, 11, 3, '2026-03-31');

    $ledger = $tally->ledger([1 => 'NSIA']);

    expect($ledger->insurers)->toHaveCount(1)
        ->and($ledger->total->month('2026-05')->accrued)->toBe(40_000);
});

test('the withholding callback sees how many officines each month rests on', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 3), new Period(2026, 5));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');
    tallyUnpaid($tally, 1, 11, 3, '2026-03-31');

    $seen = [];
    $tally->ledger([1 => 'NSIA'], function (?int $insurerId, int $accrued, int $declared) use (&$seen): bool {
        $seen[] = [$insurerId, $accrued, $declared];

        return false;
    });

    // mars (déclaré par deux officines), avril (rien), mai (deux tranches) — pour l'assureur puis le total.
    expect($seen)->toBe([
        [1, 0, 2], [1, 0, 0], [1, 2, 0],
        [null, 0, 2], [null, 0, 0], [null, 2, 0],
    ]);
});

test('a withheld month blanks every later cumulative', function () {
    $tally = new PenaltyTally(new PenaltyCalculator, new Period(2026, 5), new Period(2026, 7));
    tallyUnpaid($tally, 1, 10, 3, '2026-03-31');

    $series = $tally->ledger([1 => 'NSIA'], function (?int $insurerId, int $accrued, int $declared) use (&$calls): bool {
        $calls = ($calls ?? 0) + 1;

        return $calls === 2; // juin, deuxième mois de la série de l'assureur
    })->insurers[0];

    expect($series->month('2026-05')->accruedCumulative)->toBe(20_000)
        ->and($series->month('2026-06')->withheld)->toBeTrue()
        ->and($series->month('2026-06')->accrued)->toBeNull()
        ->and($series->month('2026-07')->accrued)->toBe(20_000)
        ->and($series->month('2026-07')->accruedCumulative)->toBeNull();
});
```

- [ ] **Step 2 : les voir échouer**

Run : `php artisan test --compact tests/Unit/Services/PenaltyTallyTest.php`
Attendu : FAIL — `Class "App\Services\Declarations\PenaltyTally" not found`.

- [ ] **Step 3 : implémenter**

`app/Data/PenaltyLedgerMonth.php` :

```php
<?php

namespace App\Data;

/**
 * Un mois du journal des pénalités, pour un assureur ou pour le total.
 *
 * Null ne dit jamais zéro : il dit « pas encore » (mois futur) ou « retenu »
 * (sous le seuil d'anonymat), et `future` / `withheld` disent lequel.
 */
readonly class PenaltyLedgerMonth
{
    public function __construct(
        /** `AAAA-MM` */
        public string $month,
        /** « Août 26 » */
        public string $label,
        public bool $current,
        public bool $future,
        /** Ce qui a couru pendant ce mois calendaire. */
        public ?int $accrued,
        /** Le couru cumulé depuis le début de la période. */
        public ?int $accruedCumulative,
        /** La pénalité, à ce jour, des factures de ce mois déclaré. */
        public ?int $declared,
        public bool $withheld = false,
    ) {
        //
    }

    /**
     * @return array{month: string, label: string, current: bool, future: bool, accrued: int|null, accruedCumulative: int|null, declared: int|null, withheld: bool}
     */
    public function toArray(): array
    {
        return [
            'month' => $this->month,
            'label' => $this->label,
            'current' => $this->current,
            'future' => $this->future,
            'accrued' => $this->accrued,
            'accruedCumulative' => $this->accruedCumulative,
            'declared' => $this->declared,
            'withheld' => $this->withheld,
        ];
    }
}
```

`app/Data/PenaltyLedgerSeries.php` :

```php
<?php

namespace App\Data;

/**
 * Les mois d'un assureur — ou du total quand `insurerId` est null.
 */
readonly class PenaltyLedgerSeries
{
    public function __construct(
        public ?int $insurerId,
        public string $name,
        /** @var list<PenaltyLedgerMonth> */
        public array $months,
    ) {
        //
    }

    public function month(string $key): ?PenaltyLedgerMonth
    {
        foreach ($this->months as $month) {
            if ($month->month === $key) {
                return $month;
            }
        }

        return null;
    }

    /**
     * @return array{insurerId: int|null, name: string, months: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'insurerId' => $this->insurerId,
            'name' => $this->name,
            'months' => array_map(fn (PenaltyLedgerMonth $month): array => $month->toArray(), $this->months),
        ];
    }
}
```

`app/Data/PenaltyLedger.php` :

```php
<?php

namespace App\Data;

/**
 * Le journal des pénalités d'une période : une série par assureur publié, et
 * le total.
 *
 * Le total peut sommer plus que les séries publiées — côté réseau, il couvre
 * aussi les assureurs masqués (décision du 27/09/2026) — : ne jamais le
 * recalculer en additionnant les séries.
 */
readonly class PenaltyLedger
{
    public function __construct(
        /** @var list<PenaltyLedgerSeries> */
        public array $insurers,
        public PenaltyLedgerSeries $total,
        public int $maskedInsurers = 0,
    ) {
        //
    }

    /**
     * @return array{insurers: list<array<string, mixed>>, total: array<string, mixed>, maskedInsurers: int}
     */
    public function toArray(): array
    {
        return [
            'insurers' => array_map(fn (PenaltyLedgerSeries $series): array => $series->toArray(), $this->insurers),
            'total' => $this->total->toArray(),
            'maskedInsurers' => $this->maskedInsurers,
        ];
    }
}
```

`app/Services/Declarations/PenaltyTally.php` :

```php
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
```

- [ ] **Step 4 : les tests passent**

Run : `php artisan test --compact tests/Unit/Services/PenaltyTallyTest.php`
Attendu : PASS.

- [ ] **Step 5 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/PenaltyLedger*.php app/Services/Declarations/PenaltyTally.php tests/Unit/Services/PenaltyTallyTest.php
git commit -m "feat: ranger les tranches de pénalité mois par mois

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3 : la fenêtre du journal et le journal d'une officine

**Files :**
- Create : `app/Services/Declarations/PenaltyLedgerWindow.php`, `app/Services/Pharmacy/PharmacyPenaltyLedger.php`
- Test : `tests/Feature/Pharmacy/PharmacyPenaltyLedgerTest.php`

**Interfaces :**
- Consumes : `PenaltyTally` (Task 2).
- Produces :
  - `PenaltyLedgerWindow::apply(Illuminate\Database\Query\Builder $query, Period $from, Period $to): Builder` — pose sur une requête qui porte `declarations` : non rejetée, déposée, et (mois déclaré dans la période **ou** déposée avant la fin de la période et pas soldée avant son début).
  - `PharmacyPenaltyLedger::for(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId = null): PenaltyLedger`
  - `PharmacyPenaltyLedger::clauseInsurers(Pharmacy $pharmacy): Illuminate\Support\Collection<int, object{id: int, name: string, penalty_trigger_days: int, penalty_rate_bp: int}>` — assureurs sous convention que l'officine a déjà déclarés, par nom.

- [ ] **Step 1 : tests qui échouent**

`tests/Feature/Pharmacy/PharmacyPenaltyLedgerTest.php` :

```php
<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Pharmacy\PharmacyPenaltyLedger;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->ledger = app(PharmacyPenaltyLedger::class);
    $this->pharmacy = Pharmacy::factory()->create();
});

/**
 * Une facture de 1 000 000 de l'officine, jamais réglée.
 *
 * @param  array<string, mixed>  $attributes
 */
function ledgerUnpaid(Pharmacy $pharmacy, Insurer $insurer, int $month, string $depositedOn, array $attributes = []): Declaration
{
    return Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => $month,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => $depositedOn,
        'paid_on' => null,
        'delay_days' => null,
        ...$attributes,
    ]);
}

test('a March invoice still unpaid accrues in May to August and is charged to March', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);
    ledgerUnpaid($this->pharmacy, $insurer, 3, '2026-03-31');

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9));
    $series = $ledger->insurers[0];

    expect($series->name)->toBe('NSIA')
        ->and($series->month('2026-03')->declared)->toBe(80_000)
        ->and($series->month('2026-05')->accrued)->toBe(20_000)
        ->and($series->month('2026-08')->accruedCumulative)->toBe(80_000)
        ->and($ledger->total->month('2026-08')->accruedCumulative)->toBe(80_000);
});

test('an invoice declared before the period still accrues inside it', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    ledgerUnpaid($this->pharmacy, $insurer, 3, '2026-03-31');

    $series = $this->ledger->for($this->pharmacy, new Period(2026, 6), new Period(2026, 9))->insurers[0];

    expect($series->month('2026-06')->accrued)->toBe(20_000)
        ->and($series->month('2026-08')->accruedCumulative)->toBe(60_000);
});

test('a month settled during the period still counts the tranches before its payment', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    // Déposée le 01/04 : tranches le 31/05 et le 30/06, soldée le 01/07.
    // Déclarée en mars, hors période : seule la branche « pas soldée avant le
    // début de la période » (paid_on >= 01/06) la fait entrer.
    Declaration::factory()
        ->instalments([['amount' => 1_000_000, 'paid_on' => '2026-07-01']])
        ->create([
            'pharmacy_id' => $this->pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 3,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-04-01',
        ]);

    $series = $this->ledger->for($this->pharmacy, new Period(2026, 6), new Period(2026, 7))->insurers[0];

    expect($series->month('2026-06')->accrued)->toBe(20_000)
        ->and($series->month('2026-07')->accrued)->toBe(0);
});

test('an insurer under a clause with nothing accrued reads zero, one without a clause is absent', function () {
    $clause = Insurer::factory()->withPenalty(triggerDays: 90, ratePercent: 2.0)->create(['name' => 'Sous convention']);
    $none = Insurer::factory()->create(['name' => 'Sans convention']);
    ledgerUnpaid($this->pharmacy, $clause, 9, '2026-09-10');
    ledgerUnpaid($this->pharmacy, $none, 3, '2026-03-31');

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9));

    expect(array_map(fn ($series) => $series->name, $ledger->insurers))->toBe(['Sous convention'])
        ->and($ledger->insurers[0]->month('2026-09')->declared)->toBe(0)
        ->and($ledger->total->month('2026-05')->accrued)->toBe(0);
});

test('a rejected invoice does not accrue', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    ledgerUnpaid($this->pharmacy, $insurer, 3, '2026-03-31', ['status' => DeclarationStatus::Rejected]);

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9));

    expect($ledger->total->month('2026-05')->accrued)->toBe(0)
        ->and($ledger->total->month('2026-03')->declared)->toBe(0);
});

test('another officine never enters the ledger', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    ledgerUnpaid($this->pharmacy, $insurer, 3, '2026-03-31');
    ledgerUnpaid(Pharmacy::factory()->create(), $insurer, 3, '2026-03-31');

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9));

    expect($ledger->total->month('2026-05')->accrued)->toBe(20_000);
});

test('the insurer filter narrows series and total alike', function () {
    $kept = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    $other = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    ledgerUnpaid($this->pharmacy, $kept, 3, '2026-03-31');
    ledgerUnpaid($this->pharmacy, $other, 3, '2026-03-31');

    $ledger = $this->ledger->for($this->pharmacy, new Period(2026, 3), new Period(2026, 9), $kept->id);

    expect($ledger->insurers)->toHaveCount(1)
        ->and($ledger->insurers[0]->insurerId)->toBe($kept->id)
        ->and($ledger->total->month('2026-05')->accrued)->toBe(20_000);
});
```

- [ ] **Step 2 : les voir échouer**

Run : `php artisan test --compact tests/Feature/Pharmacy/PharmacyPenaltyLedgerTest.php`
Attendu : FAIL — classe `PharmacyPenaltyLedger` introuvable.

- [ ] **Step 3 : implémenter**

`app/Services/Declarations/PenaltyLedgerWindow.php` :

```php
<?php

namespace App\Services\Declarations;

use App\Data\Period;
use App\Enums\DeclarationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Quelles déclarations un journal des pénalités doit lire.
 *
 * Pas le filtre habituel sur le mois déclaré : une facture de janvier encore
 * impayée court encore en septembre, et la vue « couru » doit la voir. On lit
 * donc, en plus des mois de la période, toute facture déposée avant sa fin qui
 * n'était pas soldée avant son début. Ce qui tombe hors période est calculé
 * puis écarté par PenaltyTally.
 *
 * Les bornes se comparent en `<` au premier jour du mois suivant et en `>=` au
 * premier jour : les dates remontent avec une heure (`2026-09-30 00:00:00`),
 * et `<= '2026-09-30'` exclurait le dernier jour en comparaison de chaînes.
 */
class PenaltyLedgerWindow
{
    public function apply(Builder $query, Period $from, Period $to): Builder
    {
        $firstDay = sprintf('%04d-%02d-01', $from->year, $from->month);
        $dayAfter = CarbonImmutable::create($to->year, $to->month, 1)->addMonth()->format('Y-m-d');

        return $query
            ->where('declarations.status', '!=', DeclarationStatus::Rejected->value)
            ->whereNotNull('declarations.invoice_deposited_on')
            ->where(fn (Builder $scope) => $scope
                ->whereRaw(
                    '(declarations.period_year * 12 + declarations.period_month) BETWEEN ? AND ?',
                    [$from->toOrdinal(), $to->toOrdinal()],
                )
                ->orWhere(fn (Builder $accruing) => $accruing
                    ->where('declarations.invoice_deposited_on', '<', $dayAfter)
                    ->where(fn (Builder $open) => $open
                        ->whereColumn('declarations.amount_received', '<', 'declarations.amount_invoiced')
                        ->orWhere('declarations.paid_on', '>=', $firstDay))));
    }
}
```

`app/Services/Pharmacy/PharmacyPenaltyLedger.php` :

```php
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
            ->map(fn (Collection $rows): array => $rows
                ->map(fn (object $row): array => [(int) $row->amount, DayNumber::fromDate((string) $row->paid_on)])
                ->values()
                ->all());

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

        return $tally->ledger($clauses->mapWithKeys(fn (object $row): array => [(int) $row->id => (string) $row->name])->all());
    }

    /**
     * Les assureurs sous convention que l'officine a déjà déclarés, par nom.
     *
     * Sert aussi la liste du filtre : un assureur sans convention n'a rien à
     * tracer, et le proposer ferait lire une courbe vide comme « aucun retard ».
     *
     * @return Collection<int, object>
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
```

- [ ] **Step 4 : les tests passent**

Run : `php artisan test --compact tests/Feature/Pharmacy/PharmacyPenaltyLedgerTest.php`
Attendu : PASS. Si `a month settled during the period…` échoue, vérifier d'abord le format de `paid_on` en base (`DB::table('declarations')->value('paid_on')`) avant de toucher au filtre.

- [ ] **Step 5 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Declarations/PenaltyLedgerWindow.php app/Services/Pharmacy/PharmacyPenaltyLedger.php tests/Feature/Pharmacy/PharmacyPenaltyLedgerTest.php
git commit -m "feat: tenir le journal des pénalités d'une officine

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4 : le journal réseau et son seuil d'anonymat

**Files :**
- Modify : `app/Services/Network/DeclarationWindow.php`
- Create : `app/Services/Network/NetworkPenaltyLedger.php`, `app/Services/Network/NetworkPenaltyJournal.php`
- Test : `tests/Feature/Network/NetworkPenaltyJournalTest.php`

**Interfaces :**
- Consumes : `PenaltyTally`, `PenaltyLedgerWindow` (Tasks 2-3), `NetworkStatsService::perInsurer()`, `SettingsRepository::anonymityMinPharmacies()`.
- Produces :
  - `DeclarationWindow::narrow(Builder $query, ?string $city = null, ?int $insurerId = null): Builder` (public ; `apply()` l'appelle).
  - `NetworkPenaltyLedger::tally(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): PenaltyTally` — tous les assureurs sous convention (ou le seul demandé), **trois requêtes**.
  - `NetworkPenaltyJournal::for(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): PenaltyLedger`
  - `NetworkPenaltyJournal::clauseInsurers(): Collection<int, object{id, name}>` — assureurs sous convention ayant au moins une déclaration, par nom (liste du filtre).

- [ ] **Step 1 : tests qui échouent**

`tests/Feature/Network/NetworkPenaltyJournalTest.php` :

```php
<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkPenaltyJournal;
use App\Services\Network\NetworkPenaltyLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->journal = app(NetworkPenaltyJournal::class);
    $this->bounds = [new Period(2026, 3), new Period(2026, 9)];
});

/**
 * $count officines neuves, chacune une facture de 1 000 000 jamais réglée.
 *
 * @param  array<string, mixed>  $attributes
 */
function networkUnpaid(Insurer $insurer, int $count, int $month, string $depositedOn, array $attributes = [], array $pharmacy = []): void
{
    Pharmacy::factory()->count($count)->create($pharmacy)->each(fn (Pharmacy $pharmacy) => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => $month,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => $depositedOn,
        'paid_on' => null,
        'delay_days' => null,
        ...$attributes,
    ]));
}

test('an authorised insurer gets its monthly series', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);
    networkUnpaid($insurer, 5, 3, '2026-03-31');

    $series = $this->journal->for(...$this->bounds)->insurers[0];

    expect($series->name)->toBe('NSIA')
        ->and($series->month('2026-05')->accrued)->toBe(100_000)
        ->and($series->month('2026-03')->declared)->toBe(400_000);
});

test('a month resting on too few officines is withheld, with every later cumulative', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31');
    // Juin : une seule officine déclarante. Sa facture court en août (30/06 + 60).
    networkUnpaid($insurer, 1, 6, '2026-06-30');

    $series = $this->journal->for(...$this->bounds)->insurers[0];

    // Décor discriminant : sans rétention, juin vaudrait 100 000 couru et 0 déclaré,
    // et le cumul de juillet 300 000.
    expect($series->month('2026-06')->withheld)->toBeTrue()
        ->and($series->month('2026-06')->accrued)->toBeNull()
        ->and($series->month('2026-06')->declared)->toBeNull()
        ->and($series->month('2026-05')->accruedCumulative)->toBe(100_000)
        ->and($series->month('2026-07')->accrued)->toBe(100_000)
        ->and($series->month('2026-07')->accruedCumulative)->toBeNull()
        ->and($series->month('2026-08')->accrued)->toBe(120_000);
});

test('a masked insurer has no series but still counts in the unfiltered total', function () {
    $shown = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    $masked = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($shown, 5, 3, '2026-03-31');
    networkUnpaid($masked, 2, 3, '2026-03-31');

    $ledger = $this->journal->for(...$this->bounds);

    // Décision du 27/09/2026 : le total couvre les masqués. Ce test la verrouille.
    expect(array_map(fn ($series) => $series->insurerId, $ledger->insurers))->toBe([$shown->id])
        ->and($ledger->total->month('2026-05')->accrued)->toBe(140_000)
        ->and($ledger->maskedInsurers)->toBe(1);
});

test('filtered on a masked insurer, the total is withheld too', function () {
    $masked = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($masked, 2, 3, '2026-03-31');

    $ledger = $this->journal->for(...$this->bounds, insurerId: $masked->id);

    expect($ledger->insurers)->toBe([])
        ->and(collect($ledger->total->months)->reject(fn ($month) => $month->future)->every(fn ($month) => $month->withheld))->toBeTrue();
});

test('filtered on an authorised insurer, the total follows its month-by-month withholding', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31');
    networkUnpaid($insurer, 1, 6, '2026-06-30');

    $total = $this->journal->for(...$this->bounds, insurerId: $insurer->id)->total;

    expect($total->month('2026-05')->accrued)->toBe(100_000)
        ->and($total->month('2026-06')->withheld)->toBeTrue();
});

test('an insurer without a clause appears nowhere', function () {
    $none = Insurer::factory()->create();
    networkUnpaid($none, 5, 3, '2026-03-31');

    $ledger = $this->journal->for(...$this->bounds);

    expect($ledger->insurers)->toBe([])
        ->and($ledger->total->month('2026-05')->accrued)->toBe(0);
});

test('the city filter narrows the ledger like every other network read', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 5, 3, '2026-03-31', pharmacy: ['city' => 'Cotonou']);
    networkUnpaid($insurer, 5, 3, '2026-03-31', pharmacy: ['city' => 'Parakou']);

    $total = $this->journal->for(...$this->bounds, city: 'Cotonou')->total;

    expect($total->month('2026-05')->accrued)->toBe(100_000);
});

test('the raw tally reads the same number of queries however many declarations there are', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkUnpaid($insurer, 1, 3, '2026-03-31');

    DB::enableQueryLog();
    app(NetworkPenaltyLedger::class)->tally(...$this->bounds);
    $withOne = count(DB::getQueryLog());

    networkUnpaid($insurer, 12, 4, '2026-04-30', ['amount_received' => 0]);
    DB::flushQueryLog();
    app(NetworkPenaltyLedger::class)->tally(...$this->bounds);

    expect(count(DB::getQueryLog()))->toBe($withOne)
        ->and($withOne)->toBe(3);
});
```

- [ ] **Step 2 : les voir échouer**

Run : `php artisan test --compact tests/Feature/Network/NetworkPenaltyJournalTest.php`
Attendu : FAIL — classe `NetworkPenaltyJournal` introuvable.

- [ ] **Step 3 : implémenter**

`DeclarationWindow` — extraire la fin d'`apply()` :

```php
    public function apply(Builder $query, Period $from, Period $to, ?string $city = null, ?int $insurerId = null): Builder
    {
        return $this->narrow(
            $query->whereRaw(
                '(declarations.period_year * 12 + declarations.period_month) BETWEEN ? AND ?',
                [$from->toOrdinal(), $to->toOrdinal()],
            ),
            $city,
            $insurerId,
        );
    }

    /**
     * La ville et l'assureur seuls, sans la période.
     *
     * Pour le journal des pénalités, dont la fenêtre n'est pas le mois déclaré
     * (PenaltyLedgerWindow) mais qui doit filtrer la ville exactement comme
     * tous les autres agrégats réseau.
     */
    public function narrow(Builder $query, ?string $city = null, ?int $insurerId = null): Builder
    {
        return $query
            ->when($city, fn (Builder $inner, string $filtered) => $inner->whereExists(
                fn (Builder $sub) => $sub->from('pharmacies')
                    ->whereColumn('pharmacies.id', 'declarations.pharmacy_id')
                    ->where('pharmacies.city', $filtered),
            ))
            ->when($insurerId, fn (Builder $inner, int $filtered) => $inner->where(
                'declarations.insurer_id',
                $filtered,
            ));
    }
```

`app/Services/Network/NetworkPenaltyLedger.php` :

```php
<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Services\Declarations\PenaltyCalculator;
use App\Services\Declarations\PenaltyLedgerWindow;
use App\Services\Declarations\PenaltyTally;
use App\Support\DayNumber;
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
            ->select(
                'declarations.id', 'declarations.insurer_id', 'declarations.pharmacy_id',
                'declarations.period_year', 'declarations.period_month',
                'declarations.amount_invoiced', 'declarations.amount_received',
                'declarations.invoice_deposited_on', 'declarations.paid_on',
            )
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
    protected function scope(\Illuminate\Database\Query\Builder $query, array $insurerIds, Period $from, Period $to, ?string $city): \Illuminate\Database\Query\Builder
    {
        return $this->window->narrow(
            $this->ledgerWindow->apply($query, $from, $to)->whereIn('declarations.insurer_id', $insurerIds),
            $city,
        );
    }
}
```

(Importer `Illuminate\Database\Query\Builder` en tête plutôt que les noms qualifiés, comme les fichiers voisins.)

`app/Services/Network/NetworkPenaltyJournal.php` :

```php
<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\PenaltyLedger;
use App\Data\Period;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le journal des pénalités tel que le réseau peut le publier.
 *
 * Seul point de décision du seuil pour ce journal — écrans et exports passent
 * tous par ici :
 *
 * - une série par assureur **autorisé** par perInsurer() sur la période, puis
 *   réévaluée **mois par mois** (règle « seuil à chaque granularité ») ;
 * - la série totale sans filtre couvre **tous** les assureurs sous convention,
 *   sans seuil, comme networkSummary(). Décision du 27/09/2026, risque nommé
 *   dans la spec : un seul assureur masqué se déduit par différence ;
 * - filtrée sur un assureur, la série totale devient ses chiffres : retenue en
 *   bloc s'il est masqué, sinon mois par mois comme sa série.
 */
class NetworkPenaltyJournal
{
    public function __construct(
        protected NetworkStatsService $stats,
        protected NetworkPenaltyLedger $ledger,
        protected SettingsRepository $settings,
    ) {
        //
    }

    public function for(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): PenaltyLedger
    {
        $minimum = $this->settings->anonymityMinPharmacies();
        $indicators = $this->stats->perInsurer($from, $to, $city, $insurerId);

        $authorized = [];
        $masked = 0;

        foreach ($indicators as $id => $entry) {
            if ($entry instanceof InsufficientData) {
                $masked++;

                continue;
            }

            if ($entry->penaltyTriggerDays !== null && $entry->penaltyRatePercent !== null) {
                $authorized[$id] = $entry->insurerName;
            }
        }

        asort($authorized);

        $filteredIsMasked = $insurerId !== null && ($indicators[$insurerId] ?? null) instanceof InsufficientData;
        $belowMinimum = fn (int $count): bool => $count > 0 && $count < $minimum;

        return $this->ledger->tally($from, $to, $city, $insurerId)->ledger(
            $authorized,
            function (?int $seriesInsurer, int $accruedPharmacies, int $declaredPharmacies) use ($insurerId, $filteredIsMasked, $belowMinimum): bool {
                if ($seriesInsurer === null && $insurerId === null) {
                    return false;
                }

                if ($seriesInsurer === null && $filteredIsMasked) {
                    return true;
                }

                return $belowMinimum($accruedPharmacies) || $belowMinimum($declaredPharmacies);
            },
            $masked,
        );
    }

    /**
     * Les assureurs sous convention qui ont au moins une déclaration, par nom.
     *
     * Pas restreints aux autorisés : la liste resterait sinon muette sur un
     * assureur masqué, que le filtre doit pouvoir nommer pour dire « retenu ».
     *
     * @return Collection<int, object>
     */
    public function clauseInsurers(): Collection
    {
        return DB::table('insurers')
            ->whereNotNull('penalty_trigger_days')
            ->whereNotNull('penalty_rate_bp')
            ->whereIn('id', DB::table('declarations')->distinct()->select('insurer_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
```

- [ ] **Step 4 : les tests passent, `DeclarationWindow` compris**

Run : `php artisan test --compact tests/Feature/Network tests/Feature/Admin/NetworkExportTest.php tests/Feature/Admin/NetworkTrendsTest.php`
Attendu : PASS.

- [ ] **Step 5 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Network tests/Feature/Network/NetworkPenaltyJournalTest.php
git commit -m "feat: tenir le journal des pénalités du réseau, sous seuil mois par mois

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5 : la prop deferred `penaltyTrend` sur les deux tableaux de bord

**Files :**
- Modify : `app/Http/Controllers/Pharmacy/PaymentJourneyController.php`, `app/Http/Controllers/Admin/NetworkTrendsController.php`
- Test : `tests/Feature/Pharmacy/PaymentJourneyTest.php`, `tests/Feature/Admin/NetworkTrendsTest.php`

**Interfaces :**
- Consumes : `PharmacyPenaltyLedger::for()`, `NetworkPenaltyJournal::for()`, `PenaltyLedger::toArray()`.
- Produces : prop Inertia `penaltyTrend` (deferred, groupe par défaut) de forme `PenaltyLedger::toArray()`.

- [ ] **Step 1 : tests qui échouent**

Dans `tests/Feature/Pharmacy/PaymentJourneyTest.php` (travelTo 15/08/2026 dans son `beforeEach`) :

```php
test('the penalty trend is deferred, then covers twelve months', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-03-31',
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('penaltyTrend')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('penaltyTrend.total.months', 12)
                ->where('penaltyTrend.insurers.0.insurerId', $insurer->id)
                ->where('penaltyTrend.total.months.9.month', '2026-06')
                ->where('penaltyTrend.total.months.9.accrued', 20_000)));
});
```

Dans `tests/Feature/Admin/NetworkTrendsTest.php` :

```php
test('the penalty trend is a deferred prop', function () {
    networkDeclare(Insurer::factory()->withPenalty()->create(), 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.trends'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('penaltyTrend')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('penaltyTrend.insurers', 1)
                ->has('penaltyTrend.total.months', 12)));
});
```

(12 mois : 15/08/2026, fenêtre « 12 derniers mois » = sept. 2025 → août 2026 ; l'index 9 est juin 2026.)

- [ ] **Step 2 : les voir échouer**

Run : `php artisan test --compact tests/Feature/Pharmacy/PaymentJourneyTest.php tests/Feature/Admin/NetworkTrendsTest.php --filter="penalty trend"`
Attendu : FAIL — `penaltyTrend` absente des props différées.

- [ ] **Step 3 : implémenter**

`PaymentJourneyController` : injecter `protected PharmacyPenaltyLedger $penaltyLedger` dans le constructeur, et ajouter à côté de `journey` :

```php
            // Deuxième lecture coûteuse : même traitement que le parcours.
            'penaltyTrend' => Inertia::defer(
                fn () => $this->penaltyLedger->for($pharmacy, ...Period::lastMonths(self::MONTHS))->toArray(),
            ),
```

(importer `App\Data\Period` et `App\Services\Pharmacy\PharmacyPenaltyLedger`).

`NetworkTrendsController` : injecter `protected NetworkPenaltyJournal $penaltyJournal`, et ajouter après `trend` :

```php
            'penaltyTrend' => Inertia::defer(
                fn () => $this->penaltyJournal->for($from, $to, $city)->toArray(),
            ),
```

- [ ] **Step 4 : les tests passent**

Run : `php artisan test --compact tests/Feature/Pharmacy/PaymentJourneyTest.php tests/Feature/Admin/NetworkTrendsTest.php`
Attendu : PASS.

- [ ] **Step 5 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Pharmacy/PaymentJourneyController.php app/Http/Controllers/Admin/NetworkTrendsController.php tests/Feature/Pharmacy/PaymentJourneyTest.php tests/Feature/Admin/NetworkTrendsTest.php
git commit -m "feat: servir l'évolution des pénalités aux deux tableaux de bord

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6 : la carte « Évolution des pénalités »

**Files :**
- Modify : `resources/js/types/aphaspb.ts`
- Create : `resources/js/components/aphaspb/charts/PenaltyTrendChart.vue`, `resources/js/components/aphaspb/PenaltyTrendCard.vue`
- Modify : `resources/js/pages/pharmacy/Dashboard.vue`, `resources/js/pages/admin/Trends.vue`

**Interfaces :**
- Consumes : prop `penaltyTrend` (Task 5), `ChartToolbar`, `FilterSelect`, `ChartSkeleton`, `useQueryState`/`useQueryId`, `exportChartToPng`, `formatMillions`, `CHART_COLORS`.
- Produces :
  - types TS `PenaltyLedgerMonth`, `PenaltyLedgerSeries`, `PenaltyLedger`, `PenaltyView = 'accrued' | 'declared'`, `isPenaltyView()`, `isLineOrBar()`.
  - `<PenaltyTrendChart :series :total :view :type />`
  - `<PenaltyTrendCard :ledger :subtitle :filename :show-insurer-filter />` (réutilisée Tasks 7-8).

Pas de runner JS dans ce projet : la vérification est `npm run types:check`, `npm run lint:check`, `npm run format:check`, `npm run build` et un contrôle au navigateur.

- [ ] **Step 1 : les types**

Ajouter à `resources/js/types/aphaspb.ts` :

```ts
/** Un mois du journal des pénalités — miroir de App\Data\PenaltyLedgerMonth. */
export type PenaltyLedgerMonth = {
    month: string;
    label: string;
    current: boolean;
    future: boolean;
    accrued: number | null;
    accruedCumulative: number | null;
    declared: number | null;
    withheld: boolean;
};

export type PenaltyLedgerSeries = {
    insurerId: number | null;
    name: string;
    months: PenaltyLedgerMonth[];
};

export type PenaltyLedger = {
    insurers: PenaltyLedgerSeries[];
    total: PenaltyLedgerSeries;
    maskedInsurers: number;
};

/** Les deux horloges du journal : le mois où la tranche tombe, ou celui de la facture. */
export type PenaltyView = 'accrued' | 'declared';

export function isPenaltyView(value: unknown): value is PenaltyView {
    return value === 'accrued' || value === 'declared';
}

/** Le camembert n'a pas de sens pour une évolution : ligne ou barres seulement. */
export function isLineOrBar(value: unknown): value is ChartType {
    return value === 'line' || value === 'bar';
}
```

- [ ] **Step 2 : le graphique**

Contrôler d'abord les props unovis :

```bash
grep -oE "^\s+[a-zA-Z]+[?]?:" node_modules/@unovis/ts/components/line/config.d.ts
grep -oE "^\s+[a-zA-Z]+[?]?:" node_modules/@unovis/ts/components/grouped-bar/config.d.ts
```

Attendu : `lineWidth`, `lineDashArray` pour line ; `groupPadding`, `barPadding`, `roundedCorners` pour grouped-bar (déjà utilisés par `DelayTrendChart` / `DelayBarChart`).

`resources/js/components/aphaspb/charts/PenaltyTrendChart.vue` :

```vue
<script setup lang="ts">
/**
 * L'évolution mensuelle des pénalités, en courbes ou en barres.
 *
 * Un mois retenu ou futur vaut null et reste un **trou** : tracé à zéro, il se
 * lirait « rien n'a couru », ce qui est une affirmation. Même règle que
 * DelayBarChart pour les mois sans règlement.
 *
 * Au-delà de trois séries, la couleur seule ne les sépare plus : les suivantes
 * reprennent la palette en pointillé, comme DelayTrendChart.
 */
import { VisAxis, VisGroupedBar, VisLine, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import { formatMillions } from '@/lib/millions';
import { CHART_COLORS } from '@/types/aphaspb';
import type {
    ChartType,
    PenaltyLedgerMonth,
    PenaltyLedgerSeries,
    PenaltyView,
} from '@/types/aphaspb';

const props = defineProps<{
    series: PenaltyLedgerSeries[];
    /** La série totale, ou null quand un seul assureur est affiché. */
    total: PenaltyLedgerSeries | null;
    view: PenaltyView;
    type: ChartType;
}>();

type Row = {
    index: number;
    label: string;
    total: number | null;
    [series: string]: number | string | null;
};

const pick = (month: PenaltyLedgerMonth | undefined): number | null =>
    month === undefined
        ? null
        : props.view === 'accrued'
          ? month.accrued
          : month.declared;

const months = computed(
    () => (props.total ?? props.series[0])?.months ?? [],
);

const data = computed<Row[]>(() =>
    months.value.map((month, index) => {
        const row: Row = {
            index,
            label: month.label,
            total: props.total ? pick(props.total.months[index]) : null,
        };

        props.series.forEach((one, position) => {
            row[`s${position}`] = pick(one.months[index]);
        });

        return row;
    }),
);

const SOLID_LIMIT = CHART_COLORS.length;

const isDashed = (position: number) => position >= SOLID_LIMIT;

const colorFor = (position: number) =>
    CHART_COLORS[position % CHART_COLORS.length] as string;

const positionsWhere = (dashed: boolean) =>
    props.series
        .map((_, position) => position)
        .filter((position) => isDashed(position) === dashed);

const solid = computed(() => positionsWhere(false));
const dashed = computed(() => positionsWhere(true));

const accessorsFor = (positions: number[]) =>
    positions.map(
        (position) => (row: Row) => row[`s${position}`] as number | null,
    );

const allAccessors = computed(() =>
    accessorsFor(props.series.map((_, position) => position)),
);

const x = (row: Row) => row.index;
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div
                v-for="(one, position) in series"
                :key="one.insurerId ?? one.name"
                class="flex items-center gap-2"
            >
                <span
                    class="h-[3px] w-5 rounded-full"
                    :style="{
                        background: colorFor(position),
                        opacity: isDashed(position) ? 0.6 : 1,
                    }"
                />
                <span class="text-[11px] font-medium text-ink/60">
                    {{ one.name }}
                </span>
            </div>

            <div v-if="total" class="flex items-center gap-2">
                <span class="h-[3px] w-5 rounded-full bg-ink/[0.42]" />
                <span class="text-[11px] font-medium text-ink/60">
                    {{ total.name }}
                </span>
            </div>
        </div>

        <VisXYContainer
            :data="data"
            :height="220"
            class="mt-3 [--vis-axis-tick-label-color:rgb(23_33_28_/_0.45)] [--vis-axis-tick-label-font-size:10px]"
        >
            <template v-if="type === 'bar'">
                <VisGroupedBar
                    v-if="series.length"
                    :x="x"
                    :y="allAccessors"
                    :color="series.map((_, position) => colorFor(position))"
                    :group-padding="0.18"
                    :bar-padding="0.06"
                    :rounded-corners="3"
                />
            </template>

            <template v-else>
                <VisLine
                    v-if="solid.length"
                    :x="x"
                    :y="accessorsFor(solid)"
                    :color="solid.map(colorFor)"
                    :line-width="2"
                />
                <VisLine
                    v-if="dashed.length"
                    :x="x"
                    :y="accessorsFor(dashed)"
                    :color="dashed.map(colorFor)"
                    :line-dash-array="[6, 4]"
                    :line-width="2"
                />
            </template>

            <!-- Le total par-dessus : en barres, il serait sinon caché. -->
            <VisLine
                v-if="total"
                :x="x"
                :y="(row: Row) => row.total"
                color="rgb(23 33 28 / 0.42)"
                :line-dash-array="[7, 4]"
                :line-width="1.5"
            />

            <VisAxis
                type="x"
                :tick-format="(index: number) => data[index]?.label ?? ''"
                :grid-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="(value: number) => formatMillions(value)"
                :num-ticks="4"
            />
        </VisXYContainer>
    </div>
</template>
```

Les deux `rgb(23 33 28 / …)` sont repris tels quels de `DelayTrendChart.vue`. Si la garde des littéraux (`tests/Feature/Design/PaletteSourceTest.php`) les refuse dans un nouveau fichier, la lancer (`php artisan test --compact tests/Feature/Design/PaletteSourceTest.php`) et remplacer par la classe/variable qu'elle indique.

- [ ] **Step 3 : la carte**

`resources/js/components/aphaspb/PenaltyTrendCard.vue` :

```vue
<script setup lang="ts">
/**
 * La carte « Évolution des pénalités », partagée par les deux tableaux de
 * bord et les deux pages Journal.
 *
 * Toutes les séries arrivent dans la charge différée : basculer d'horloge ou
 * restreindre à un assureur filtre ce que le navigateur détient déjà, sans
 * aller-retour — même choix que l'écran des tendances. Les clés d'URL sont
 * préfixées `penalty_` : `chart` appartient déjà au graphique voisin.
 */
import { Deferred } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import ChartToolbar from '@/components/aphaspb/charts/ChartToolbar.vue';
import PenaltyTrendChart from '@/components/aphaspb/charts/PenaltyTrendChart.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import { useQueryId, useQueryState } from '@/composables/useQueryState';
import { exportChartToPng } from '@/lib/chartPng';
import { CHART_COLORS, isLineOrBar, isPenaltyView } from '@/types/aphaspb';
import type { PenaltyLedger, PenaltyView } from '@/types/aphaspb';

const props = withDefaults(
    defineProps<{
        ledger?: PenaltyLedger;
        /** Sous-titre du PNG : l'officine, ou la période et la ville. */
        subtitle: string;
        filename: string;
        /** Faux sur les pages Journal, où le filtre assureur est côté serveur. */
        showInsurerFilter?: boolean;
    }>(),
    { ledger: undefined, showInsurerFilter: true },
);

const view = useQueryState<PenaltyView>('penalty_view', 'accrued', isPenaltyView);
const chartType = useQueryState('penalty_chart', 'line', isLineOrBar);
const insurer = useQueryId('penalty_insurer');

const VIEWS: { value: PenaltyView; label: string }[] = [
    { value: 'accrued', label: 'Courue par mois' },
    { value: 'declared', label: 'Par mois déclaré' },
];

const insurerOptions = computed(() => [
    { value: null, label: 'Tous les assureurs' },
    ...(props.ledger?.insurers ?? []).map((one) => ({
        value: one.insurerId,
        label: one.name,
    })),
]);

const shownInsurer = computed(() =>
    props.showInsurerFilter ? insurer.value : null,
);

const series = computed(() =>
    (props.ledger?.insurers ?? []).filter(
        (one) =>
            shownInsurer.value === null || one.insurerId === shownInsurer.value,
    ),
);

const total = computed(() =>
    shownInsurer.value === null ? (props.ledger?.total ?? null) : null,
);

const hasWithheld = computed(() =>
    [...series.value, ...(total.value ? [total.value] : [])].some((one) =>
        one.months.some((month) => month.withheld),
    ),
);

const caption = computed(() =>
    view.value === 'accrued'
        ? 'Pénalité tombée chaque mois calendaire, toutes factures confondues · le mois en cours est partiel.'
        : 'Pénalité à ce jour des factures de chaque mois déclaré · un mois encore ouvert continue de croître.',
);

const chartArea = ref<HTMLElement | null>(null);
const exporting = ref(false);

async function exportChart() {
    exporting.value = true;

    try {
        await exportChartToPng(chartArea.value, {
            title: 'Évolution des pénalités',
            subtitle: `${props.subtitle} · ${VIEWS.find((one) => one.value === view.value)?.label ?? ''}`,
            legend: [
                ...series.value.map((one, index) => ({
                    label: one.name,
                    color: CHART_COLORS[index % CHART_COLORS.length] as string,
                    dashed: index >= CHART_COLORS.length,
                    shape:
                        chartType.value === 'bar'
                            ? ('square' as const)
                            : ('line' as const),
                })),
                ...(total.value
                    ? [
                          {
                              label: total.value.name,
                              color: 'rgb(23 33 28 / 0.42)',
                              dashed: true,
                          },
                      ]
                    : []),
            ],
            filename: props.filename,
        });
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <section class="rounded-2xl border border-input bg-card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-ink">
                    Évolution des pénalités
                </h2>
                <p class="mt-1 text-[12px] text-ink/55">{{ caption }}</p>
            </div>

            <ChartToolbar
                v-model="chartType"
                :types="['line', 'bar']"
                :exporting="exporting"
                @export="exportChart"
            >
                <template #filters>
                    <div
                        class="flex items-center gap-0.5 rounded-[10px] border border-input bg-card p-0.5"
                        role="group"
                        aria-label="Horloge de la pénalité"
                    >
                        <button
                            v-for="one in VIEWS"
                            :key="one.value"
                            type="button"
                            class="flex h-[30px] items-center rounded-lg px-2.5 text-[11.5px] font-semibold transition-colors"
                            :class="
                                view === one.value
                                    ? 'bg-ink text-white'
                                    : 'text-ink/55 hover:bg-cream-header'
                            "
                            :aria-pressed="view === one.value"
                            @click="view = one.value"
                        >
                            {{ one.label }}
                        </button>
                    </div>

                    <FilterSelect
                        v-if="showInsurerFilter && ledger && ledger.insurers.length > 1"
                        v-model="insurer"
                        :options="insurerOptions"
                        size="compact"
                        aria-label="Filtrer les pénalités par assureur"
                    />
                </template>
            </ChartToolbar>
        </div>

        <Deferred data="penaltyTrend">
            <template #fallback>
                <ChartSkeleton class="mt-5" :height="220" />
            </template>

            <p
                v-if="ledger && ledger.insurers.length === 0 && ledger.maskedInsurers === 0"
                class="mt-5 text-[12.5px] text-ink/55"
            >
                Aucun assureur sous convention de pénalité.
            </p>

            <div v-else-if="ledger" ref="chartArea" class="mt-5">
                <PenaltyTrendChart
                    :series="series"
                    :total="total"
                    :view="view"
                    :type="chartType"
                />
            </div>

            <p
                v-if="hasWithheld"
                class="mt-3 text-[11.5px] text-ink/55"
            >
                Les trous de la courbe sont des mois retenus : trop peu
                d'officines pour publier le chiffre sans en exposer une.
            </p>

            <p
                v-if="ledger && ledger.maskedInsurers > 0"
                class="mt-1 text-[11.5px] text-ink/55"
            >
                {{ ledger.maskedInsurers }} assureur(s) masqué(s) sous le seuil
                d'anonymat · compté(s) dans le total.
            </p>
        </Deferred>
    </section>
</template>
```

- [ ] **Step 4 : brancher la carte**

`resources/js/pages/pharmacy/Dashboard.vue` : ajouter l'import de `PenaltyTrendCard` et du type `PenaltyLedger`, la prop `penaltyTrend?: PenaltyLedger;` dans `defineProps`, et la carte juste après la `section.journey-card` :

```vue
        <PenaltyTrendCard
            :ledger="penaltyTrend"
            :subtitle="pharmacyName"
            filename="aphaspb-penalites-officine"
        />
```

`resources/js/pages/admin/Trends.vue` : même import, prop `penaltyTrend?: PenaltyLedger;`, `'penaltyTrend'` ajouté au tableau `only` de `reload()`, et la carte après la `section.trend-card` :

```vue
        <PenaltyTrendCard
            :ledger="penaltyTrend"
            :subtitle="`${periodLabel}${city === null ? '' : ` · ${city}`}`"
            filename="aphaspb-penalites-reseau"
        />
```

Si l'espacement vertical des pages vient d'une grille ou d'un `gap` sur le conteneur, la carte en hérite ; sinon lui ajouter la classe de marge qu'utilisent les sections voisines.

- [ ] **Step 5 : vérifier**

Run : `npm run types:check && npm run lint:check && npm run format:check && npm run build`
Attendu : aucune erreur. Puis `php artisan test --compact tests/Feature/Design` (garde des littéraux).

Contrôle navigateur (`composer run dev`) : `/admin/trends` et `/{slug}/dashboard` — squelette puis courbe, bascule d'horloge, filtre assureur, barres, export PNG, rechargement de l'URL qui garde `penalty_view` / `penalty_chart` / `penalty_insurer`. Lire `browser-logs` (Boost) après chaque écran.

- [ ] **Step 6 : commit**

```bash
git add resources/js/types/aphaspb.ts resources/js/components/aphaspb/charts/PenaltyTrendChart.vue resources/js/components/aphaspb/PenaltyTrendCard.vue resources/js/pages/pharmacy/Dashboard.vue resources/js/pages/admin/Trends.vue
git commit -m "feat: tracer l'évolution des pénalités sur les deux tableaux de bord

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7 : la page « Journal des pénalités » de l'officine

**Files :**
- Create : `app/Http/Controllers/Pharmacy/PharmacyPenaltyLedgerController.php`, `resources/js/components/aphaspb/PenaltyLedgerTable.vue`, `resources/js/pages/pharmacy/PenaltyLedger.vue`
- Modify : `routes/web.php`, `app/Support/ConsoleNavigation.php`, `resources/js/lib/navIcons.ts`, `tests/Feature/Console/ConsoleShellTest.php`
- Test : `tests/Feature/Pharmacy/PenaltyLedgerPageTest.php`

**Interfaces :**
- Consumes : `PharmacyPenaltyLedger::for()` / `clauseInsurers()`, `PenaltyTrendCard` (Task 6).
- Produces :
  - route `pharmacy.penalty-ledger` (`GET /pharmacy/penalties`), contrôleur `PharmacyPenaltyLedgerController@index`.
  - `PharmacyPenaltyLedgerController::insurerId(Request $request, Pharmacy $pharmacy): ?int` (protégée ; réutilisée par `download` en Task 9).
  - `<PenaltyLedgerTable :ledger :show-withheld />`.
  - props de page : `penaltyTrend` (deferred), `period`, `periodLabel`, `periods`, `insurer`, `insurers: {id, name}[]`, `downloadUrl`, `pharmacyName`.

- [ ] **Step 1 : tests qui échouent**

`tests/Feature/Pharmacy/PenaltyLedgerPageTest.php` :

```php
<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

/**
 * Un titulaire et une facture de mars jamais réglée chez un assureur sous convention.
 *
 * @return array{0: User, 1: Insurer}
 */
function ledgerOfficine(): array
{
    $user = User::factory()->create();
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-03-31',
        'paid_on' => null,
        'delay_days' => null,
    ]);

    return [$user, $insurer];
}

test('the officine reads its own penalty ledger', function () {
    [$user, $insurer] = ledgerOfficine();

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/PenaltyLedger')
            ->where('insurers.0.id', $insurer->id)
            ->missing('penaltyTrend')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('penaltyTrend.total.months.7.month', '2026-05')
                ->where('penaltyTrend.total.months.7.accrued', 20_000)));
});

test('another officine\'s invoices never reach the ledger', function () {
    [$user, $insurer] = ledgerOfficine();
    [$other] = ledgerOfficine();

    $this->actingAs($other)
        ->get(route('pharmacy.penalty-ledger'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('penaltyTrend.total.months.7.accrued', 20_000)));
});

test('a forged insurer id is ignored', function () {
    [$user] = ledgerOfficine();
    $foreign = Insurer::factory()->withPenalty()->create();

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger', ['insurer' => $foreign->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('insurer', null)
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('penaltyTrend.insurers', 1)));
});

test('the ledger is not open to a network admin without an officine', function () {
    $this->actingAs(User::factory()->networkAdmin()->notOnboarded()->create())
        ->get(route('pharmacy.penalty-ledger'))
        ->assertForbidden();
});
```

Vérifier le dernier test contre le comportement réel des routes `pharmacy.*` pour un admin sans officine (`can:declare-payments` → 403, ou `onboarded` → redirection) en lisant un test voisin (`tests/Feature/Pharmacy/PharmacyExportTest.php`), et aligner l'assertion sur ce qu'il fait.

Dans `tests/Feature/Console/ConsoleShellTest.php`, passer `->has('console.nav', 5)` à `6` dans « a pharmacy gets the pharmacy shell, without space » (et dans le test admin à la Task 8).

(Au 19/09/2026, « 12 derniers mois » = oct. 2025 → sept. 2026 : l'index 7 est mai 2026.)

- [ ] **Step 2 : les voir échouer**

Run : `php artisan test --compact tests/Feature/Pharmacy/PenaltyLedgerPageTest.php`
Attendu : FAIL — `Route [pharmacy.penalty-ledger] not defined`.

- [ ] **Step 3 : contrôleur, route, navigation**

`app/Http/Controllers/Pharmacy/PharmacyPenaltyLedgerController.php` :

```php
<?php

namespace App\Http\Controllers\Pharmacy;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Pharmacy\PharmacyPenaltyLedger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le journal mensuel des pénalités d'une officine, et ses trois exports.
 *
 * Distinct de NetworkPenaltyLedgerController pour la même raison que les deux
 * exports : celui-ci nomme une officine et ne retient rien, l'autre ne nomme
 * personne et retient sous le seuil.
 *
 * L'officine vient toujours de la session, jamais de la requête.
 */
class PharmacyPenaltyLedgerController extends Controller
{
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(protected PharmacyPenaltyLedger $ledger)
    {
        //
    }

    public function index(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request, $pharmacy);

        [$from, $to] = $period->bounds();

        return Inertia::render('pharmacy/PenaltyLedger', [
            'penaltyTrend' => Inertia::defer(
                fn () => $this->ledger->for($pharmacy, $from, $to, $insurerId)->toArray(),
            ),
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'insurer' => $insurerId,
            'insurers' => $this->ledger->clauseInsurers($pharmacy)
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
                ->values(),
            'downloadUrl' => route('pharmacy.penalty-ledger.download', absolute: false),
            'pharmacyName' => $pharmacy->name,
        ]);
    }

    /**
     * L'assureur demandé, seulement s'il est sous convention **et** déclaré par
     * cette officine : un identifiant forgé retombe sur « tous », jamais sur
     * les chiffres d'un autre.
     */
    protected function insurerId(Request $request, Pharmacy $pharmacy): ?int
    {
        $requested = $request->integer('insurer');

        if ($requested === 0) {
            return null;
        }

        return $this->ledger->clauseInsurers($pharmacy)->contains('id', $requested) ? $requested : null;
    }
}
```

La route `download` référencée par `downloadUrl` n'existe qu'à la Task 9 : déclarer **dès maintenant** les deux routes, et une méthode `download()` provisoire qui fait `abort(404)`, remplacée à la Task 9. Dans le groupe `pharmacy.` de `routes/web.php`, après `data-exports.download` :

```php
        // « penalty-ledger » et non « penalties » seul : le nom reste lisible
        // côté Wayfinder et ne heurte aucun global JS.
        Route::get('penalties', [PharmacyPenaltyLedgerController::class, 'index'])->name('penalty-ledger');
        Route::get('penalties/download', [PharmacyPenaltyLedgerController::class, 'download'])->name('penalty-ledger.download');
```

(import `use App\Http\Controllers\Pharmacy\PharmacyPenaltyLedgerController;` en tête, trié avec les autres.)

`ConsoleNavigation::pharmacy()` — après « Historique » :

```php
        $definitions[] = ['Journal des pénalités', 'pharmacy.penalty-ledger', [], 'receipt'];
```

`resources/js/lib/navIcons.ts` : importer `Receipt` depuis `@lucide/vue` et ajouter `receipt: Receipt,` dans `ICONS`.

- [ ] **Step 4 : le tableau et la page**

`resources/js/components/aphaspb/PenaltyLedgerTable.vue` :

```vue
<script setup lang="ts">
/**
 * Le journal mois par mois : la ligne du total, dépliable par assureur.
 *
 * Une ligne retenue reste une ligne : absente, elle se lirait « rien couru ».
 */
import { ref } from 'vue';
import { formatAmount } from '@/lib/fcfa';
import type { PenaltyLedger, PenaltyLedgerMonth } from '@/types/aphaspb';

const props = defineProps<{ ledger: PenaltyLedger }>();

const open = ref<Set<string>>(new Set());

function toggle(month: string): void {
    const next = new Set(open.value);

    if (next.has(month)) {
        next.delete(month);
    } else {
        next.add(month);
    }

    open.value = next;
}

const canExpand = () => props.ledger.insurers.length > 1;

const monthOf = (index: number, series: { months: PenaltyLedgerMonth[] }) =>
    series.months[index];

const cell = (month: PenaltyLedgerMonth, value: number | null): string =>
    month.withheld ? 'retenu' : formatAmount(value);
</script>

<template>
    <div class="overflow-x-auto rounded-2xl border border-input bg-card">
        <table class="w-full min-w-[560px] text-[13px]">
            <thead>
                <tr class="text-left font-mono text-[10px] tracking-wide text-ink/50 uppercase">
                    <th class="px-4 py-3">Mois</th>
                    <th class="px-4 py-3 text-right">Pénalité courue</th>
                    <th class="px-4 py-3 text-right">Cumul couru</th>
                    <th class="px-4 py-3 text-right">Pénalité des factures du mois</th>
                </tr>
            </thead>
            <tbody>
                <template
                    v-for="(month, index) in ledger.total.months"
                    :key="month.month"
                >
                    <tr
                        class="border-t border-input"
                        :class="{ 'cursor-pointer hover:bg-cream-header': canExpand() }"
                        @click="canExpand() && toggle(month.month)"
                    >
                        <td class="px-4 py-2.5 font-semibold text-ink">
                            {{ month.label }}
                            <span v-if="month.current" class="ml-2 text-[11px] font-normal text-ink/50">en cours</span>
                            <span v-if="month.withheld" class="ml-2 text-[11px] font-normal text-ink/50">sous le seuil</span>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ cell(month, month.accrued) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ cell(month, month.accruedCumulative) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ cell(month, month.declared) }}</td>
                    </tr>

                    <template v-if="open.has(month.month)">
                        <tr
                            v-for="series in ledger.insurers"
                            :key="`${month.month}-${series.insurerId}`"
                            class="bg-cream-header/40 text-ink/70"
                        >
                            <td class="py-2 pr-4 pl-8">{{ series.name }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ cell(monthOf(index, series), monthOf(index, series).accrued) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ cell(monthOf(index, series), monthOf(index, series).accruedCumulative) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ cell(monthOf(index, series), monthOf(index, series).declared) }}</td>
                        </tr>
                    </template>
                </template>
            </tbody>
        </table>
    </div>
</template>
```

`resources/js/pages/pharmacy/PenaltyLedger.vue` :

```vue
<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import PenaltyLedgerTable from '@/components/aphaspb/PenaltyLedgerTable.vue';
import PenaltyTrendCard from '@/components/aphaspb/PenaltyTrendCard.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import type { PenaltyLedger } from '@/types/aphaspb';

const props = defineProps<{
    penaltyTrend?: PenaltyLedger;
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    insurer: number | null;
    insurers: { id: number; name: string }[];
    downloadUrl: string;
    pharmacyName: string;
}>();

const period = ref(props.period);
const insurer = ref(props.insurer);

const insurerOptions = computed(() => [
    { value: null, label: 'Tous mes assureurs' },
    ...props.insurers.map((one) => ({ value: one.id, label: one.name })),
]);

/** Le journal est différé : il doit être nommé dans `only` pour revenir. */
watch([period, insurer], () =>
    router.get(
        '/pharmacy/penalties',
        { period: period.value, insurer: insurer.value },
        {
            only: ['penaltyTrend', 'period', 'periodLabel', 'insurer'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    ),
);

/** Chaque lien porte les filtres : le fichier couvre l'écran, pas autre chose. */
const hrefFor = (format: 'csv' | 'xlsx' | 'pdf') => {
    const query = new URLSearchParams({ period: period.value, format });

    if (insurer.value) {
        query.set('insurer', String(insurer.value));
    }

    return `${props.downloadUrl}?${query.toString()}`;
};

const FORMATS = [
    { key: 'pdf' as const, label: 'PDF' },
    { key: 'xlsx' as const, label: 'XLSX' },
    { key: 'csv' as const, label: 'CSV' },
];
</script>

<template>
    <Head title="Journal des pénalités" />

    <div class="penalty-ledger-page">
        <ConsoleHeader title="Journal des pénalités">
            <template #filters>
                <div class="header-filters">
                    <FilterSelect v-model="period" :options="periods" aria-label="Filtrer par période" />
                    <FilterSelect v-model="insurer" :options="insurerOptions" aria-label="Filtrer par assureur" />
                </div>
            </template>
        </ConsoleHeader>

        <PenaltyTrendCard
            :ledger="penaltyTrend"
            :subtitle="`${pharmacyName} · ${periodLabel}`"
            filename="aphaspb-journal-penalites-officine"
            :show-insurer-filter="false"
        />

        <Deferred data="penaltyTrend">
            <template #fallback>
                <ChartSkeleton :height="320" />
            </template>

            <PenaltyLedgerTable v-if="penaltyTrend" :ledger="penaltyTrend" />
        </Deferred>

        <div class="exports">
            <span class="exports-label">Exporter ce journal</span>
            <a
                v-for="format in FORMATS"
                :key="format.key"
                :href="hrefFor(format.key)"
                class="export-link"
            >
                {{ format.label }}
            </a>
        </div>
    </div>
</template>

<style scoped>
.penalty-ledger-page {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.header-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.exports {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.exports-label {
    margin-right: 4px;
    font-size: 12px;
    color: var(--ink-muted);
}

.export-link {
    padding: 7px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--card);
    font-size: 12px;
    font-weight: 600;
}
</style>
```

Avant de committer, remplacer `--ink-muted`, `--border`, `--card` par les noms de jetons réellement définis dans `resources/css/app.css` (les chercher : `grep -n "^\s*--" resources/css/app.css | head -60`) et aligner l'espacement de page sur `pharmacy/Exports.vue`. La garde des littéraux refuse toute couleur écrite en dur.

- [ ] **Step 5 : vérifier**

Run : `php artisan test --compact tests/Feature/Pharmacy/PenaltyLedgerPageTest.php tests/Feature/Console` puis `npm run types:check && npm run lint:check && npm run format:check`.
Attendu : PASS. `NavIconCoverageTest` doit rester vert (la clé `receipt` existe des deux côtés).

- [ ] **Step 6 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Pharmacy/PharmacyPenaltyLedgerController.php routes/web.php app/Support/ConsoleNavigation.php resources/js/lib/navIcons.ts resources/js/components/aphaspb/PenaltyLedgerTable.vue resources/js/pages/pharmacy/PenaltyLedger.vue tests/Feature/Pharmacy/PenaltyLedgerPageTest.php tests/Feature/Console/ConsoleShellTest.php
git commit -m "feat: ouvrir le journal des pénalités de l'officine

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8 : la page « Journal des pénalités » du réseau

**Files :**
- Create : `app/Http/Controllers/Admin/NetworkPenaltyLedgerController.php`, `resources/js/pages/admin/PenaltyLedger.vue`
- Modify : `routes/web.php`, `app/Support/ConsoleNavigation.php`, `tests/Feature/Console/ConsoleShellTest.php`
- Test : `tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php`

**Interfaces :**
- Consumes : `NetworkPenaltyJournal::for()` / `clauseInsurers()`, `PenaltyTrendCard`, `PenaltyLedgerTable`.
- Produces : routes `admin.penalty-ledger` / `admin.penalty-ledger.download` ; `NetworkPenaltyLedgerController::insurerId(Request): ?int` (protégée) ; props de page = celles de la Task 7 sans `pharmacyName`, avec `city` et `cities`.

- [ ] **Step 1 : tests qui échouent**

`tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php` :

```php
<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

function networkLedgerDeclare(Insurer $insurer, int $count): void
{
    Pharmacy::factory()->count($count)->create()->each(fn (Pharmacy $pharmacy) => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-03-31',
        'paid_on' => null,
        'delay_days' => null,
    ]));
}

test('the network admin reads the network penalty ledger', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkLedgerDeclare($insurer, 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/PenaltyLedger')
            ->missing('penaltyTrend')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('penaltyTrend.insurers', 1)
                ->where('penaltyTrend.total.months.7.accrued', 100_000)));
});

test('an officine user cannot open the network ledger', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.penalty-ledger'))
        ->assertForbidden();
});

test('an unknown insurer id falls back to every insurer', function () {
    $insurer = Insurer::factory()->withPenalty()->create();
    networkLedgerDeclare($insurer, 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger', ['insurer' => 999_999]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('insurer', null));
});
```

Dans `ConsoleShellTest`, passer à `6` le `has('console.nav', 5)` de « an admin gets the admin shell with its space ».

- [ ] **Step 2 : les voir échouer**

Run : `php artisan test --compact tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php`
Attendu : FAIL — route introuvable.

- [ ] **Step 3 : contrôleur, route, navigation, page**

`app/Http/Controllers/Admin/NetworkPenaltyLedgerController.php` :

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Network\NetworkPenaltyJournal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le journal mensuel des pénalités du réseau, et ses trois exports.
 *
 * Ne nomme aucune officine. Toute rétention se décide dans
 * NetworkPenaltyJournal : ce contrôleur n'en prend aucune.
 */
class NetworkPenaltyLedgerController extends Controller
{
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(protected NetworkPenaltyJournal $journal)
    {
        //
    }

    public function index(Request $request): Response
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request);

        [$from, $to] = $period->bounds();

        return Inertia::render('admin/PenaltyLedger', [
            'penaltyTrend' => Inertia::defer(
                fn () => $this->journal->for($from, $to, $city, $insurerId)->toArray(),
            ),
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'city' => $city,
            'cities' => Pharmacy::filterableCities(),
            'insurer' => $insurerId,
            'insurers' => $this->journal->clauseInsurers()
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
                ->values(),
            'downloadUrl' => route('admin.penalty-ledger.download', absolute: false),
        ]);
    }

    /**
     * Un identifiant hors liste retombe sur « tous » : un fichier vide se
     * lirait « rien couru », pas « filtre sans objet ».
     */
    protected function insurerId(Request $request): ?int
    {
        $requested = $request->integer('insurer');

        if ($requested === 0) {
            return null;
        }

        return $this->journal->clauseInsurers()->contains('id', $requested) ? $requested : null;
    }
}
```

Même provisoire qu'en Task 7 : `download()` fait `abort(404)` jusqu'à la Task 10.

`routes/web.php`, groupe `admin.`, après `csv-exports.download` :

```php
        Route::get('penalties', [NetworkPenaltyLedgerController::class, 'index'])->name('penalty-ledger');
        Route::get('penalties/download', [NetworkPenaltyLedgerController::class, 'download'])->name('penalty-ledger.download');
```

`ConsoleNavigation::admin()` — après « Évolution » :

```php
                ['Journal des pénalités', 'admin.penalty-ledger', [], 'receipt'],
```

`resources/js/pages/admin/PenaltyLedger.vue` : reprendre **intégralement** la page officine de la Task 7 avec ces différences, écrites ici en entier pour les parties qui changent :

```ts
const props = defineProps<{
    penaltyTrend?: PenaltyLedger;
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    city: string | null;
    cities: string[];
    insurer: number | null;
    insurers: { id: number; name: string }[];
    downloadUrl: string;
}>();

const period = ref(props.period);
const city = ref(props.city);
const insurer = ref(props.insurer);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

const insurerOptions = computed(() => [
    { value: null, label: 'Tous les assureurs' },
    ...props.insurers.map((one) => ({ value: one.id, label: one.name })),
]);

watch([period, city, insurer], () =>
    router.get(
        '/admin/penalties',
        { period: period.value, city: city.value, insurer: insurer.value },
        {
            only: ['penaltyTrend', 'period', 'periodLabel', 'city', 'insurer'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    ),
);

const hrefFor = (format: 'csv' | 'xlsx' | 'pdf') => {
    const query = new URLSearchParams({ period: period.value, format });

    if (city.value) {
        query.set('city', city.value);
    }

    if (insurer.value) {
        query.set('insurer', String(insurer.value));
    }

    return `${props.downloadUrl}?${query.toString()}`;
};
```

Dans le template : trois `FilterSelect` (période, ville, assureur), `PenaltyTrendCard` avec `:subtitle="`${periodLabel}${city === null ? '' : ` · ${city}`}`"` et `filename="aphaspb-journal-penalites-reseau"`, et, sous le tableau :

```vue
            <p v-if="penaltyTrend && penaltyTrend.maskedInsurers > 0" class="masked-note">
                Le total couvre aussi {{ penaltyTrend.maskedInsurers }} assureur(s) masqué(s) sous le seuil d'anonymat.
            </p>
```

- [ ] **Step 4 : vérifier**

Run : `php artisan test --compact tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php tests/Feature/Console` puis `npm run types:check && npm run lint:check && npm run format:check`.
Attendu : PASS.

- [ ] **Step 5 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/NetworkPenaltyLedgerController.php routes/web.php app/Support/ConsoleNavigation.php resources/js/pages/admin/PenaltyLedger.vue tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php tests/Feature/Console/ConsoleShellTest.php
git commit -m "feat: ouvrir le journal des pénalités du réseau

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9 : les exports du journal de l'officine

**Files :**
- Create : `app/Services/Pharmacy/PharmacyPenaltyLedgerRows.php`, `app/Services/Pharmacy/PharmacyPenaltyLedgerPdf.php`, `resources/views/exports/penalty-ledger-pharmacy.blade.php`
- Modify : `app/Http/Controllers/Pharmacy/PharmacyPenaltyLedgerController.php` (remplace le `download()` provisoire)
- Test : `tests/Feature/Pharmacy/PenaltyLedgerPageTest.php`

**Interfaces :**
- Consumes : `PharmacyPenaltyLedger::for()`, `CsvRenderer::render()`, `XlsxWriter::write()`.
- Produces :
  - `PharmacyPenaltyLedgerRows::COLUMNS = ['mois', 'assureur', 'penalite_courue', 'cumul_couru', 'penalite_mois_declare', 'mois_en_cours']`
  - `PharmacyPenaltyLedgerRows::rows(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId = null): iterable<int, list<string|int|null>>`
  - `PharmacyPenaltyLedgerPdf::document(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId, string $periodLabel): Barryvdh\DomPDF\PDF`

- [ ] **Step 1 : tests qui échouent**

Ajouter à `tests/Feature/Pharmacy/PenaltyLedgerPageTest.php` :

```php
use App\Services\Pharmacy\PharmacyPenaltyLedgerRows;

/**
 * @return list<list<string>>
 */
function ledgerCsv(User $user, array $query = []): array
{
    $body = str_replace("\xEF\xBB\xBF", '', test()->actingAs($user)
        ->get(route('pharmacy.penalty-ledger.download', $query))
        ->streamedContent());

    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\n", trim($body))),
    );
}

test('the CSV has one row per month and insurer, then the total', function () {
    [$user] = ledgerOfficine();

    $rows = ledgerCsv($user, ['format' => 'csv']);
    $columns = PharmacyPenaltyLedgerRows::COLUMNS;
    $may = array_values(array_filter($rows, fn (array $row) => $row[array_search('mois', $columns)] === '2026-05'));

    expect($rows[0])->toBe($columns)
        ->and(array_column($may, array_search('assureur', $columns)))->toBe(['NSIA', 'Tous assureurs'])
        ->and($may[0][array_search('penalite_courue', $columns)])->toBe('20000')
        // Octobre et la suite ne sont pas encore arrivés : aucune ligne.
        ->and(array_filter($rows, fn (array $row) => $row[0] === '2026-10'))->toBe([]);
});

test('the current month is flagged in the file', function () {
    [$user] = ledgerOfficine();

    $rows = ledgerCsv($user, ['format' => 'csv']);
    $columns = PharmacyPenaltyLedgerRows::COLUMNS;
    $september = array_values(array_filter($rows, fn (array $row) => $row[0] === '2026-09'))[0];

    expect($september[array_search('mois_en_cours', $columns)])->toBe('oui');
});

test('the three formats download with their own type and name', function (string $format, string $type) {
    [$user] = ledgerOfficine();
    $slug = $user->currentPharmacy->slug;

    $this->actingAs($user)
        ->get(route('pharmacy.penalty-ledger.download', ['format' => $format]))
        ->assertOk()
        ->assertHeader('Content-Type', $type)
        ->assertDownload("{$slug}-penalites-2026-09.{$format}");
})->with([
    ['csv', 'text/csv; charset=UTF-8'],
    ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ['pdf', 'application/pdf'],
]);
```

- [ ] **Step 2 : les voir échouer**

Run : `php artisan test --compact tests/Feature/Pharmacy/PenaltyLedgerPageTest.php`
Attendu : FAIL — 404 du `download()` provisoire.

- [ ] **Step 3 : implémenter**

`app/Services/Pharmacy/PharmacyPenaltyLedgerRows.php` :

```php
<?php

namespace App\Services\Pharmacy;

use App\Data\PenaltyLedgerMonth;
use App\Data\Period;
use App\Models\Pharmacy;

/**
 * Le journal des pénalités d'une officine, en lignes de tableur.
 *
 * Classe sœur de NetworkPenaltyLedgerRows, jamais fusionnée avec elle : celle-ci
 * nomme l'officine et ne retient rien (règle exports.md).
 *
 * Une ligne par (mois, assureur), puis la ligne « Tous assureurs » du mois —
 * sauf filtre sur un assureur, où elle répéterait la précédente. Les mois
 * futurs de la période n'ont pas de ligne : il n'y a rien à y écrire.
 */
class PharmacyPenaltyLedgerRows
{
    public const COLUMNS = ['mois', 'assureur', 'penalite_courue', 'cumul_couru', 'penalite_mois_declare', 'mois_en_cours'];

    public function __construct(protected PharmacyPenaltyLedger $ledger)
    {
        //
    }

    /**
     * @return iterable<int, list<string|int|null>>
     */
    public function rows(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId = null): iterable
    {
        $ledger = $this->ledger->for($pharmacy, $from, $to, $insurerId);

        foreach ($ledger->total->months as $index => $total) {
            if ($total->future) {
                continue;
            }

            foreach ($ledger->insurers as $series) {
                yield $this->row($series->months[$index], $series->name);
            }

            if ($insurerId === null) {
                yield $this->row($total, $ledger->total->name);
            }
        }
    }

    /**
     * @return list<string|int|null>
     */
    protected function row(PenaltyLedgerMonth $month, string $name): array
    {
        return [
            $month->month,
            $name,
            $month->accrued,
            $month->accruedCumulative,
            $month->declared,
            $month->current ? 'oui' : 'non',
        ];
    }
}
```

`app/Services/Pharmacy/PharmacyPenaltyLedgerPdf.php` :

```php
<?php

namespace App\Services\Pharmacy;

use App\Data\Period;
use App\Models\Pharmacy;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Le journal des pénalités d'une officine, mis en page pour être lu.
 */
class PharmacyPenaltyLedgerPdf
{
    public function __construct(protected PharmacyPenaltyLedger $ledger)
    {
        //
    }

    public function document(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId, string $periodLabel): PdfDocument
    {
        return Pdf::loadView('exports.penalty-ledger-pharmacy', [
            'ledger' => $this->ledger->for($pharmacy, $from, $to, $insurerId),
            'pharmacyName' => $pharmacy->name,
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');
    }
}
```

`resources/views/exports/penalty-ledger-pharmacy.blade.php` — reprendre **tel quel** le bloc `<style>` de `resources/views/exports/pharmacy.blade.php` (en-tête et pied `position: fixed`, `@page`, `table`, `.sheet-footer .page-number:after`), puis :

```blade
{{--
    Le journal des pénalités d'une officine, pour dompdf : tableaux seulement,
    ni flex ni grid ; en-tête et pied fixés ; lignes insécables.
--}}
<style>
    {{-- … bloc <style> repris de exports/pharmacy.blade.php … --}}
    .ledger th, .ledger td { padding: 5px 7px; border-bottom: 0.6px solid #dfe7e5; text-align: right; }
    .ledger th:first-child, .ledger td:first-child { text-align: left; }
    .ledger tr { page-break-inside: avoid; }
    .ledger .total td { font-weight: bold; }
    .muted { color: #6b7878; }
</style>

<div class="sheet-header">
    <div class="kicker">Journal des pénalités</div>
    <div class="brand">{{ $pharmacyName }}</div>
    <div class="scope">{{ $periodLabel }}</div>
</div>

<div class="sheet-footer">
    Édité le {{ $generatedAt->format('d/m/Y') }} · page <span class="page-number"></span>
</div>

<h2>Tous assureurs</h2>
<p class="lede">Pénalité courue : tombée pendant le mois. Mois déclaré : pénalité à ce jour des factures du mois. Le mois en cours est partiel.</p>

<table class="ledger">
    <thead>
        <tr><th>Mois</th><th>Courue</th><th>Cumul</th><th>Mois déclaré</th></tr>
    </thead>
    <tbody>
        @foreach ($ledger->total->months as $month)
            @continue($month->future)
            <tr class="total">
                <td>{{ $month->label }}@if ($month->current) <span class="muted">· en cours</span>@endif</td>
                <td>{{ \App\Support\Fcfa::format($month->accrued) }}</td>
                <td>{{ \App\Support\Fcfa::format($month->accruedCumulative) }}</td>
                <td>{{ \App\Support\Fcfa::format($month->declared) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@foreach ($ledger->insurers as $series)
    <h2 style="margin-top: 18px;">{{ $series->name }}</h2>
    <table class="ledger">
        <thead>
            <tr><th>Mois</th><th>Courue</th><th>Cumul</th><th>Mois déclaré</th></tr>
        </thead>
        <tbody>
            @foreach ($series->months as $month)
                @continue($month->future)
                <tr>
                    <td>{{ $month->label }}</td>
                    <td>{{ \App\Support\Fcfa::format($month->accrued) }}</td>
                    <td>{{ \App\Support\Fcfa::format($month->accruedCumulative) }}</td>
                    <td>{{ \App\Support\Fcfa::format($month->declared) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach
```

Avant d'écrire la vue, lire `app/Support/Fcfa.php` et la façon dont `exports/pharmacy.blade.php` formate un montant (et rend null en « — ») ; utiliser **cette** fonction, pas `Fcfa::format` si elle s'appelle autrement.

Dans `PharmacyPenaltyLedgerController`, injecter `PharmacyPenaltyLedgerRows $rows`, `PharmacyPenaltyLedgerPdf $pdf`, `CsvRenderer $csv`, `XlsxWriter $xlsx`, et remplacer `download()` :

```php
    public function download(Request $request): StreamedResponse|BinaryFileResponse
    {
        $pharmacy = $request->user()->currentPharmacy;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request, $pharmacy);

        [$from, $to] = $period->bounds();

        $stem = sprintf('%s-penalites-%04d-%02d', $pharmacy->slug, $to->year, $to->month);

        // Un seul point d'entrée : le fichier ne peut pas couvrir une période
        // ou un assureur différents de l'écran.
        return match ($request->string('format')->value()) {
            'xlsx' => $this->workbook($stem.'.xlsx', $this->rows->rows($pharmacy, $from, $to, $insurerId)),
            'pdf' => $this->report($stem.'.pdf', $this->pdf->document($pharmacy, $from, $to, $insurerId, $period->describe())),
            default => $this->spreadsheet($stem.'.csv', $this->rows->rows($pharmacy, $from, $to, $insurerId)),
        };
    }

    /**
     * @param  iterable<int, list<string|int|null>>  $rows
     */
    protected function spreadsheet(string $filename, iterable $rows): StreamedResponse
    {
        $lines = $this->csv->render(PharmacyPenaltyLedgerRows::COLUMNS, $rows);

        return response()->streamDownload(function () use ($lines) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Un BOM, sans quoi un Excel français lit les accents de travers.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($lines as $line) {
                fputcsv($handle, $line, ';', '"', '');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  iterable<int, list<string|int|null>>  $rows
     */
    protected function workbook(string $filename, iterable $rows): BinaryFileResponse
    {
        // tempnam() tel quel : lui concaténer une extension abandonnerait un orphelin.
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        $this->xlsx->write($path, 'Journal des pénalités', PharmacyPenaltyLedgerRows::COLUMNS, $rows);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    protected function report(string $filename, PdfDocument $document): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        file_put_contents($path, $document->output());

        return response()->download($path, $filename, ['Content-Type' => 'application/pdf'])->deleteFileAfterSend();
    }
```

(imports : `CsvRenderer`, `XlsxWriter`, `PharmacyPenaltyLedgerRows`, `PharmacyPenaltyLedgerPdf`, `Barryvdh\DomPDF\PDF as PdfDocument`, `StreamedResponse`, `BinaryFileResponse`.)

- [ ] **Step 4 : les tests passent**

Run : `php artisan test --compact tests/Feature/Pharmacy/PenaltyLedgerPageTest.php`
Attendu : PASS. En cas d'écart de `Content-Type` sur le CSV, comparer avec l'assertion équivalente de `PharmacyExportTest` et s'y aligner.

- [ ] **Step 5 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Pharmacy/PharmacyPenaltyLedgerRows.php app/Services/Pharmacy/PharmacyPenaltyLedgerPdf.php resources/views/exports/penalty-ledger-pharmacy.blade.php app/Http/Controllers/Pharmacy/PharmacyPenaltyLedgerController.php tests/Feature/Pharmacy/PenaltyLedgerPageTest.php
git commit -m "feat: exporter le journal des pénalités de l'officine en trois formats

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10 : les exports du journal du réseau

**Files :**
- Create : `app/Services/Network/NetworkPenaltyLedgerRows.php`, `app/Services/Network/NetworkPenaltyLedgerPdf.php`, `resources/views/exports/penalty-ledger-network.blade.php`
- Modify : `app/Http/Controllers/Admin/NetworkPenaltyLedgerController.php`
- Test : `tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php`

**Interfaces :**
- Consumes : `NetworkPenaltyJournal::for()`, `SettingsRepository::anonymityMinPharmacies()`, `CsvRenderer`, `XlsxWriter`.
- Produces :
  - `NetworkPenaltyLedgerRows::COLUMNS = ['mois', 'assureur', 'penalite_courue', 'cumul_couru', 'penalite_mois_declare', 'mois_en_cours', 'retenu']`
  - `NetworkPenaltyLedgerRows::rows(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): iterable`
  - `NetworkPenaltyLedgerPdf::document(Period $from, Period $to, ?string $city, ?int $insurerId, string $periodLabel): PdfDocument`

- [ ] **Step 1 : tests qui échouent**

Ajouter à `tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php` :

```php
use App\Services\Network\NetworkPenaltyLedgerRows;

/**
 * @return list<list<string>>
 */
function networkLedgerCsv(User $admin, array $query = []): array
{
    $body = str_replace("\xEF\xBB\xBF", '', test()->actingAs($admin)
        ->get(route('admin.penalty-ledger.download', ['format' => 'csv', ...$query]))
        ->streamedContent());

    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\n", trim($body))),
    );
}

test('a withheld month keeps its row, emptied and explained', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);
    networkLedgerDeclare($insurer, 5);
    // Juin : une seule officine déclarante.
    Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 6,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-06-30',
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $rows = networkLedgerCsv(User::factory()->networkAdmin()->create());
    $columns = NetworkPenaltyLedgerRows::COLUMNS;
    $june = array_values(array_filter($rows, fn (array $row) => $row[0] === '2026-06' && $row[1] === 'NSIA'))[0];

    expect($june[array_search('penalite_courue', $columns)])->toBe('')
        ->and($june[array_search('penalite_mois_declare', $columns)])->toBe('')
        ->and($june[array_search('retenu', $columns)])->toBe('moins de 5 officines');
});

test('the network file never names an officine', function () {
    $insurer = Insurer::factory()->withPenalty()->create();
    networkLedgerDeclare($insurer, 5);
    Pharmacy::query()->first()->update(['name' => 'Pharmacie Nommable']);

    $admin = User::factory()->networkAdmin()->create();

    foreach (['csv', 'xlsx', 'pdf'] as $format) {
        $response = $this->actingAs($admin)->get(route('admin.penalty-ledger.download', ['format' => $format]))->assertOk();
        $content = $format === 'csv' ? $response->streamedContent() : file_get_contents($response->baseResponse->getFile()->getPathname());

        expect($content)->not->toContain('Pharmacie Nommable');
    }
});

test('the network PDF renders with a withheld month', function () {
    $insurer = Insurer::factory()->withPenalty()->create();
    networkLedgerDeclare($insurer, 2);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger.download', ['format' => 'pdf', 'insurer' => $insurer->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('reseau-penalites-2026-09.pdf');
});
```

Le test « never names » est faible pour XLSX et PDF (binaires compressés) : c'est le CSV qui porte l'assertion. Le garder pour les trois formats quand même — il rougit si un rendu échoue — et ne pas prétendre dans un commentaire qu'il prouve l'absence de fuite dans le PDF.

- [ ] **Step 2 : les voir échouer**

Run : `php artisan test --compact tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php`
Attendu : FAIL — 404 du `download()` provisoire.

- [ ] **Step 3 : implémenter**

`app/Services/Network/NetworkPenaltyLedgerRows.php` :

```php
<?php

namespace App\Services\Network;

use App\Data\PenaltyLedgerMonth;
use App\Data\Period;
use App\Services\Settings\SettingsRepository;

/**
 * Le journal des pénalités du réseau, en lignes de tableur.
 *
 * Ne nomme aucune officine. Ne décide d'aucune rétention : les mois retenus
 * arrivent déjà vidés de NetworkPenaltyJournal, et gardent leur ligne avec
 * l'explication — une ligne absente se lirait « rien couru ».
 */
class NetworkPenaltyLedgerRows
{
    public const COLUMNS = ['mois', 'assureur', 'penalite_courue', 'cumul_couru', 'penalite_mois_declare', 'mois_en_cours', 'retenu'];

    public function __construct(
        protected NetworkPenaltyJournal $journal,
        protected SettingsRepository $settings,
    ) {
        //
    }

    /**
     * @return iterable<int, list<string|int|null>>
     */
    public function rows(Period $from, Period $to, ?string $city = null, ?int $insurerId = null): iterable
    {
        $ledger = $this->journal->for($from, $to, $city, $insurerId);
        $reason = sprintf('moins de %d officines', $this->settings->anonymityMinPharmacies());

        foreach ($ledger->total->months as $index => $total) {
            if ($total->future) {
                continue;
            }

            foreach ($ledger->insurers as $series) {
                yield $this->row($series->months[$index], $series->name, $reason);
            }

            // Filtrée, la ligne « Tous assureurs » est l'assureur choisi : on la
            // garde quand même, c'est la seule qui porte ses chiffres s'il est
            // masqué (et elle arrive alors retenue).
            if ($insurerId === null || $ledger->insurers === []) {
                yield $this->row($total, $ledger->total->name, $reason);
            }
        }
    }

    /**
     * @return list<string|int|null>
     */
    protected function row(PenaltyLedgerMonth $month, string $name, string $reason): array
    {
        return [
            $month->month,
            $name,
            $month->accrued,
            $month->accruedCumulative,
            $month->declared,
            $month->current ? 'oui' : 'non',
            $month->withheld ? $reason : null,
        ];
    }
}
```

`app/Services/Network/NetworkPenaltyLedgerPdf.php` :

```php
<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Services\Settings\SettingsRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Le journal des pénalités du réseau, mis en page pour être joint à un courrier.
 *
 * Les pages itèrent le journal publié par NetworkPenaltyJournal : un assureur
 * masqué n'a pas de page, et un mois retenu y est écrit « retenu ».
 */
class NetworkPenaltyLedgerPdf
{
    public function __construct(
        protected NetworkPenaltyJournal $journal,
        protected SettingsRepository $settings,
    ) {
        //
    }

    public function document(Period $from, Period $to, ?string $city, ?int $insurerId, string $periodLabel): PdfDocument
    {
        return Pdf::loadView('exports.penalty-ledger-network', [
            'ledger' => $this->journal->for($from, $to, $city, $insurerId),
            'city' => $city,
            'periodLabel' => $periodLabel,
            'anonymityThreshold' => $this->settings->anonymityMinPharmacies(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');
    }
}
```

`resources/views/exports/penalty-ledger-network.blade.php` : même structure que la vue officine de la Task 9 (bloc `<style>` repris de `exports/network.blade.php`), avec ces différences, écrites en entier :

```blade
<div class="sheet-header">
    <div class="kicker">Journal des pénalités · réseau APhaSPB</div>
    <div class="brand">{{ $periodLabel }}</div>
    <div class="scope">{{ $city ?? 'Toutes les villes' }} · aucun chiffre sous {{ $anonymityThreshold }} officines</div>
</div>
```

et, dans chaque cellule de montant des deux boucles (total et assureurs) :

```blade
<td>{{ $month->withheld ? 'retenu' : \App\Support\Fcfa::format($month->accrued) }}</td>
```

(même remarque qu'en Task 9 sur le nom réel du formateur), plus, après le tableau du total :

```blade
@if ($ledger->maskedInsurers > 0)
    <p class="lede">Le total couvre aussi {{ $ledger->maskedInsurers }} assureur(s) masqué(s) sous le seuil d'anonymat.</p>
@endif
```

`NetworkPenaltyLedgerController::download()` : identique à celui de la Task 9, avec `$city = $request->string('city')->value() ?: null;`, `$insurerId = $this->insurerId($request)`, `$stem = sprintf('reseau-penalites-%04d-%02d', $to->year, $to->month)`, `$this->rows->rows($from, $to, $city, $insurerId)`, `$this->pdf->document($from, $to, $city, $insurerId, $period->describe())`, `NetworkPenaltyLedgerRows::COLUMNS`, et les mêmes méthodes protégées `spreadsheet()` / `workbook()` / `report()` recopiées (la règle exports.md veut deux contrôleurs, pas une classe de base commune).

- [ ] **Step 4 : les tests passent**

Run : `php artisan test --compact tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php`
Attendu : PASS.

- [ ] **Step 5 : commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Network/NetworkPenaltyLedgerRows.php app/Services/Network/NetworkPenaltyLedgerPdf.php resources/views/exports/penalty-ledger-network.blade.php app/Http/Controllers/Admin/NetworkPenaltyLedgerController.php tests/Feature/Admin/NetworkPenaltyLedgerPageTest.php
git commit -m "feat: exporter le journal des pénalités du réseau en trois formats

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11 : consigner, réconcilier la spec, vérifier en entier

**Files :**
- Modify : `docs/superpowers/specs/2026-09-27-journal-penalites-design.md`
- Via `record-rule` (Boost MCP) : `.ai/rules/*`

- [ ] **Step 1 : réconcilier la spec avec ce qui a été construit**

Mettre à jour la spec sur ces points, décidés au plan :

- §4.1 : `PharmacyPenaltyLedger` lit en **query builder**, pas en Eloquent (pas de `private_note`, même résultat).
- §4.2 / §4.3 : `PenaltyTally` porte l'accumulation et la construction des DTO ; `NetworkPenaltyJournal` décide du seuil. `PenaltyLedgerMonth` a un champ `future`.
- §5.1 : la bascule et le filtre assureur de la carte filtrent **côté navigateur** (toutes les séries sont dans la charge différée), comme l'écran des tendances — pas de partial reload. Un seul assureur affiché ne trace pas son cumul : le cumul vit dans le tableau.
- §6 : **un mois retenu vide tous les cumuls suivants** (déduction par différence).
- §7.3 : contrôleurs `Pharmacy\PharmacyPenaltyLedgerController` et `Admin\NetworkPenaltyLedgerController`.
- §8 : le test à espion est remplacé par `a masked insurer has no series but still counts in the unfiltered total` — l'accumulateur reçoit volontairement tous les assureurs (pour le total), c'est la publication qui filtre.

- [ ] **Step 2 : consigner les règles durables**

Appeler `record-rule` (Boost) trois fois :

1. glob `app/Services/Declarations/**` — titre « Le journal des pénalités lit plus large que la période » — note : la vue « couru » lit toute facture déposée avant la fin de la période et non soldée avant son début (`PenaltyLedgerWindow`) ; bornes en `<` premier jour du mois suivant, jamais `<= dernier jour` (dates stockées avec heure). `accruedInDays()` est la somme de `tranches()` : un seul algorithme.
2. glob `app/Services/Network/**` — titre « Journal des pénalités : cumul vidé après un mois retenu, total sans seuil » — note : cumul(M) − cumul(M−1) = couru(M), donc tout cumul postérieur à un mois retenu est null ; la série totale non filtrée couvre les assureurs masqués (décision du 27/09/2026, verrouillée par test) ; filtrée sur un assureur, elle suit sa rétention.
3. glob `resources/js/components/aphaspb/**` — titre « PenaltyTrendCard : clés d'URL préfixées penalty_ » — note : `chart` est déjà pris par le graphique voisin des deux tableaux de bord ; la carte filtre côté navigateur, la page Journal côté serveur (`showInsurerFilter=false`).

- [ ] **Step 3 : vérification complète**

Run : `composer ci:check`
Attendu : lint, format, PHPStan, Pint (projet entier) et toute la suite verts. Corriger ce qui rougit dans la tâche concernée (nouveau commit `fix: …`), jamais en désactivant un contrôle.

- [ ] **Step 4 : commit**

```bash
git add docs/superpowers/specs/2026-09-27-journal-penalites-design.md .ai/rules
git commit -m "docs: réconcilier la spec du journal des pénalités et consigner ses règles

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```
