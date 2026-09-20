# Pénalités de retard — lot B — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire passer le calcul de pénalité à l'échelle du réseau, ajouter quatre colonnes agrégées à l'export réseau, et donner une page par assureur aux deux PDF.

**Architecture :** Le cœur de `PenaltyCalculator` bascule sur des numéros de jour entiers (100× plus rapide, 8× plus léger) sans changer son API Carbon. Le délai le plus long se calcule entièrement en SQL. Un agrégateur réseau dédié reçoit **les identifiants d'assureurs déjà autorisés**, ce qui rend le contournement du seuil d'anonymat structurellement impossible.

**Tech Stack :** Laravel 13 · PHP 8.4 · Pest · dompdf · OpenSpout · SQLite (tests) / MySQL (prod)

**Spec :** `docs/superpowers/specs/2026-09-20-penalites-de-retard-lot-b-design.md`

## Global Constraints

- **PHP** : accolades obligatoires même sur un corps d'une ligne ; promotion de propriétés ; types de retour et de paramètres explicites ; PHPDoc plutôt que commentaires en ligne ; formes de tableaux (`array{...}`) dans les PHPDoc.
- **Langue** : suivre celle du fichier voisin. `app/Services/Network/**` et `app/Services/Declarations/**` sont en français ; `app/Support/**` est mixte. Tout texte visible est en français.
- **Anonymat** : le seuil (`SettingsRepository::anonymityMinPharmacies()`, plancher 2) est décidé **une seule fois**, dans `NetworkStatsService::perInsurer()`. Aucune classe de ce lot ne le réévalue ; elles reçoivent les identifiants déjà autorisés.
- **`private_note` ne franchit jamais un chemin réseau.** Toute lecture de `app/Services/Network/**` passe par le query builder, jamais par Eloquent.
- **Pénalité** : tranche de 30 jours, non composée, base dégressive, acquise conservée. `intdiv($base * $rateBp, 10_000)`, jamais de flottant.
- **`null` ≠ `0`** : `null` = « pas de convention » (rendu « — »), `0` = « une convention, rien à réclamer ».
- **Colonnes d'export** : référencées par leur nom via `array_search()` sur `COLUMNS`, **jamais par leur index**.
- **dompdf** : ni flexbox ni grid — les colonnes sont des `<table>` —, `page-break-inside: avoid` sur les lignes, en-tête et pied en `position: fixed`.
- **Formatage** : `vendor/bin/pint --dirty --format agent` après toute modification PHP.
- **Vérification finale** : `composer ci:check` en entier.

### Deux corrections portées par ce plan

La spec (§7) justifie l'absence de page pour un assureur sous le seuil en disant qu'une page de tirets « dirait au lecteur quels assureurs sont peu déclarés ». **C'est faux** : `NetworkExportRows::withheld()` publie déjà ce nombre, délibérément, pour que l'absence de chiffre ne se lise pas comme une absence de déclarations. La décision reste la bonne, mais pour une raison plus simple : **il n'y a rien à mettre sur cette page**, et la ligne de retenue existante dit déjà ce qu'il faut. C'est cette raison qui va dans le code.

La spec (§8) demande aussi un test vérifiant que « le nom d'un assureur masqué
est absent de tout le document ». **C'est l'inverse de ce que le code fait, et
délibérément** : la ligne de retenue le nomme, pour que son absence de chiffres
ne se lise pas comme une absence de déclarations. Le plan teste donc la bonne
propriété — pas de **page**, pas de **chiffre** — et laisse le nom où il est.

---

## File Structure

**Créés**

| Fichier | Responsabilité |
|---|---|
| `app/Support/DayNumber.php` | Convertir une date en numéro de jour, et rien d'autre |
| `app/Services/Network/DeclarationWindow.php` | Le filtre période + ville, extrait pour ne pas être dupliqué |
| `app/Data/InsurerPenaltyFigures.php` | Pénalité et pire retard d'un assureur |
| `app/Services/Network/InsurerPenaltyAggregates.php` | Les deux chiffres, à l'échelle réseau |
| `tests/Unit/Support/DayNumberTest.php` | |
| `tests/Feature/Network/InsurerPenaltyAggregatesTest.php` | |

**Modifiés**

| Fichier | Changement |
|---|---|
| `app/Services/Declarations/PenaltyCalculator.php` | `accruedInDays()` devient le cœur, `accrued()` l'adaptateur |
| `app/Services/Network/NetworkStatsService.php` | `baseQuery()` délègue à `DeclarationWindow` ; `monthlyByInsurer()` nouveau |
| `app/Services/Network/NetworkExportRows.php` | 4 colonnes |
| `app/Services/Network/NetworkPdfExport.php` | pages par assureur |
| `resources/views/exports/network.blade.php` | pages par assureur |
| `app/Services/Pharmacy/PharmacyPdfExport.php` | pages par assureur |
| `resources/views/exports/pharmacy.blade.php` | pages par assureur |
| `tests/Feature/Admin/NetworkExportTest.php` | |
| `tests/Feature/Pharmacy/PharmacyExportTest.php` | |

---

### Task 1 : `DayNumber`

**Files:**
- Create: `app/Support/DayNumber.php`
- Test: `tests/Unit/Support/DayNumberTest.php`

**Interfaces:**
- Consumes: rien.
- Produces:
  - `DayNumber::fromDate(string $date): int`
  - `DayNumber::fromCarbon(CarbonInterface $moment): int`
  - `DayNumber::today(): int`

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Unit/Support/DayNumberTest.php` :

```php
<?php

use App\Support\DayNumber;
use Carbon\CarbonImmutable;

test('a day number counts whole days from the epoch', function () {
    expect(DayNumber::fromDate('1970-01-01'))->toBe(0)
        ->and(DayNumber::fromDate('1970-01-02'))->toBe(1);
});

test('dates before the epoch count backwards', function () {
    // intdiv() tronque vers zéro et rendrait 0 ici : il faut floor().
    expect(DayNumber::fromDate('1969-12-31'))->toBe(-1)
        ->and(DayNumber::fromDate('1969-12-30'))->toBe(-2);
});

test('the difference of two day numbers matches Carbon to the day', function () {
    $anchor = CarbonImmutable::create(2024, 1, 1);

    // Quatre ans jour par jour : deux 29 février, tous les changements de mois,
    // et des écarts dans les deux sens. C'est la propriété dont dépend tout le
    // calcul de pénalité.
    for ($offset = -400; $offset <= 1000; $offset++) {
        $other = $anchor->addDays($offset);

        expect(DayNumber::fromDate($other->format('Y-m-d')) - DayNumber::fromDate($anchor->format('Y-m-d')))
            ->toBe((int) $anchor->diffInDays($other, absolute: false));
    }
});

test('a leap day is one day after the twenty-eighth', function () {
    expect(DayNumber::fromDate('2024-02-29') - DayNumber::fromDate('2024-02-28'))->toBe(1)
        ->and(DayNumber::fromDate('2024-03-01') - DayNumber::fromDate('2024-02-29'))->toBe(1);
});

test('fromCarbon reads the calendar date, not the timestamp', function () {
    // Le projet tourne en UTC, mais si app.timezone changeait, un
    // getTimestamp() / 86400 décalerait toutes les dates d'un jour une partie
    // de l'année, sans que rien ne rougisse.
    config(['app.timezone' => 'Pacific/Kiritimati']);
    date_default_timezone_set('Pacific/Kiritimati');

    $moment = CarbonImmutable::create(2026, 5, 1, 2, 0, 0);

    expect(DayNumber::fromCarbon($moment))->toBe(DayNumber::fromDate('2026-05-01'));

    date_default_timezone_set('UTC');
});

test('today follows the test clock', function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 20));

    expect(DayNumber::today())->toBe(DayNumber::fromDate('2026-09-20'));

    $this->travelTo(CarbonImmutable::create(2027, 1, 15));

    expect(DayNumber::today())->toBe(DayNumber::fromDate('2027-01-15'));
});

test('an unparseable date is refused rather than silently zero', function () {
    expect(fn () => DayNumber::fromDate('pas une date'))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `vendor/bin/pest tests/Unit/Support/DayNumberTest.php`
Expected : FAIL — `Class "App\Support\DayNumber" not found`

- [ ] **Step 3 : Écrire `DayNumber`**

```php
<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Une date réduite à son numéro de jour, compté depuis le 1er janvier 1970.
 *
 * Le calcul de pénalité compare et incrémente des dates des dizaines de
 * milliers de fois par export réseau. Mesuré sur 40 000 déclarations : 4 323 ms
 * et 273 Mo avec des objets CarbonImmutable, 42 ms et 34 Mo avec des entiers.
 * Le goulot n'est pas l'arithmétique, c'est l'allocation d'objets.
 *
 * Deux dates converties ici se soustraient exactement comme diffInDays() les
 * compare — c'est la seule propriété dont le reste dépend, et un test la
 * vérifie sur quatre années jour par jour.
 */
class DayNumber
{
    /** Secondes dans une journée. */
    protected const SECONDS_PER_DAY = 86400;

    /**
     * Le numéro de jour d'une date au format `Y-m-d`.
     *
     * Ancrée sur UTC explicitement : sans ce suffixe, la conversion suivrait le
     * fuseau du processus et deux dates identiques rendraient deux numéros
     * différents selon la machine.
     */
    public static function fromDate(string $date): int
    {
        $timestamp = strtotime($date.' UTC');

        if ($timestamp === false) {
            throw new InvalidArgumentException("Date illisible : {$date}");
        }

        // floor() et non intdiv() : ce dernier tronque vers zéro, ce qui
        // rendrait le même numéro pour le 31/12/1969 et le 01/01/1970.
        return (int) floor($timestamp / self::SECONDS_PER_DAY);
    }

    /**
     * Le numéro de jour d'un instant Carbon.
     *
     * Passe par la date formatée et non par l'horodatage : `app.timezone` vaut
     * UTC aujourd'hui, mais s'il changeait, un `getTimestamp() / 86400`
     * décalerait toutes les dates d'un jour une partie de l'année, en silence.
     */
    public static function fromCarbon(CarbonInterface $moment): int
    {
        return self::fromDate($moment->format('Y-m-d'));
    }

    /**
     * Aujourd'hui.
     *
     * Dérivé de CarbonImmutable::now() et non de time(), sans quoi travelTo()
     * cesserait de piloter les tests et toute la suite « la pénalité croît avec
     * le temps » deviendrait fausse sans rougir.
     */
    public static function today(): int
    {
        return self::fromCarbon(CarbonImmutable::now());
    }
}
```

- [ ] **Step 4 : Lancer les tests et vérifier qu'ils passent**

Run : `vendor/bin/pest tests/Unit/Support/DayNumberTest.php`
Expected : PASS (7 tests)

- [ ] **Step 5 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/DayNumber.php tests/Unit/Support/DayNumberTest.php
git commit -m "feat: réduire une date à son numéro de jour

Le calcul de pénalité compare des dates des dizaines de milliers de fois
par export réseau. Mesuré sur 40 000 déclarations : 4 323 ms avec des
objets Carbon, 42 ms avec des entiers.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2 : Le cœur de `PenaltyCalculator` en entiers

**Files:**
- Modify: `app/Services/Declarations/PenaltyCalculator.php`
- Test: `tests/Unit/Services/PenaltyCalculatorTest.php` (**un seul test ajouté, aucun modifié**)

**Interfaces:**
- Consumes: `DayNumber::fromCarbon()`, `DayNumber::today()` (Task 1) ; `Insurer::PENALTY_TRANCHE_DAYS`.
- Produces: `PenaltyCalculator::accruedInDays(int $amountInvoiced, int $amountReceived, int $depositedDay, ?int $paidDay, int $triggerDays, int $rateBp, array $payments): int` où `$payments` est `list<array{0: int, 1: int}>` — montant, numéro de jour.

> **Le contrat de non-régression** : les **17 tests existants de `PenaltyCalculatorTest` ne doivent pas être touchés**, et tous rester verts. S'il faut en modifier un, le refactor a changé le comportement et pas seulement la représentation — c'est un échec à signaler, pas un test à ajuster.

- [ ] **Step 1 : Écrire le test d'équivalence entre les deux portes**

Ajouter à la fin de `tests/Unit/Services/PenaltyCalculatorTest.php` :

```php
test('both entry points agree on every case the Carbon one covers', function () {
    $cases = [
        // [facturé, encaissé, dépôt, versements[amount, date]]
        [1_000_000, 0, '2026-05-22', []],
        [1_000_000, 400_000, '2026-05-22', [[400_000, '2026-08-01']]],
        [1_000_000, 1_000_000, '2026-05-22', [[1_000_000, '2026-08-25']]],
        [1_000_000, 1_050_000, '2026-05-01', [[1_000_000, '2026-06-15'], [50_000, '2026-09-01']]],
        [500_000, 500_000, '2026-09-19', [[500_000, '2026-09-19']]],
    ];

    foreach ($cases as [$invoiced, $received, $deposited, $payments]) {
        $paidOn = $payments === [] ? null : CarbonImmutable::parse(end($payments)[1]);

        $viaCarbon = $this->calculator->accrued(
            amountInvoiced: $invoiced,
            amountReceived: $received,
            depositedOn: CarbonImmutable::parse($deposited),
            paidOn: $paidOn,
            triggerDays: 60,
            rateBp: 200,
            payments: array_map(
                fn (array $p): array => ['amount' => $p[0], 'paid_on' => CarbonImmutable::parse($p[1])],
                $payments,
            ),
        );

        $viaDays = $this->calculator->accruedInDays(
            amountInvoiced: $invoiced,
            amountReceived: $received,
            depositedDay: DayNumber::fromDate($deposited),
            paidDay: $paidOn === null ? null : DayNumber::fromCarbon($paidOn),
            triggerDays: 60,
            rateBp: 200,
            payments: array_map(
                fn (array $p): array => [$p[0], DayNumber::fromDate($p[1])],
                $payments,
            ),
        );

        expect($viaDays)->toBe($viaCarbon);
    }
});
```

Ajouter l'import `use App\Support\DayNumber;` en tête du fichier.

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `vendor/bin/pest tests/Unit/Services/PenaltyCalculatorTest.php`
Expected : FAIL — `Call to undefined method App\Services\Declarations\PenaltyCalculator::accruedInDays()`

- [ ] **Step 3 : Remplacer `accrued()` par un adaptateur et écrire le cœur**

Dans `app/Services/Declarations/PenaltyCalculator.php`, remplacer `accrued()`, `clockStopsOn()` et `receivedBy()` — les deux dernières disparaissent, repliées dans le cœur — par :

```php
    /**
     * L'algorithme, sur des dates Carbon.
     *
     * Porte d'entrée du chemin officine, qui traite des centaines de lignes et
     * n'a aucune raison de convertir ses dates à la main. Le réseau, lui, passe
     * directement par accruedInDays() : convertir 160 000 dates en Carbon y
     * coûterait 380 ms contre 99 en entiers.
     *
     * @param  list<array{amount: int, paid_on: CarbonImmutable}>  $payments
     */
    public function accrued(
        int $amountInvoiced,
        int $amountReceived,
        CarbonImmutable $depositedOn,
        ?CarbonImmutable $paidOn,
        int $triggerDays,
        int $rateBp,
        array $payments,
    ): int {
        return $this->accruedInDays(
            amountInvoiced: $amountInvoiced,
            amountReceived: $amountReceived,
            depositedDay: DayNumber::fromCarbon($depositedOn),
            paidDay: $paidOn === null ? null : DayNumber::fromCarbon($paidOn),
            triggerDays: $triggerDays,
            rateBp: $rateBp,
            payments: array_map(
                fn (array $payment): array => [
                    $payment['amount'],
                    DayNumber::fromCarbon($payment['paid_on']),
                ],
                $payments,
            ),
        );
    }

    /**
     * Le cœur : aucun objet date, que des entiers.
     *
     * Chaque versement est une paire `[montant, numéro de jour]`, volontairement
     * indexée plutôt que nommée : ce tableau est construit des dizaines de
     * milliers de fois par export réseau, et des clés de chaîne y coûteraient
     * plus que le calcul lui-même.
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
    ): int {
        // Un mois entièrement soldé cesse de courir au dernier versement, et ce
        // qu'il avait accumulé lui reste acquis. Un mois qui doit encore quelque
        // chose court jusqu'à aujourd'hui.
        $end = $amountReceived < $amountInvoiced ? DayNumber::today() : $paidDay;

        if ($end === null) {
            return 0;
        }

        $total = 0;
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

            // Les versements ne font que s'ajouter : une base retombée à zéro
            // ne peut plus remonter, donc les tranches suivantes ne
            // factureraient rien. Sortir plutôt que continuer est une
            // optimisation, pas une règle — le total est le même dans les deux
            // cas, et c'est pourquoi aucun test ne peut les distinguer.
            if ($base <= 0) {
                break;
            }

            $total += intdiv($base * $rateBp, 10_000);
            $tranche += Insurer::PENALTY_TRANCHE_DAYS;
        }

        return $total;
    }
```

Ajouter l'import `use App\Support\DayNumber;`. Retirer l'import `Carbon\CarbonImmutable` **seulement si** plus rien ne l'utilise — `accrued()` le garde dans sa signature, donc il reste.

- [ ] **Step 4 : Lancer les tests et vérifier qu'ils passent, tous**

Run : `vendor/bin/pest tests/Unit/Services/PenaltyCalculatorTest.php`
Expected : PASS (18 tests — les 17 d'origine **inchangés**, plus l'équivalence)

Run : `php artisan test --compact tests/Feature/Declarations tests/Feature/Pharmacy`
Expected : PASS — le chemin officine passe par `accrued()`, qui doit être resté exact.

- [ ] **Step 5 : Vérifier que le gain est réel**

Run :

```bash
php artisan tinker --execute '
$c = app(App\Services\Declarations\PenaltyCalculator::class);
Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::create(2026, 9, 19));
$today = App\Support\DayNumber::today();
$rows = [];
for ($i = 0; $i < 40000; $i++) {
    $dep = $today - (30 + ($i % 700));
    $pays = [];
    for ($k = 0; $k < 1 + ($i % 3); $k++) { $pays[] = [200000, $dep + 20 + $k * 40]; }
    $rows[] = [$dep, $pays, 1000000, 200000 * count($pays)];
}
$t = microtime(true);
foreach ($rows as [$dep, $pays, $inv, $rec]) {
    $c->accruedInDays($inv, $rec, $dep, $rec >= $inv ? $pays[count($pays)-1][1] : null, 60, 200, $pays);
}
printf("40 000 declarations : %.0f ms, crete %.0f Mo\n", (microtime(true)-$t)*1000, memory_get_peak_usage(true)/1048576);
'
```

Expected : de l'ordre de **50 ms et 100 Mo**. Si le temps dépasse la seconde, une conversion Carbon subsiste dans la boucle — la chercher avant de continuer.

- [ ] **Step 6 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Declarations/PenaltyCalculator.php tests/Unit/Services/PenaltyCalculatorTest.php
git commit -m "perf: calculer la pénalité sur des entiers plutôt que des Carbon

accruedInDays() devient le cœur, accrued() son adaptateur. Les 17 tests
du lot A passent sans une retouche : la représentation change, pas le
comportement.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3 : Extraire le filtre période + ville

**Files:**
- Create: `app/Services/Network/DeclarationWindow.php`
- Modify: `app/Services/Network/NetworkStatsService.php`

**Interfaces:**
- Consumes: `App\Data\Period`.
- Produces:
  - `DeclarationWindow::query(Period $from, Period $to, ?string $city = null): Builder` — `DB::table('declarations')` filtré
  - `DeclarationWindow::apply(Builder $query, Period $from, Period $to, ?string $city = null): Builder` — le même filtre posé sur une requête qui joint déjà `declarations`

> **Pourquoi extraire** : la tâche 4 doit filtrer `declaration_payments` joint à `declarations` sur la même période et la même ville. Recopier le `whereExists` sur `pharmacies` mettrait **deux copies du filtre qui décide quelles officines entrent dans un agrégat réseau** — exactement le genre de duplication qui dérive et finit par faire dire deux choses différentes à deux écrans.

- [ ] **Step 1 : S'appuyer sur les tests existants comme filet**

Aucun test neuf : cette tâche ne change aucun comportement. Le filet est la suite réseau, qui couvre déjà le filtre de ville et les bornes de période.

Run : `php artisan test --compact tests/Feature/Admin tests/Feature/Network 2>/dev/null || php artisan test --compact tests/Feature/Admin`
Expected : PASS — noter le nombre de tests, il doit être identique à la fin.

- [ ] **Step 2 : Écrire `DeclarationWindow`**

```php
<?php

namespace App\Services\Network;

use App\Data\Period;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Quelles déclarations un agrégat réseau a le droit de voir.
 *
 * Extrait de NetworkStatsService parce que plusieurs requêtes en ont besoin —
 * dont une qui part de `declaration_payments` et joint `declarations`. Deux
 * copies du filtre qui décide quelles officines entrent dans un agrégat
 * finiraient par diverger, et l'écart ne se verrait que le jour où deux écrans
 * afficheraient deux chiffres.
 *
 * Les colonnes sont qualifiées de leur table : ce même filtre se pose sur des
 * requêtes jointes, où `period_year` seul serait ambigu.
 */
class DeclarationWindow
{
    /**
     * Les déclarations de la période, éventuellement d'une seule ville.
     */
    public function query(Period $from, Period $to, ?string $city = null): Builder
    {
        return $this->apply(DB::table('declarations'), $from, $to, $city);
    }

    /**
     * Le même filtre, posé sur une requête qui joint déjà `declarations`.
     */
    public function apply(Builder $query, Period $from, Period $to, ?string $city = null): Builder
    {
        return $query
            ->whereRaw(
                '(declarations.period_year * 12 + declarations.period_month) BETWEEN ? AND ?',
                [$from->toOrdinal(), $to->toOrdinal()],
            )
            ->when($city, fn (Builder $inner, string $filtered) => $inner->whereExists(
                fn (Builder $sub) => $sub->from('pharmacies')
                    ->whereColumn('pharmacies.id', 'declarations.pharmacy_id')
                    ->where('pharmacies.city', $filtered),
            ));
    }
}
```

- [ ] **Step 3 : Faire déléguer `NetworkStatsService`**

Injecter la fenêtre :

```php
    public function __construct(
        protected SettingsRepository $settings,
        protected DeclarationWindow $window,
    ) {
        //
    }
```

Remplacer le corps de `baseQuery()` :

```php
    /**
     * Le socle de tout agrégat réseau : la période, et la ville s'il y en a une.
     *
     * Délègue à DeclarationWindow, que partagent les requêtes parties d'une
     * autre table — voir InsurerPenaltyAggregates.
     */
    protected function baseQuery(Period $from, Period $to, ?string $city = null): Builder
    {
        return $this->window->query($from, $to, $city);
    }
```

- [ ] **Step 4 : Lancer la suite réseau**

Run : `php artisan test --compact tests/Feature/Admin`
Expected : PASS, **exactement le même nombre de tests qu'à l'étape 1**. Une colonne devenue ambiguë sortirait ici.

- [ ] **Step 5 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Network/DeclarationWindow.php app/Services/Network/NetworkStatsService.php
git commit -m "refactor: extraire le filtre période et ville des agrégats réseau

Une requête du lot B part de declaration_payments et joint declarations.
Deux copies du filtre qui décide quelles officines entrent dans un
agrégat finiraient par diverger.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4 : `InsurerPenaltyAggregates`

**Files:**
- Create: `app/Data/InsurerPenaltyFigures.php`
- Create: `app/Services/Network/InsurerPenaltyAggregates.php`
- Test: `tests/Feature/Network/InsurerPenaltyAggregatesTest.php`

**Interfaces:**
- Consumes: `DayNumber` (Task 1), `PenaltyCalculator::accruedInDays()` (Task 2), `DeclarationWindow` (Task 3).
- Produces:
  - `App\Data\InsurerPenaltyFigures` — readonly, `?int $penalty`, `?int $longestDelayDays`
  - `InsurerPenaltyAggregates::forInsurers(array $insurerIds, Period $from, Period $to, ?string $city = null): array` — `array<int, InsurerPenaltyFigures>`, une entrée par identifiant demandé

> **Quatre requêtes**, pas trois comme l'annonce la spec §5 : les clauses des assureurs, l'agrégat de délai, les versements, puis le curseur des déclarations. Le nombre est **constant**, c'est ce qui compte et c'est ce que le test vérifie.

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Network/InsurerPenaltyAggregatesTest.php` :

```php
<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Declarations\LongestDelay;
use App\Services\Network\InsurerPenaltyAggregates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->aggregates = app(InsurerPenaltyAggregates::class);
    $this->bounds = [new Period(2025, 10), new Period(2026, 9)];
});

/** Une déclaration d'une officine neuve, pour que chaque ligne compte une officine. */
function networkDeclare(Insurer $insurer, int $month, array $attributes = []): Declaration
{
    return Declaration::factory()->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => $month,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'delay_days' => 20,
        ...$attributes,
    ]);
}

test('an insurer with no clause has no penalty but still has a longest delay', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    networkDeclare($insurer, 7, ['delay_days' => 44]);

    $figures = $this->aggregates->forInsurers([$insurer->id], ...$this->bounds);

    expect($figures[$insurer->id]->penalty)->toBeNull()
        ->and($figures[$insurer->id]->longestDelayDays)->toBe(44);
});

test('an insurer under a clause with nothing accrued reads zero, not null', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 90, ratePercent: 2.0)->create();
    // Réglée en 20 jours : la pénalité ne se déclenche qu'au 90e.
    networkDeclare($insurer, 8);

    expect($this->aggregates->forInsurers([$insurer->id], ...$this->bounds)[$insurer->id]->penalty)
        ->toBe(0);
});

test('the penalty sums across the officines of one insurer', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();

    // Deux officines, chacune une facture de 1 000 000 déposée il y a 120
    // jours et jamais réglée : trois tranches à 20 000 chacune, deux fois.
    foreach ([1, 2] as $month) {
        networkDeclare($insurer, $month, [
            'amount_received' => 0,
            'status' => DeclarationStatus::Unpaid,
            'is_status_manual' => true,
            'invoice_deposited_on' => CarbonImmutable::create(2026, 9, 19)->subDays(120),
            'paid_on' => null,
            'delay_days' => null,
        ]);
    }

    expect($this->aggregates->forInsurers([$insurer->id], ...$this->bounds)[$insurer->id]->penalty)
        ->toBe(120_000);
});

test('the longest delay agrees with the per-declaration implementation', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    // Un mois soldé tard, un partiellement réglé qui traîne, un impayé, un
    // rejeté : les quatre cas que LongestDelay distingue.
    networkDeclare($insurer, 3, ['delay_days' => 55]);

    Declaration::factory()->instalments([['amount' => 100_000, 'paid_on' => '2026-01-10']])->create([
        'pharmacy_id' => Pharmacy::factory(),
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 1,
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-01-01',
    ]);

    networkDeclare($insurer, 4, [
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 9, 19)->subDays(150),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    networkDeclare($insurer, 5, [
        'amount_received' => 0,
        'status' => DeclarationStatus::Rejected,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 9, 19)->subDays(400),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $viaSql = $this->aggregates->forInsurers([$insurer->id], ...$this->bounds)[$insurer->id]->longestDelayDays;

    $viaDeclarations = app(LongestDelay::class)->for(
        Declaration::query()->where('insurer_id', $insurer->id)->get(),
    );

    // 261 jours pour la partielle du 1er janvier : c'est elle qui gagne, pas
    // les 55 du mois soldé ni les 400 du rejeté, qui ne compte pas.
    expect($viaSql)->toBe($viaDeclarations)
        ->and($viaSql)->toBe(261);
});

test('an insurer absent from the allowed list is absent from the result', function () {
    $allowed = Insurer::factory()->withPenalty()->create();
    $hidden = Insurer::factory()->withPenalty()->create();

    networkDeclare($allowed, 7);
    networkDeclare($hidden, 7);

    $figures = $this->aggregates->forInsurers([$allowed->id], ...$this->bounds);

    expect($figures)->toHaveKey($allowed->id)
        ->and($figures)->not->toHaveKey($hidden->id);
});

test('an insurer with no declaration at all still gets an entry', function () {
    $withClause = Insurer::factory()->withPenalty()->create();
    $without = Insurer::factory()->create();

    $figures = $this->aggregates->forInsurers([$withClause->id, $without->id], ...$this->bounds);

    expect($figures[$withClause->id]->penalty)->toBe(0)
        ->and($figures[$withClause->id]->longestDelayDays)->toBeNull()
        ->and($figures[$without->id]->penalty)->toBeNull()
        ->and($figures[$without->id]->longestDelayDays)->toBeNull();
});

test('a period outside the window contributes nothing', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    networkDeclare($insurer, 1, ['delay_days' => 300]);

    $figures = $this->aggregates->forInsurers([$insurer->id], new Period(2026, 6), new Period(2026, 9));

    expect($figures[$insurer->id]->longestDelayDays)->toBeNull();
});

test('the query count stays flat however many declarations there are', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();

    networkDeclare($insurer, 1);
    DB::enableQueryLog();
    $this->aggregates->forInsurers([$insurer->id], ...$this->bounds);
    $withOne = count(DB::getQueryLog());

    foreach (range(2, 12) as $month) {
        networkDeclare($insurer, $month);
    }

    DB::flushQueryLog();
    $this->aggregates->forInsurers([$insurer->id], ...$this->bounds);

    // Les clauses, l'agrégat de délai, les versements, le curseur : quatre,
    // quel que soit le volume. Un whereIn sur les identifiants ferait exploser
    // ce compte le jour où il reviendrait.
    expect(count(DB::getQueryLog()))->toBe($withOne)
        ->and($withOne)->toBe(4);
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Network/InsurerPenaltyAggregatesTest.php`
Expected : FAIL — `Target class [App\Services\Network\InsurerPenaltyAggregates] does not exist.`

- [ ] **Step 3 : Écrire le DTO**

Créer `app/Data/InsurerPenaltyFigures.php` :

```php
<?php

namespace App\Data;

/**
 * Ce qu'un assureur doit au réseau en plus, et depuis combien de temps.
 *
 * Aucune propriété n'identifie une officine : ces deux chiffres agrègent au
 * moins le seuil d'anonymat d'entre elles, parce que l'agrégateur ne reçoit que
 * des assureurs déjà autorisés par NetworkStatsService::perInsurer().
 */
readonly class InsurerPenaltyFigures
{
    public function __construct(
        /** Null faute de convention ; zéro quand la convention n'a rien produit. */
        public ?int $penalty,
        /** Le pire retard constaté, soldé ou non ; null si rien n'a été déclaré. */
        public ?int $longestDelayDays,
    ) {
        //
    }
}
```

- [ ] **Step 4 : Écrire l'agrégateur**

Créer `app/Services/Network/InsurerPenaltyAggregates.php` :

```php
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
        $rejected = DeclarationStatus::Rejected->value;

        $rows = $this->window->query($from, $to, $city)
            ->whereIn('declarations.insurer_id', $insurerIds)
            ->select('declarations.insurer_id')
            ->selectRaw("MAX(CASE WHEN declarations.status != '{$rejected}' AND declarations.amount_invoiced <= declarations.amount_received THEN declarations.delay_days END) as worst_settled")
            ->selectRaw("MIN(CASE WHEN declarations.status != '{$rejected}' AND declarations.amount_invoiced > declarations.amount_received AND declarations.invoice_deposited_on IS NOT NULL THEN declarations.invoice_deposited_on END) as oldest_open")
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
        $rejected = DeclarationStatus::Rejected->value;
        $totals = [];

        $declarations = $this->window->query($from, $to, $city)
            ->whereIn('declarations.insurer_id', $insurerIds)
            ->where('declarations.status', '!=', $rejected)
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
```

- [ ] **Step 5 : Lancer les tests**

Run : `php artisan test --compact tests/Feature/Network/InsurerPenaltyAggregatesTest.php`
Expected : PASS (8 tests)

Si « the query count stays flat » échoue en annonçant 5 requêtes au lieu de 4, `cursor()` a été remplacé par `get()` quelque part, ou une requête de clauses est faite deux fois.

- [ ] **Step 6 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/InsurerPenaltyFigures.php app/Services/Network/InsurerPenaltyAggregates.php tests/Feature/Network
git commit -m "feat: agréger pénalité et pire retard à l'échelle du réseau

Le pire retard se calcule entièrement en SQL — deux agrégats, aucune
boucle. Seule la pénalité en garde une, restreinte aux assureurs sous
convention.

L'anonymat est structurel : la méthode reçoit les identifiants déjà
autorisés, elle n'a pas la liberté de contourner le seuil.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5 : Les quatre colonnes de l'export réseau

**Files:**
- Modify: `app/Services/Network/NetworkExportRows.php`
- Test: `tests/Feature/Admin/NetworkExportTest.php`

**Interfaces:**
- Consumes: `InsurerPenaltyAggregates::forInsurers()` (Task 4).
- Produces: `NetworkExportRows::COLUMNS` porte `delai_le_plus_long_jours`, `delai_declenchement_penalite_jours`, `taux_penalite_pct`, `penalite_potentielle_fcfa`.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Admin/NetworkExportTest.php` :

```php
/**
 * Le CSV téléchargé, parsé, en-tête compris.
 *
 * @return list<list<string>>
 */
function networkCsvRows(): array
{
    $body = str_replace("\xEF\xBB\xBF", '', downloadCsv());

    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\n", trim($body))),
    );
}

test('the csv carries the longest delay and the potential penalty', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'NSIA', 'standard_delay_days' => 30]);

    // Deux officines — le seuil d'anonymat vaut 2 —, chacune une facture de
    // 1 000 000 déposée il y a 120 jours et jamais réglée : trois tranches à
    // 20 000, deux fois, et un retard de 120 jours.
    exportDeclare($insurer, 2, [
        'amount_received' => 0,
        'status' => App\Enums\DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $rows = networkCsvRows();
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('delai_le_plus_long_jours'))->toBe('120')
        ->and($cell('delai_declenchement_penalite_jours'))->toBe('60')
        ->and($cell('taux_penalite_pct'))->toBe('2')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('120000');
});

test('an insurer without a clause leaves the penalty cells empty', function () {
    // Soldée, sinon c'est l'âge de l'encours qui l'emporte sur delay_days —
    // et exportDeclare() laisse 300 000 impayés par défaut.
    exportDeclare(Insurer::factory()->create(['standard_delay_days' => 30]), 2, [
        'amount_received' => 1_000_000,
        'delay_days' => 44,
    ]);

    $rows = networkCsvRows();
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('delai_le_plus_long_jours'))->toBe('44')
        ->and($cell('delai_declenchement_penalite_jours'))->toBe('')
        ->and($cell('taux_penalite_pct'))->toBe('')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('');
});

test('an insurer under the anonymity threshold gets no penalty figure either', function () {
    $hidden = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'Petit Assureur']);

    // Une seule officine : sous le seuil de 2.
    exportDeclare($hidden, 1, [
        'amount_received' => 0,
        'status' => App\Enums\DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $rows = networkCsvRows();
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('assureur'))->toBe('Petit Assureur')
        ->and($cell('delai_le_plus_long_jours'))->toBe('')
        ->and($cell('penalite_potentielle_fcfa'))->toBe('');
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Admin/NetworkExportTest.php`
Expected : FAIL — `array_search()` rend `false`, la cellule lue est la colonne 0

- [ ] **Step 3 : Étendre `NetworkExportRows`**

Injecter l'agrégateur :

```php
    public function __construct(
        protected NetworkStatsService $stats,
        protected InsurerPenaltyAggregates $penalties,
    ) {
        //
    }
```

Dans `COLUMNS`, insérer :

```php
        'delai_moyen_pondere_jours',
        'delai_le_plus_long_jours',
        'delai_standard_jours',
        'delai_declenchement_penalite_jours',
        'taux_penalite_pct',
        'part_sous_seuil_pct',
```

et, tout à la fin, après `'taux_recouvrement_pct'` :

```php
        'penalite_potentielle_fcfa',
```

Dans `rows()`, après le calcul de `$amounts`, résoudre les chiffres de pénalité **pour les seuls assureurs déjà autorisés** :

```php
        // Les identifiants passés ici sont ceux que perInsurer() a laissé
        // passer : l'agrégateur n'a pas la liberté de contourner le seuil.
        $allowed = array_keys(array_filter(
            $indicators,
            fn ($entry): bool => $entry instanceof InsurerIndicators,
        ));

        $penalties = $this->penalties->forInsurers($allowed, $from, $to, $city);
```

Dans `full()`, ajouter le paramètre et les cellules. La signature devient :

```php
    protected function full(
        string $name,
        InsurerIndicators $entry,
        InsurerAmounts $amount,
        InsurerPenaltyFigures $figures,
    ): array {
        return [
            $name,
            $entry->declaringPharmacies,
            $entry->declarations,
            $entry->averageDelayDays,
            $entry->weightedDelayDays,
            $figures->longestDelayDays,
            $entry->standardDelayDays,
            // Le délai et le taux accompagnent le montant pour le rendre
            // vérifiable : cet export finit dans un courrier adressé à
            // l'assureur, où le chiffre doit pouvoir être refait.
            $entry->penaltyTriggerDays,
            $entry->penaltyRatePercent,
            $entry->withinThresholdShare,
            $entry->recoveredWithinDelayShare,
            $entry->instalments,
            $entry->instalmentsPerDeclaration,
            $entry->multiInstalmentShare,
            $entry->averageFirstInstalmentDelayDays,
            $entry->rejectionRate,
            $entry->unpaidRate,
            $amount->invoiced,
            $amount->received,
            $amount->outstanding,
            $amount->recoveryRate,
            $figures->penalty,
        ];
    }
```

et l'appel devient `yield $this->full($name, $entry, $amount, $penalties[$insurerId] ?? new InsurerPenaltyFigures(null, null));`.

Ajouter les imports `App\Data\InsurerPenaltyFigures` et `App\Services\Network\InsurerPenaltyAggregates` (même espace de noms pour le second — pas d'import).

- [ ] **Step 4 : Porter la clause sur `InsurerIndicators`**

`full()` lit `$entry->penaltyTriggerDays` et `$entry->penaltyRatePercent`, qui n'existent pas encore. Les ajouter au DTO, après `standardDelayDays` :

```php
        /** Le jour où la pénalité mord, ou null faute de convention. */
        public ?int $penaltyTriggerDays,
        /** Le taux par tranche de 30 jours, en pourcent, ou null. */
        public ?float $penaltyRatePercent,
```

Dans `NetworkStatsService::perInsurer()`, la requête sélectionne déjà `insurers.standard_delay_days` ; ajouter les deux colonnes au `select` et au `groupBy`, puis les passer au constructeur :

```php
            ->select('insurer_id', 'insurers.standard_delay_days', 'insurers.penalty_trigger_days', 'insurers.penalty_rate_bp')
            ...
            ->groupBy('insurer_id', 'insurers.standard_delay_days', 'insurers.penalty_trigger_days', 'insurers.penalty_rate_bp')
```

```php
                penaltyTriggerDays: $row->penalty_trigger_days === null ? null : (int) $row->penalty_trigger_days,
                penaltyRatePercent: $row->penalty_rate_bp === null ? null : (float) ((int) $row->penalty_rate_bp / 100),
```

Ce sont deux colonnes SQL de plus, pas une boucle : `maskedInsurerCount()` reste aussi rapide qu'avant.

- [ ] **Step 5 : Lancer les tests**

Run : `php artisan test --compact tests/Feature/Admin`
Expected : PASS — y compris `NetworkStatsTest` et `NetworkXlsxExportTest`, qui lisent `COLUMNS` par nom

- [ ] **Step 6 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Network app/Data/InsurerIndicators.php tests/Feature/Admin/NetworkExportTest.php
git commit -m "feat: chiffrer pénalité et pire retard dans l'export réseau

Quatre colonnes et non deux : un montant de pénalité sans le taux qui
l'a produit est invérifiable dans un tableur, et cet export finit dans
un courrier adressé à l'assureur.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6 : L'agrégat mensuel par assureur

**Files:**
- Modify: `app/Services/Network/NetworkStatsService.php`
- Test: `tests/Feature/Admin/NetworkStatsTest.php`

**Interfaces:**
- Consumes: `DeclarationWindow` (Task 3).
- Produces: `NetworkStatsService::monthlyByInsurer(array $insurerIds, Period $from, Period $to, ?string $city = null): array` — `array<int, list<array{year: int, month: int, monthLabel: string, declarations: int, invoiced: int, received: int, outstanding: int, averageDelayDays: float|null}>>`, du plus récent au plus ancien.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Admin/NetworkStatsTest.php` :

```php
test('the monthly breakdown gives one row per month an insurer was declared to', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    foreach ([6, 7] as $month) {
        Pharmacy::factory()->count(2)->create()->each(
            fn (Pharmacy $pharmacy) => Declaration::factory()->create([
                'pharmacy_id' => $pharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => $month,
                'amount_invoiced' => 1_000_000,
                'amount_received' => 600_000,
                'delay_days' => 40,
            ]),
        );
    }

    $monthly = app(NetworkStatsService::class)->monthlyByInsurer(
        [$insurer->id],
        new Period(2026, 1),
        new Period(2026, 8),
    );

    expect($monthly[$insurer->id])->toHaveCount(2)
        // Le plus récent en tête : un rapport se lit du haut.
        ->and($monthly[$insurer->id][0]['month'])->toBe(7)
        ->and($monthly[$insurer->id][0]['declarations'])->toBe(2)
        ->and($monthly[$insurer->id][0]['invoiced'])->toBe(2_000_000)
        ->and($monthly[$insurer->id][0]['received'])->toBe(1_200_000)
        ->and($monthly[$insurer->id][0]['outstanding'])->toBe(800_000)
        ->and($monthly[$insurer->id][0]['averageDelayDays'])->toBe(40.0);
});

test('the monthly breakdown ignores insurers not asked for', function () {
    $asked = Insurer::factory()->create();
    $other = Insurer::factory()->create();

    foreach ([$asked, $other] as $insurer) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 7,
            'amount_invoiced' => 500_000,
            'amount_received' => 500_000,
            'delay_days' => 10,
        ]);
    }

    $monthly = app(NetworkStatsService::class)->monthlyByInsurer(
        [$asked->id],
        new Period(2026, 1),
        new Period(2026, 8),
    );

    expect($monthly)->toHaveKey($asked->id)
        ->and($monthly)->not->toHaveKey($other->id);
});

test('an empty list of insurers costs no query at all', function () {
    DB::enableQueryLog();

    expect(app(NetworkStatsService::class)->monthlyByInsurer([], new Period(2026, 1), new Period(2026, 8)))
        ->toBe([])
        ->and(DB::getQueryLog())->toBeEmpty();
});
```

Ajouter les imports manquants en tête du fichier : `use Illuminate\Support\Facades\DB;`.

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Admin/NetworkStatsTest.php`
Expected : FAIL — `Call to undefined method ...::monthlyByInsurer()`

- [ ] **Step 3 : Écrire la méthode**

Ajouter à `NetworkStatsService`, après `delayTrend()` :

```php
    /**
     * Le détail mois par mois des assureurs demandés, le plus récent en tête.
     *
     * Alimente les pages par assureur du rapport PDF. Voisine de delayTrend()
     * sans la remplacer : celle-là ne rend qu'une moyenne de délai parce
     * qu'elle alimente un graphique, et lui ajouter quatre colonnes ferait
     * payer ce poids à l'écran des tendances.
     *
     * Les identifiants passés sont ceux que perInsurer() a déjà autorisés : le
     * seuil d'anonymat n'est pas réévalué ici, il est en amont.
     *
     * @param  list<int>  $insurerIds
     * @return array<int, list<array{year: int, month: int, monthLabel: string, declarations: int, invoiced: int, received: int, outstanding: int, averageDelayDays: float|null}>>
     */
    public function monthlyByInsurer(array $insurerIds, Period $from, Period $to, ?string $city = null): array
    {
        if ($insurerIds === []) {
            return [];
        }

        $rows = $this->baseQuery($from, $to, $city)
            ->whereIn('declarations.insurer_id', $insurerIds)
            ->select('declarations.insurer_id', 'declarations.period_year', 'declarations.period_month')
            ->selectRaw('COUNT(*) as declarations')
            ->selectRaw('SUM(declarations.amount_invoiced) as invoiced')
            ->selectRaw('SUM(declarations.amount_received) as received')
            ->selectRaw("AVG(CASE WHEN declarations.status IN ('paid', 'partial') THEN declarations.delay_days END) as average_delay")
            ->groupBy('declarations.insurer_id', 'declarations.period_year', 'declarations.period_month')
            ->orderByDesc('declarations.period_year')
            ->orderByDesc('declarations.period_month')
            ->get();

        $monthly = [];

        foreach ($rows as $row) {
            $invoiced = (int) $row->invoiced;
            $received = (int) $row->received;

            $monthly[(int) $row->insurer_id][] = [
                'year' => (int) $row->period_year,
                'month' => (int) $row->period_month,
                'monthLabel' => MonthLabel::short((int) $row->period_month, (int) $row->period_year),
                'declarations' => (int) $row->declarations,
                'invoiced' => $invoiced,
                'received' => $received,
                'outstanding' => max(0, $invoiced - $received),
                'averageDelayDays' => $row->average_delay === null ? null : round((float) $row->average_delay, 1),
            ];
        }

        return $monthly;
    }
```

Ajouter l'import `use App\Support\MonthLabel;`.

- [ ] **Step 4 : Lancer les tests**

Run : `php artisan test --compact tests/Feature/Admin/NetworkStatsTest.php`
Expected : PASS

- [ ] **Step 5 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Network/NetworkStatsService.php tests/Feature/Admin/NetworkStatsTest.php
git commit -m "feat: détailler mois par mois les assureurs autorisés

Alimente les pages par assureur du rapport PDF. Voisine de delayTrend()
sans la remplacer : celle-là n'a qu'une moyenne parce qu'elle sert un
graphique.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7 : Une page par assureur dans le PDF réseau

**Files:**
- Modify: `app/Services/Network/NetworkPdfExport.php`
- Modify: `resources/views/exports/network.blade.php`
- Test: `tests/Feature/Admin/NetworkExportTest.php`

**Interfaces:**
- Consumes: `InsurerPenaltyAggregates::forInsurers()` (Task 4), `NetworkStatsService::monthlyByInsurer()` (Task 6).
- Produces: la clé `rows[N]['figures']` (`InsurerPenaltyFigures`) et `rows[N]['monthly']` (`list<array{...}>`) dans les données de vue.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Admin/NetworkExportTest.php` :

```php
test('the report gives a page to each insurer above the threshold', function () {
    $shown = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'NSIA Assurances']);
    $hidden = Insurer::factory()->create(['name' => 'Petit Assureur']);

    // Soldées : sans cela l'âge de l'encours l'emporterait sur delay_days.
    exportDeclare($shown, 2, ['amount_received' => 1_000_000, 'delay_days' => 44]);
    exportDeclare($hidden, 1, ['amount_received' => 1_000_000, 'delay_days' => 10]);

    $export = app(NetworkPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, new Period(2026, 8), new Period(2026, 8), null);

    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['name'])->toBe('NSIA Assurances')
        ->and($payload['rows'][0]['figures']->longestDelayDays)->toBe(44)
        ->and($payload['rows'][0])->toHaveKey('monthly')
        ->and($payload['rows'][0]['monthly'])->toHaveCount(1)
        // L'assureur sous le seuil n'a pas de page : il n'est pas dans `rows`.
        // Son nom reste dans la liste de retenue, et c'est voulu — voir plus bas.
        ->and($payload['withheld'])->toHaveCount(1)
        ->and($payload['withheld'][0]['name'])->toBe('Petit Assureur');
});

test('a page never contradicts the recap line above it', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'NSIA Assurances']);

    exportDeclare($insurer, 2, [
        'amount_received' => 0,
        'status' => App\Enums\DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $export = app(NetworkPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, new Period(2026, 4), new Period(2026, 8), null);

    // La page et la ligne du récapitulatif sortent du même appel : le test le
    // prouve plutôt que de l'espérer.
    $fromMonthly = array_sum(array_column($payload['rows'][0]['monthly'], 'outstanding'));

    expect($fromMonthly)->toBe($payload['rows'][0]['amounts']->outstanding);
});

test('the rendered report names each insurer above the threshold once per page', function () {
    $shown = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    exportDeclare($shown, 2, ['amount_received' => 1_000_000, 'delay_days' => 44]);

    $this->actingAs($this->admin)
        ->get(route('admin.csv-exports.download', ['format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Admin/NetworkExportTest.php`
Expected : FAIL — `Failed asserting that an array has the key 'figures'`

- [ ] **Step 3 : Étendre `NetworkPdfExport::data()`**

Injecter les deux collaborateurs :

```php
    public function __construct(
        protected NetworkStatsService $stats,
        protected SettingsRepository $settings,
        protected InsurerPenaltyAggregates $penalties,
    ) {
        //
    }
```

Dans `data()`, après la boucle qui remplit `$rows` et `$withheld`, et **avant** le `usort` :

```php
        // Seuls les assureurs de $rows ont franchi le seuil : c'est cette
        // liste, et pas les indicateurs bruts, qui est passée aux agrégats.
        $allowed = array_map(fn (array $row): int => $row['insurerId'], $rows);

        $figures = $this->penalties->forInsurers($allowed, $from, $to, $city);
        $monthly = $this->stats->monthlyByInsurer($allowed, $from, $to, $city);

        foreach ($rows as $index => $row) {
            $rows[$index]['figures'] = $figures[$row['insurerId']]
                ?? new InsurerPenaltyFigures(null, null);
            $rows[$index]['monthly'] = $monthly[$row['insurerId']] ?? [];
        }
```

Ce code lit `$row['insurerId']`, que la boucle ne pose pas encore. L'ajouter :

```php
            $rows[] = [
                'insurerId' => $insurerId,
                'name' => $name,
                'indicators' => $entry,
                'amounts' => $amount instanceof InsurerAmounts ? $amount : null,
            ];
```

Ajouter l'import `use App\Data\InsurerPenaltyFigures;`.

- [ ] **Step 4 : Écrire les pages dans la vue**

Dans `resources/views/exports/network.blade.php`, ajouter au `<style>` :

```css
    .insurer-page { page-break-before: always; }
    .insurer-page h2 { margin-bottom: 2px; }
    .convention {
        margin: 0 0 10px;
        color: #445050;
        font-size: 8px;
    }
```

Puis, **après la `</div>` qui ferme la section « Assureur par assureur »**, à la fin du fichier :

```blade
@foreach ($rows as $row)
    @php($indicators = $row['indicators'])
    @php($figures = $row['figures'])

    <div class="insurer-page">
        <h2>{{ $row['name'] }}</h2>

        {{-- Sans la convention, la pénalité imprimée plus bas est un nombre
             sans provenance. --}}
        <p class="convention">
            Remboursement convenu à {{ $indicators->standardDelayDays }} jours.
            @if ($indicators->penaltyTriggerDays !== null && $indicators->penaltyRatePercent !== null)
                Pénalité à partir de {{ $indicators->penaltyTriggerDays }} jours,
                {{ number_format($indicators->penaltyRatePercent, 2, ',', ' ') }} % par tranche de 30 jours.
            @else
                Aucune clause de pénalité enregistrée pour cet assureur.
            @endif
        </p>

        <table class="kpis">
            <tr>
                <td>
                    <div class="value">
                        {{ $figures->longestDelayDays === null ? '—' : $figures->longestDelayDays }}<span class="unit"> j</span>
                    </div>
                    <div class="label">Délai le plus long</div>
                </td>
                <td>
                    <div class="value">
                        {{ $indicators->weightedDelayDays === null ? '—' : number_format($indicators->weightedDelayDays, 1, ',', ' ') }}<span class="unit"> j</span>
                    </div>
                    <div class="label">Délai moyen pondéré</div>
                </td>
                <td>
                    <div class="value">
                        {{ $row['amounts'] === null ? '—' : \App\Support\Fcfa::format($row['amounts']->outstanding) }}
                    </div>
                    <div class="label">Reste dû au réseau</div>
                </td>
                <td>
                    {{-- Un tiret dit « pas de convention », un zéro dit « une
                         convention, rien à réclamer ». --}}
                    <div class="value">
                        {{ $figures->penalty === null ? '—' : \App\Support\Fcfa::format($figures->penalty) }}
                    </div>
                    <div class="label">Pénalité potentielle</div>
                </td>
            </tr>
        </table>

        <table class="grid">
            <thead>
                <tr>
                    <th class="text">Mois</th>
                    <th>Décl.</th>
                    <th>Facturé</th>
                    <th>Encaissé</th>
                    <th>Reste dû</th>
                    <th>Délai moyen</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($row['monthly'] as $month)
                    <tr>
                        <td class="text">{{ $month['monthLabel'] }}</td>
                        <td>{{ $month['declarations'] }}</td>
                        <td>{{ \App\Support\Fcfa::format($month['invoiced']) }}</td>
                        <td>{{ \App\Support\Fcfa::format($month['received']) }}</td>
                        <td>{{ \App\Support\Fcfa::format($month['outstanding']) }}</td>
                        <td class="{{ $month['averageDelayDays'] !== null && $month['averageDelayDays'] > $indicators->standardDelayDays ? 'late' : '' }}">
                            {{ $month['averageDelayDays'] === null ? '—' : number_format($month['averageDelayDays'], 1, ',', ' ').' j' }}
                        </td>
                    </tr>
                @endforeach

                @if (count($row['monthly']) === 0)
                    <tr><td class="text" colspan="6">Aucune déclaration sur la période retenue.</td></tr>
                @endif
            </tbody>
        </table>
    </div>
@endforeach
```

> **Aucune page pour un assureur sous le seuil**, et la raison est simple : il n'y a rien à y mettre. La ligne de retenue du tableau récapitulatif dit déjà son nom, son nombre d'officines et pourquoi ses chiffres manquent — une page entière de tirets n'ajouterait que du bruit.

- [ ] **Step 5 : Vérifier le rendu**

Run : `php artisan test --compact tests/Feature/Admin/NetworkExportTest.php`
Expected : PASS

Puis générer un PDF de contrôle et vérifier que chaque assureur ouvre bien une page :

```bash
php artisan tinker --execute '
$pdf = app(App\Services\Network\NetworkPdfExport::class)->document(new App\Data\Period(2025, 10), new App\Data\Period(2026, 9));
file_put_contents("/tmp/reseau.pdf", $pdf->output());
echo "OK\n";'
pdftotext -layout /tmp/reseau.pdf - | head -80
```

Expected : le récapitulatif, puis un bloc par assureur. Si deux assureurs partagent une page, `page-break-before` n'a pas été appliqué à la bonne `div`.

- [ ] **Step 6 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Network/NetworkPdfExport.php resources/views/exports/network.blade.php tests/Feature/Admin/NetworkExportTest.php
git commit -m "feat: donner une page à chaque assureur du rapport réseau

Convention rappelée en tête, quatre indicateurs, puis le détail mois par
mois. Un assureur sous le seuil n'a pas de page : il n'y aurait rien à y
mettre, et sa ligne de retenue dit déjà ce qu'il faut.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8 : Une page par assureur dans le PDF officine

**Files:**
- Modify: `app/Services/Pharmacy/PharmacyPdfExport.php`
- Modify: `resources/views/exports/pharmacy.blade.php`
- Test: `tests/Feature/Pharmacy/PharmacyExportTest.php`

**Interfaces:**
- Consumes: `PenaltyCalculator::for()`, `LongestDelay::for()` — déjà injectés dans `PharmacyPdfExport` depuis le lot A. Ajouter `use App\Support\MonthLabel;` en tête du fichier.
- Produces: la clé `insurerPages` dans les données de vue — `list<array{name: string, standardDelayDays: int, penaltyTriggerDays: ?int, penaltyRatePercent: ?float, longestDelayDays: ?int, penalty: ?int, invoiced: int, received: int, outstanding: int, months: list<Declaration>}>`.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Pharmacy/PharmacyExportTest.php` :

```php
test('the officine report gives a page to each of its insurers', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    $insurer->update(['penalty_trigger_days' => 10, 'penalty_rate_bp' => 200]);

    $other = Insurer::factory()->create(['name' => 'SUNU Assurances', 'standard_delay_days' => 30]);
    $pharmacy->insurers()->attach($other);

    declareSplit($pharmacy, $insurer);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $other->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 500_000,
        'amount_received' => 500_000,
        'delay_days' => 12,
    ]);

    $export = app(PharmacyPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, $pharmacy, new Period(2025, 9), new Period(2026, 8), null);

    expect($payload['insurerPages'])->toHaveCount(2);

    $pages = collect($payload['insurerPages'])->keyBy('name');

    expect($pages['NSIA Assurances']['penalty'])->toBe(12_000)
        ->and($pages['NSIA Assurances']['longestDelayDays'])->toBe(25)
        ->and($pages['NSIA Assurances']['months'])->toHaveCount(1)
        ->and($pages['SUNU Assurances']['penalty'])->toBeNull()
        ->and($pages['SUNU Assurances']['longestDelayDays'])->toBe(12);
});

test('the pages agree with the summary table they follow', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    $insurer->update(['penalty_trigger_days' => 10, 'penalty_rate_bp' => 200]);

    declareSplit($pharmacy, $insurer);

    $export = app(PharmacyPdfExport::class);
    $reflected = new ReflectionMethod($export, 'data');
    $payload = $reflected->invoke($export, $pharmacy, new Period(2025, 9), new Period(2026, 8), null);

    // Les pages et la synthèse sortent de la même collection : elles ne
    // peuvent pas diverger, et ce test le prouve plutôt que de l'espérer.
    expect($payload['insurerPages'][0]['penalty'])->toBe($payload['perInsurer'][0]['penalty'])
        ->and($payload['insurerPages'][0]['longestDelayDays'])->toBe($payload['perInsurer'][0]['longestDelayDays'])
        ->and($payload['insurerPages'][0]['outstanding'])->toBe($payload['perInsurer'][0]['outstanding']);
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Pharmacy/PharmacyExportTest.php`
Expected : FAIL — `Undefined array key "insurerPages"`

- [ ] **Step 3 : Construire les pages**

Dans `PharmacyPdfExport::data()`, ajouter au tableau retourné :

```php
            'insurerPages' => $this->insurerPages($declarations),
```

Et la méthode, après `perInsurer()` :

```php
    /**
     * Une page par assureur, avec ses seuls mois.
     *
     * Bâtie sur la même collection que perInsurer() et que la table de détail :
     * les trois vues du même fichier ne peuvent pas se contredire, et un test
     * compare une page à la ligne de synthèse qui la précède.
     *
     * @param  Collection<int, Declaration>  $declarations
     * @return list<array<string, mixed>>
     */
    protected function insurerPages(Collection $declarations): array
    {
        // MonthLabel est importé en tête du fichier.
        $pages = $declarations->groupBy('insurer_id')->map(function (Collection $group): array {
            $insurer = $group->first()->insurer;
            $invoiced = (int) $group->sum('amount_invoiced');
            $received = (int) $group->sum('amount_received');

            return [
                'name' => $insurer->name,
                'standardDelayDays' => $insurer->standard_delay_days,
                'penaltyTriggerDays' => $insurer->penalty_trigger_days,
                'penaltyRatePercent' => $insurer->penaltyRatePercent(),
                'longestDelayDays' => $this->longestDelay->for($group),
                'penalty' => $this->penalties->total($group),
                'invoiced' => $invoiced,
                'received' => $received,
                'outstanding' => max(0, $invoiced - $received),
                // Aplati ici et non dans la vue : une vue Blade ne doit pas
                // appeler un service, et la pénalité de chaque mois demande le
                // calculateur. Du plus récent au plus ancien, comme la table
                // de détail globale.
                'months' => array_values($group->sortByDesc(
                    fn (Declaration $one): int => $one->period_year * 12 + $one->period_month,
                )->map(fn (Declaration $one): array => [
                    'monthLabel' => MonthLabel::short($one->period_month, $one->period_year),
                    'statusLabel' => $one->status->label(),
                    'invoiced' => $one->amount_invoiced,
                    'received' => $one->amount_received,
                    'outstanding' => $one->amount_outstanding,
                    'depositedOn' => $one->invoice_deposited_on?->toDateString(),
                    'delayDays' => $one->delay_days,
                    'penalty' => $this->penalties->for($one),
                ])->all()),
            ];
        })->values();

        // array_values() plutôt que celui de la collection : ce qui sort est
        // déclaré list, et seule une réindexation le prouve.
        return array_values($pages->sortByDesc(fn (array $page): int => $page['outstanding'])->all());
    }
```

- [ ] **Step 4 : Écrire les pages dans la vue**

Dans `resources/views/exports/pharmacy.blade.php`, ajouter au `<style>` :

```css
    .insurer-page { page-break-before: always; }
    .insurer-page h2 { margin-bottom: 2px; }
    .convention {
        margin: 0 0 10px;
        color: #445050;
        font-size: 8px;
    }
```

Puis, **tout à la fin du fichier**, après la table de détail globale :

```blade
@foreach ($insurerPages as $page)
    <div class="insurer-page">
        <h2>{{ $page['name'] }}</h2>

        <p class="convention">
            Remboursement convenu à {{ $page['standardDelayDays'] }} jours.
            @if ($page['penaltyTriggerDays'] !== null && $page['penaltyRatePercent'] !== null)
                Pénalité à partir de {{ $page['penaltyTriggerDays'] }} jours,
                {{ number_format($page['penaltyRatePercent'], 2, ',', ' ') }} % par tranche de 30 jours.
            @else
                Aucune clause de pénalité enregistrée pour cet assureur.
            @endif
        </p>

        <table class="kpis">
            <tr>
                <td>
                    <div class="value">{{ \App\Support\Fcfa::format($page['outstanding']) }}</div>
                    <div class="label">Reste dû</div>
                </td>
                <td>
                    <div class="value">
                        {{ $page['longestDelayDays'] === null ? '—' : $page['longestDelayDays'] }}<span class="unit"> j</span>
                    </div>
                    <div class="label">Délai le plus long</div>
                </td>
                <td>
                    <div class="value">
                        {{ $page['penalty'] === null ? '—' : \App\Support\Fcfa::format($page['penalty']) }}
                    </div>
                    <div class="label">Pénalité réclamable</div>
                </td>
            </tr>
        </table>

        <table class="grid">
            <thead>
                <tr>
                    <th class="text">Mois</th>
                    <th class="text">Statut</th>
                    <th>Facturé</th>
                    <th>Encaissé</th>
                    <th>Reste dû</th>
                    <th class="text">Dépôt</th>
                    <th>Délai</th>
                    <th>Pénalité</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($page['months'] as $month)
                    <tr>
                        <td class="text">{{ $month['monthLabel'] }}</td>
                        <td class="text">{{ $month['statusLabel'] }}</td>
                        <td>{{ \App\Support\Fcfa::format($month['invoiced']) }}</td>
                        <td>{{ \App\Support\Fcfa::format($month['received']) }}</td>
                        <td>{{ \App\Support\Fcfa::format($month['outstanding']) }}</td>
                        <td class="text">{{ $month['depositedOn'] ?? '—' }}</td>
                        <td class="{{ $month['delayDays'] !== null && $month['delayDays'] > $page['standardDelayDays'] ? 'late' : '' }}">
                            {{ $month['delayDays'] === null ? '—' : $month['delayDays'].' j' }}
                        </td>
                        {{-- Un tiret dit « pas de convention », un zéro dit
                             « une convention, rien à réclamer ». --}}
                        <td>{{ $month['penalty'] === null ? '—' : \App\Support\Fcfa::format($month['penalty']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach
```

- [ ] **Step 5 : Lancer les tests et contrôler le rendu**

Run : `php artisan test --compact tests/Feature/Pharmacy/PharmacyExportTest.php`
Expected : PASS

```bash
php artisan tinker --execute '
$p = App\Models\Pharmacy::first();
$pdf = app(App\Services\Pharmacy\PharmacyPdfExport::class)->document($p, new App\Data\Period(2025, 9), new App\Data\Period(2026, 9));
file_put_contents("/tmp/officine.pdf", $pdf->output());
echo "OK\n";'
pdftotext -layout /tmp/officine.pdf - | tail -60
```

Expected : la table de détail globale, puis un bloc par assureur.

- [ ] **Step 6 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Pharmacy/PharmacyPdfExport.php resources/views/exports/pharmacy.blade.php tests/Feature/Pharmacy/PharmacyExportTest.php
git commit -m "feat: donner une page à chaque assureur du rapport officine

Le détail global reste à sa place : les pages s'y ajoutent. Les deux
sortent de la même collection, et un test compare une page à la ligne de
synthèse qui la précède.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9 : Clôture

- [ ] **Step 1 : Lancer la chaîne complète**

Run : `composer ci:check`
Expected : PASS sur les six étapes. `pint --parallel --test` scanne tout le projet, là où `--dirty` ne voit que le diff : des fichiers committés plus tôt peuvent rester non formatés. Si `format:check` échoue, lancer `npm run format`.

- [ ] **Step 2 : Vérifier que le lot A n'a pas bougé**

Run : `vendor/bin/pest tests/Unit/Services/PenaltyCalculatorTest.php tests/Unit/Services/LongestDelayTest.php`
Expected : PASS — et `git diff d124ef3 -- tests/Unit/Services/LongestDelayTest.php` doit être **vide**. Une seule ligne de différence sur les tests du lot A signifie que le refactor a changé le comportement.

- [ ] **Step 3 : Contrôle négatif sur chaque garde neuve**

Casser, lancer, rétablir. Chacune doit rougir :

| Mutation | Test attendu rouge |
|---|---|
| `DayNumber::fromDate` : `floor()` → `intdiv()` | « dates before the epoch count backwards » |
| `DayNumber::fromCarbon` : `format('Y-m-d')` → `getTimestamp()/86400` | « fromCarbon reads the calendar date » |
| `DayNumber::today` : `CarbonImmutable::now()` → `time()` | « today follows the test clock » |
| `accruedInDays` : `$day <= $tranche` → `<` | « both entry points agree » + les tests du lot A |
| `longestDelays` : retirer la condition `status != 'rejected'` | « the longest delay agrees with the per-declaration implementation » |
| `longestDelays` : `MIN(...)` → `MAX(...)` sur `oldest_open` | idem |
| `forInsurers` : `isset($clauses[...])` → `true` | « an insurer with no clause has no penalty » |
| `instalments()` : `cursor()` → `get()` | aucun — c'est attendu, le compte de requêtes ne bouge pas |
| `NetworkExportRows` : passer `$indicators` entier au lieu des autorisés | « an insurer under the anonymity threshold gets no penalty figure » |

La huitième ligne est là pour être lue : `cursor()` contre `get()` ne change ni le résultat ni le compte de requêtes, seulement la mémoire. Aucun test ne peut les distinguer, et il ne faut pas en écrire un qui prétendrait le faire — c'est la même leçon que le `break` du lot A.

- [ ] **Step 4 : Mesurer sur volume réaliste**

```bash
php artisan tinker --execute '
$t = microtime(true);
$rows = iterator_to_array(app(App\Services\Network\NetworkExportRows::class)->rows(
    new App\Data\Period(2025, 10), new App\Data\Period(2026, 9),
));
printf("export reseau : %.0f ms, %d lignes, crete %.0f Mo\n",
    (microtime(true)-$t)*1000, count($rows), memory_get_peak_usage(true)/1048576);'
```

Expected : sur la base de développement (911 déclarations), de l'ordre de la centaine de millisecondes. Le chiffre sert de repère : il doit rester proportionnel au volume, pas exploser.

- [ ] **Step 5 : Consigner les règles durables**

Avec l'outil `record-rule` de Laravel Boost, jamais dans une note personnelle :

1. glob `app/Support/DayNumber.php` — « Le calcul de pénalité compte en numéros de jour » : pourquoi (4 323 ms → 42 ms, 273 Mo → 34 Mo sur 40 000 déclarations), `floor()` et non `intdiv()` pour les dates antérieures à 1970, `format('Y-m-d')` et non l'horodatage pour être indépendant du fuseau, `CarbonImmutable::now()` et non `time()` pour que `travelTo()` pilote les tests.
2. glob `app/Services/Network/**` — « L'anonymat des agrégats de pénalité est structurel » : `InsurerPenaltyAggregates::forInsurers()` et `NetworkStatsService::monthlyByInsurer()` reçoivent les identifiants **déjà autorisés** par `perInsurer()`. Le seuil n'est pas réévalué, il est en amont, et c'est ce qui rend le contournement impossible plutôt qu'improbable. Ne pas leur faire appeler `anonymityMinPharmacies()`.
3. glob `app/Services/Network/**` — « Le pire retard se calcule en SQL, la pénalité non » : deux agrégats (`MAX(delay_days)` sur les soldés, `MIN(invoice_deposited_on)` sur les encours) suffisent au premier ; les tranches du second sont séquentielles et demandent une boucle, restreinte aux assureurs sous convention. Un test confronte l'agrégat SQL à `LongestDelay` déclaration par déclaration : le garder.

- [ ] **Step 6 : Demander la suite complète**

Demander à l'utilisateur de lancer `php artisan test --compact` en entier et de confirmer avant d'ouvrir la PR.

---

## Ce que ce plan ne fait pas

- **Aucun écran web neuf.** L'espace admin garde ses cinq écrans.
- **`OverduePaymentsService` et `InsurerRelationshipReport` ne changent pas** : ils restent sur `accrued()`, l'API Carbon, qui demeure exacte.
- **Pas de table d'instantanés ni de cache** — écartés en §2 de la spec, et la porte reste ouverte : le cœur en entiers est justement ce qui les rendrait triviaux à remplir.
- **Pas de mention de la pénalité dans les digests e-mail.**
- **`ConsoleNavigation::chaseNotice()` et son horloge à 60 jours** restent en l'état.
