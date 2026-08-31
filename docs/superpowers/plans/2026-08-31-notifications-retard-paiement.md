# Notifications de retard de paiement — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prévenir chaque officine, une fois par semaine, des factures qui ont dépassé le délai convenu avec leur assureur, et donner à l'APhaSPB une vue agrégée du même retard.

**Architecture:** Un service `OverduePaymentsService` répond seul à la question « qu'est-ce qui est en retard », sur la même horloge que les statistiques réseau : `today − invoice_deposited_on > insurers.standard_delay_days`. Une commande hebdomadaire lit ce service et envoie un récapitulatif groupé par officine (`mail` + `database`), plus un digest agrégé par assureur aux admins réseau. Aucune table de suivi : l'anti-doublon est une lecture de la table `notifications` sur la semaine en cours.

**Tech Stack:** Laravel 13, PHP 8.4, Pest, notifications Laravel (canaux `mail` et `database`), file `database`, ordonnanceur déclaré dans `routes/console.php`.

**Spec:** `docs/superpowers/specs/2026-08-31-notifications-retard-paiement-design.md`

## Global Constraints

- **Définition du retard, sans exception :** `amount_invoiced > amount_received` ∧ `status ≠ rejected` ∧ `invoice_deposited_on ≠ null` ∧ `today − invoice_deposited_on > insurers.standard_delay_days`. Seuil **strict**.
- **Le digest admin ne nomme jamais une officine à côté d'un montant.** Agrégat par assureur uniquement, et seuls les assureurs atteignant `SettingsRepository::anonymityMinPharmacies()` officines déclarantes y figurent.
- **Pas d'arithmétique de dates en SQL.** Le projet calcule les âges en PHP ou compare à une constante préparée en PHP (`PharmacyStatsService::outstandingByMonth()` documente pourquoi : lier la requête aux fonctions de dates d'un moteur).
- **Formatage des montants :** `App\Support\Fcfa::format()`, jamais `number_format()` à la main.
- **Langue :** tout le texte visible est en français. Les messages de validation propres à un formulaire vivent dans son FormRequest ; les règles du framework dans `lang/fr/validation.php`.
- **Style :** `vendor/bin/pint --dirty --format agent` après toute modification PHP. Avant de déclarer terminé, `composer ci:check` **en entier**.
- **Tests :** `.ai/rules/tests.md` — ne jamais poser un stub global en `beforeEach`.

---

## File Structure

| Fichier | Responsabilité |
|---|---|
| `app/Data/OverdueLine.php` | Une facture en retard, telle qu'elle s'affiche dans un e-mail |
| `app/Data/InsurerOverdueTotals.php` | L'agrégat réseau d'un assureur |
| `app/Services/Declarations/OverduePaymentsService.php` | L'unique définition du retard |
| `app/Support/MonthLabel.php` | « Août 26 » et « AOÛT 2026 », aujourd'hui dupliqués dans deux contrôleurs |
| `app/Notifications/Declarations/OverduePaymentsDigest.php` | Récapitulatif d'une officine |
| `app/Notifications/Declarations/NetworkOverdueDigest.php` | Digest agrégé des admins |
| `app/Console/Commands/NotifyOverduePayments.php` | Orchestration, anti-doublon, `--dry-run` |

---

### Task 1 : la date de dépôt devient obligatoire

**Files:**
- Modify: `app/Http/Requests/Pharmacy/SaveDeclarationRequest.php`
- Test: `tests/Feature/Pharmacy/DeclarationTest.php`

**Interfaces:**
- Consumes: rien.
- Produces: garantit que toute déclaration enregistrée après ce commit porte `invoice_deposited_on`, ce dont dépend la Task 2.

- [ ] **Step 1 : écrire le test qui échoue**

Ajouter à la fin de `tests/Feature/Pharmacy/DeclarationTest.php` :

```php
test('a declaration without a deposit date is refused, even unpaid', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_received' => 0,
            'paid_on' => null,
            'invoice_deposited_on' => null,
        ]))
        ->assertSessionHasErrors('invoice_deposited_on');
});
```

- [ ] **Step 2 : le lancer et vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Pharmacy/DeclarationTest.php --filter="without a deposit date"`
Expected: FAIL — aucune erreur de session sur `invoice_deposited_on`, la règle actuelle étant `nullable`.

- [ ] **Step 3 : passer la règle en `required`**

Dans `rules()`, remplacer `'nullable'` par `'required'` sur `invoice_deposited_on` :

```php
'invoice_deposited_on' => [
    'required',
    'date',
    'before_or_equal:today',
    ...($this->declaredMonthStart() === null
        ? []
        : ['after_or_equal:'.$this->declaredMonthStart()]),
],
```

- [ ] **Step 4 : retirer la vérification devenue redondante dans `withValidator()`**

Le bloc `if ($status->isSettled())` réclamait la date de dépôt ; `required` s'en charge désormais pour tous les statuts. Remplacer le corps de la closure par :

```php
$validator->after(function (Validator $validator) {
    if ($validator->errors()->hasAny(['amount_invoiced', 'amount_received'])) {
        return;
    }

    $paid = $this->input('paid_on');

    // La date de dépôt est exigée par la règle `required` quel que soit le
    // statut : une facture impayée en a une, c'est le paiement qui manque.
    if ($this->resolvedStatus()->isSettled()) {
        if ($paid === null || $paid === '') {
            $validator->errors()->add('paid_on', 'Indiquez la date de paiement.');
        }

        return;
    }

    if ($paid !== null && $paid !== '') {
        $validator->errors()->add('paid_on', "Une date de paiement n'a de sens que si un paiement a été reçu.");
    }
});
```

- [ ] **Step 5 : conserver le libellé d'erreur d'origine**

Ajouter dans `messages()`, pour que l'officine lise la même phrase qu'avant plutôt que le message générique du framework :

```php
'invoice_deposited_on.required' => 'Indiquez la date de dépôt de la facture.',
```

- [ ] **Step 6 : lancer les tests des déclarations**

Run: `vendor/bin/pest tests/Feature/Pharmacy/ tests/Feature/Declarations/`
Expected: PASS. Si un test échoue en postant sans `invoice_deposited_on`, c'est le changement attendu — corriger le test en ajoutant la date, pas la règle.

- [ ] **Step 7 : Pint puis commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Pharmacy/SaveDeclarationRequest.php tests/Feature/Pharmacy/DeclarationTest.php
git commit -m "feat: exiger la date de dépôt sur toute déclaration

La règle ne l'exigeait que sur les déclarations réglées, si bien qu'une
facture impayée — celle qu'une alerte de retard vise — pouvait n'avoir
aucun retard calculable. Le champ était déjà affiché sur toutes les
déclarations, l'écran ne change pas."
```

---

### Task 2 : `OverduePaymentsService`

**Files:**
- Create: `app/Data/OverdueLine.php`
- Create: `app/Services/Declarations/OverduePaymentsService.php`
- Test: `tests/Feature/Declarations/OverduePaymentsServiceTest.php`

**Interfaces:**
- Consumes: la garantie de la Task 1.
- Produces:
  - `App\Data\OverdueLine` — `readonly`, propriétés publiques `int $declarationId`, `string $insurerName`, `int $periodYear`, `int $periodMonth`, `CarbonImmutable $invoiceDepositedOn`, `int $ageDays`, `int $standardDelayDays`, `int $outstanding`.
  - `OverduePaymentsService::forPharmacy(Pharmacy $pharmacy): array` → `list<OverdueLine>`, la plus ancienne en tête.
  - `OverduePaymentsService::pharmaciesWithOverdue(): Collection` → `Collection<int, Pharmacy>`.

- [ ] **Step 1 : écrire les tests qui échouent**

Créer `tests/Feature/Declarations/OverduePaymentsServiceTest.php` :

```php
<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Declarations\OverduePaymentsService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 31));
    $this->service = app(OverduePaymentsService::class);
    $this->pharmacy = Pharmacy::factory()->create();
});

/**
 * Une déclaration déposée il y a $daysAgo jours, dont il reste quelque chose.
 */
function overdueCandidate(Pharmacy $pharmacy, Insurer $insurer, int $daysAgo, array $attributes = []): Declaration
{
    $deposited = CarbonImmutable::create(2026, 8, 31)->subDays($daysAgo);

    return Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => $deposited->year,
        'period_month' => $deposited->month,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => false,
        'invoice_deposited_on' => $deposited,
        'paid_on' => null,
        'delay_days' => null,
        ...$attributes,
    ]);
}

test('a declaration exactly at the standard delay is not yet overdue', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    overdueCandidate($this->pharmacy, $insurer, 30);

    expect($this->service->forPharmacy($this->pharmacy))->toBeEmpty();
});

test('a declaration one day past the standard delay is overdue', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    overdueCandidate($this->pharmacy, $insurer, 31);

    $lines = $this->service->forPharmacy($this->pharmacy);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->ageDays)->toBe(31)
        ->and($lines[0]->standardDelayDays)->toBe(30)
        ->and($lines[0]->outstanding)->toBe(1_000_000);
});

test('each insurer is judged by its own standard delay', function () {
    $strict = Insurer::factory()->create(['name' => 'Strict', 'standard_delay_days' => 30]);
    $lenient = Insurer::factory()->create(['name' => 'Souple', 'standard_delay_days' => 45]);

    overdueCandidate($this->pharmacy, $strict, 40);
    overdueCandidate($this->pharmacy, $lenient, 40);

    $lines = $this->service->forPharmacy($this->pharmacy);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->insurerName)->toBe('Strict');
});

test('rejected, settled and undated declarations are left out', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    overdueCandidate($this->pharmacy, $insurer, 90, [
        'status' => DeclarationStatus::Rejected,
        'is_status_manual' => true,
    ]);
    overdueCandidate($this->pharmacy, $insurer, 91, [
        'amount_received' => 1_000_000,
        'status' => DeclarationStatus::Paid,
        'paid_on' => CarbonImmutable::create(2026, 8, 30),
    ]);
    overdueCandidate($this->pharmacy, $insurer, 92, ['invoice_deposited_on' => null]);

    expect($this->service->forPharmacy($this->pharmacy))->toBeEmpty();
});

test('lines come back oldest first', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    overdueCandidate($this->pharmacy, $insurer, 40);
    overdueCandidate($this->pharmacy, $insurer, 100);
    overdueCandidate($this->pharmacy, $insurer, 70);

    expect(array_map(
        fn ($line) => $line->ageDays,
        $this->service->forPharmacy($this->pharmacy),
    ))->toBe([100, 70, 40]);
});

test('only officines carrying an overdue invoice come back', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    $quiet = Pharmacy::factory()->create();

    overdueCandidate($this->pharmacy, $insurer, 60);
    overdueCandidate($quiet, $insurer, 10);

    $found = $this->service->pharmaciesWithOverdue();

    expect($found)->toHaveCount(1)
        ->and($found->first()->id)->toBe($this->pharmacy->id);
});
```

- [ ] **Step 2 : les lancer et vérifier qu'ils échouent**

Run: `vendor/bin/pest tests/Feature/Declarations/OverduePaymentsServiceTest.php`
Expected: FAIL — `Class "App\Services\Declarations\OverduePaymentsService" not found`.

- [ ] **Step 3 : créer la classe de données**

```bash
php artisan make:class Data/OverdueLine --no-interaction
```

Puis remplacer son contenu par :

```php
<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Une facture qui a dépassé le délai convenu avec son assureur.
 *
 * L'âge se compte depuis le dépôt, pas depuis la fin du mois déclaré : c'est
 * l'horloge de `delay_days` et de `NetworkStatsService::WITHIN_STANDARD_DELAY_SUM`,
 * et deux horloges feraient dire à l'e-mail et à l'écran réseau deux choses
 * différentes de la même facture.
 */
readonly class OverdueLine
{
    public function __construct(
        public int $declarationId,
        public string $insurerName,
        public int $periodYear,
        public int $periodMonth,
        public CarbonImmutable $invoiceDepositedOn,
        public int $ageDays,
        public int $standardDelayDays,
        public int $outstanding,
    ) {
        //
    }
}
```

- [ ] **Step 4 : créer le service**

```bash
php artisan make:class Services/Declarations/OverduePaymentsService --no-interaction
```

Puis :

```php
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
```

- [ ] **Step 5 : lancer les tests et vérifier qu'ils passent**

Run: `vendor/bin/pest tests/Feature/Declarations/OverduePaymentsServiceTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6 : Pint, PHPStan puis commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress
git add app/Data/OverdueLine.php app/Services/Declarations/OverduePaymentsService.php tests/Feature/Declarations/OverduePaymentsServiceTest.php
git commit -m "feat: définir le retard de paiement en un seul endroit

Le dépassement se teste contre une date butoir par délai standard distinct,
calculée en PHP : comparer une date à un intervalle porté par une colonne
demanderait de l'arithmétique de dates en SQL, que le projet évite."
```

---

### Task 3 : `MonthLabel`, pour ne pas écrire un troisième tableau de mois

**Files:**
- Create: `app/Support/MonthLabel.php`
- Modify: `app/Http/Controllers/Pharmacy/DeclarationHistoryController.php`
- Modify: `app/Http/Controllers/Pharmacy/DeclarationController.php`
- Test: couverture existante (`tests/Feature/Pharmacy/DeclarationHistoryTest.php`, `tests/Feature/Pharmacy/DeclarationTest.php`)

**Interfaces:**
- Produces: `App\Support\MonthLabel::short(int $month, int $year): string` → `« Août 26 »` ; `App\Support\MonthLabel::long(int $month, int $year): string` → `« AOÛT 2026 »`. La Task 4 consomme `short()`.

> Les noms de mois français sont aujourd'hui écrits deux fois, dans
> `DeclarationHistoryController::monthLabel()` et
> `DeclarationController::periodPayload()`. L'e-mail en aurait besoin d'un
> troisième. On extrait avant d'ajouter.

- [ ] **Step 1 : créer la classe**

```bash
php artisan make:class Support/MonthLabel --no-interaction
```

```php
<?php

namespace App\Support;

/**
 * Les noms de mois français, écrits une fois.
 *
 * Deux formes coexistent dans les maquettes : l'en-tête de l'écran de
 * déclaration crie « AOÛT 2026 », le tableau de l'historique abrège en
 * « Août 26 ». Les deux vivent ici plutôt que dans le contrôleur qui les
 * affiche, parce que l'e-mail de relance en veut une troisième copie.
 */
class MonthLabel
{
    /** @var array<int, string> */
    protected const SHORT = [
        1 => 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin',
        'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.',
    ];

    /** @var array<int, string> */
    protected const LONG = [
        1 => 'JANVIER', 'FÉVRIER', 'MARS', 'AVRIL', 'MAI', 'JUIN',
        'JUILLET', 'AOÛT', 'SEPTEMBRE', 'OCTOBRE', 'NOVEMBRE', 'DÉCEMBRE',
    ];

    /** « Août 26 » */
    public static function short(int $month, int $year): string
    {
        return self::SHORT[$month].' '.substr((string) $year, 2);
    }

    /** « AOÛT 2026 » */
    public static function long(int $month, int $year): string
    {
        return self::LONG[$month].' '.$year;
    }
}
```

- [ ] **Step 2 : brancher l'historique**

Dans `DeclarationHistoryController`, ajouter `use App\Support\MonthLabel;`, remplacer l'appel `$this->monthLabel($declaration->period_month, $declaration->period_year)` par `MonthLabel::short($declaration->period_month, $declaration->period_year)`, puis **supprimer** la méthode `monthLabel()`.

- [ ] **Step 3 : brancher l'écran de déclaration**

Dans `DeclarationController`, ajouter `use App\Support\MonthLabel;` et remplacer le corps de `periodPayload()` par :

```php
return [
    'year' => $period->year,
    'month' => $period->month,
    'label' => MonthLabel::long($period->month, $period->year),
];
```

Supprimer le tableau `$months` local.

- [ ] **Step 4 : lancer les tests concernés**

Run: `vendor/bin/pest tests/Feature/Pharmacy/`
Expected: PASS. Les libellés sont couverts par les tests existants — s'ils passent, l'extraction n'a rien changé à l'affichage.

- [ ] **Step 5 : Pint puis commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/MonthLabel.php app/Http/Controllers/Pharmacy/DeclarationHistoryController.php app/Http/Controllers/Pharmacy/DeclarationController.php
git commit -m "refactor: extraire les noms de mois français dans MonthLabel

Ils étaient écrits deux fois ; l'e-mail de relance en voulait une
troisième copie."
```

---

### Task 4 : le récapitulatif hebdomadaire de l'officine

**Files:**
- Create: migration `notifications` (via artisan)
- Create: `app/Notifications/Declarations/OverduePaymentsDigest.php`
- Create: `app/Console/Commands/NotifyOverduePayments.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Declarations/NotifyOverduePaymentsTest.php`

**Interfaces:**
- Consumes: `OverduePaymentsService::forPharmacy()`, `::pharmaciesWithOverdue()` (Task 2) ; `MonthLabel::short()` (Task 3).
- Produces: `OverduePaymentsDigest::__construct(Pharmacy $pharmacy, array $lines)` où `$lines` est `list<OverdueLine>` ; sa charge `database` porte `pharmacy_id`, `lines` et `outstanding`. La commande `declarations:notify-overdue` accepte `--dry-run` et `--force`.

- [ ] **Step 1 : créer la table `notifications`**

```bash
php artisan make:notifications-table --no-interaction
php artisan migrate
```

- [ ] **Step 2 : écrire les tests qui échouent**

Créer `tests/Feature/Declarations/NotifyOverduePaymentsTest.php` :

```php
<?php

use App\Enums\DeclarationStatus;
use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Notifications\Declarations\OverduePaymentsDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 31));
});

/**
 * Une officine, ses membres par rôle, et une facture en retard de $daysAgo jours.
 *
 * @param  array<string, PharmacyRole>  $roles  nom du membre => rôle
 * @return array{0: \App\Models\Pharmacy, 1: \Illuminate\Support\Collection<string, \App\Models\User>}
 */
function officineInArrears(array $roles, int $daysAgo = 60, int $standardDelay = 30): array
{
    $pharmacy = Pharmacy::factory()->create();
    $insurer = Insurer::factory()->create(['standard_delay_days' => $standardDelay]);
    $pharmacy->insurers()->attach($insurer->id);

    $members = collect($roles)->map(function (PharmacyRole $role) use ($pharmacy) {
        $user = User::factory()->notOnboarded()->create();
        $pharmacy->members()->attach($user, ['role' => $role->value]);

        return $user;
    });

    $deposited = CarbonImmutable::create(2026, 8, 31)->subDays($daysAgo);

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => $deposited->year,
        'period_month' => $deposited->month,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => false,
        'invoice_deposited_on' => $deposited,
        'paid_on' => null,
        'delay_days' => null,
    ]);

    return [$pharmacy, $members];
}

test('the digest reaches the owner and the managers, never plain members', function () {
    Notification::fake();

    [, $members] = officineInArrears([
        'titulaire' => PharmacyRole::Owner,
        'gestionnaire' => PharmacyRole::Admin,
        'membre' => PharmacyRole::Member,
    ]);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertSentTo($members['titulaire'], OverduePaymentsDigest::class);
    Notification::assertSentTo($members['gestionnaire'], OverduePaymentsDigest::class);
    Notification::assertNotSentTo($members['membre'], OverduePaymentsDigest::class);
});

test('an officine with several overdue invoices receives one digest, not several', function () {
    Notification::fake();

    [$pharmacy, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);
    $insurer = $pharmacy->insurers()->sole();

    foreach ([70, 80, 90] as $daysAgo) {
        $deposited = CarbonImmutable::create(2026, 8, 31)->subDays($daysAgo);

        Declaration::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => $deposited->year,
            'period_month' => $deposited->month,
            'amount_invoiced' => 500_000,
            'amount_received' => 0,
            'status' => DeclarationStatus::Unpaid,
            'is_status_manual' => false,
            'invoice_deposited_on' => $deposited,
            'paid_on' => null,
            'delay_days' => null,
        ]);
    }

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertSentToTimes($members['titulaire'], OverduePaymentsDigest::class, 1);
});

test('an officine with nothing overdue is left alone', function () {
    Notification::fake();

    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner], daysAgo: 10);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertNothingSentTo($members['titulaire']);
});

test('a second run in the same week sends nothing', function () {
    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::fake();
    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertNothingSentTo($members['titulaire']);
});

test('force overrides the weekly guard', function () {
    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::fake();
    $this->artisan('declarations:notify-overdue', ['--force' => true])->assertSuccessful();

    Notification::assertSentTo($members['titulaire'], OverduePaymentsDigest::class);
});

test('dry run reports without sending', function () {
    Notification::fake();

    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);

    $this->artisan('declarations:notify-overdue', ['--dry-run' => true])
        ->expectsOutputToContain('1 facture')
        ->assertSuccessful();

    Notification::assertNothingSentTo($members['titulaire']);
});

test('the stored digest carries the officine and what it is owed', function () {
    [, $members] = officineInArrears(['titulaire' => PharmacyRole::Owner]);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    $stored = $members['titulaire']->notifications()->sole();

    expect($stored->data['outstanding'])->toBe(1_000_000)
        ->and($stored->data['lines'])->toHaveCount(1);
});
```

- [ ] **Step 3 : les lancer et vérifier qu'ils échouent**

Run: `vendor/bin/pest tests/Feature/Declarations/NotifyOverduePaymentsTest.php`
Expected: FAIL — `The command "declarations:notify-overdue" does not exist.`

- [ ] **Step 4 : écrire la notification**

```bash
php artisan make:notification Declarations/OverduePaymentsDigest --no-interaction
```

```php
<?php

namespace App\Notifications\Declarations;

use App\Data\OverdueLine;
use App\Models\Pharmacy;
use App\Support\Fcfa;
use App\Support\MonthLabel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OverduePaymentsDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<OverdueLine>  $lines  la plus ancienne en tête
     */
    public function __construct(
        public Pharmacy $pharmacy,
        public array $lines,
    ) {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->lines);

        $message = (new MailMessage)
            ->subject(sprintf(
                '%s : %d facture%s au-delà du délai de paiement',
                $this->pharmacy->name,
                $count,
                $count > 1 ? 's' : '',
            ))
            ->line(sprintf(
                'Au %s, %d facture%s de votre officine dépasse%s le délai convenu avec son assureur, pour un total de %s FCFA restant dû.',
                now()->translatedFormat('j F Y'),
                $count,
                $count > 1 ? 's' : '',
                $count > 1 ? 'nt' : '',
                Fcfa::format($this->outstanding()),
            ));

        foreach ($this->lines as $line) {
            $message->line(sprintf(
                '• %s · %s · %d jours écoulés (délai convenu : %d) · %s FCFA dus',
                $line->insurerName,
                MonthLabel::short($line->periodMonth, $line->periodYear),
                $line->ageDays,
                $line->standardDelayDays,
                Fcfa::format($line->outstanding),
            ));
        }

        return $message
            ->action('Ouvrir mon historique', route('pharmacy.history'))
            ->line("Ce récapitulatif est envoyé une fois par semaine tant qu'une facture reste en retard.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_name' => $this->pharmacy->name,
            'outstanding' => $this->outstanding(),
            'lines' => array_map(fn (OverdueLine $line) => [
                'declaration_id' => $line->declarationId,
                'insurer_name' => $line->insurerName,
                'period_label' => MonthLabel::short($line->periodMonth, $line->periodYear),
                'age_days' => $line->ageDays,
                'standard_delay_days' => $line->standardDelayDays,
                'outstanding' => $line->outstanding,
            ], $this->lines),
        ];
    }

    protected function outstanding(): int
    {
        return array_sum(array_map(fn (OverdueLine $line) => $line->outstanding, $this->lines));
    }
}
```

- [ ] **Step 5 : écrire la commande**

```bash
php artisan make:command NotifyOverduePayments --no-interaction
```

```php
<?php

namespace App\Console\Commands;

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Notifications\Declarations\OverduePaymentsDigest;
use App\Services\Declarations\OverduePaymentsService;
use App\Support\Fcfa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Le récapitulatif hebdomadaire des factures en retard.
 *
 * Aucune table de suivi : ce qui empêche un second envoi dans la semaine est
 * une lecture de la table `notifications`. Le choix d'un récapitulatif groupé
 * rend inutile toute mémoire par facture — il n'y a qu'une question binaire,
 * cette officine a-t-elle déjà reçu son digest cette semaine.
 */
class NotifyOverduePayments extends Command
{
    protected $signature = 'declarations:notify-overdue
                            {--dry-run : Affiche ce qui partirait, sans rien envoyer}
                            {--force : Envoie même si un récapitulatif est déjà parti cette semaine}';

    protected $description = 'Prévenir les officines des factures dépassant le délai de leur assureur';

    public function handle(OverduePaymentsService $overdue): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $sent = 0;

        foreach ($overdue->pharmaciesWithOverdue() as $pharmacy) {
            $lines = $overdue->forPharmacy($pharmacy);

            if ($lines === []) {
                continue;
            }

            if (! $force && $this->alreadyNotifiedThisWeek($pharmacy)) {
                $this->line("· {$pharmacy->name} — déjà prévenue cette semaine");

                continue;
            }

            $recipients = $pharmacy->members()
                ->wherePivotIn('role', [PharmacyRole::Owner->value, PharmacyRole::Admin->value])
                ->get();

            $total = array_sum(array_map(fn ($line) => $line->outstanding, $lines));

            $this->line(sprintf(
                '· %s — %d facture%s, %s FCFA, %d destinataire%s',
                $pharmacy->name,
                count($lines),
                count($lines) > 1 ? 's' : '',
                Fcfa::format($total),
                $recipients->count(),
                $recipients->count() > 1 ? 's' : '',
            ));

            if ($dryRun || $recipients->isEmpty()) {
                continue;
            }

            Notification::send($recipients, new OverduePaymentsDigest($pharmacy, $lines));
            $sent++;
        }

        $this->info($dryRun
            ? 'Essai à blanc : rien n’a été envoyé.'
            : "{$sent} récapitulatif(s) envoyé(s).");

        return self::SUCCESS;
    }

    /**
     * Un récapitulatif est-il déjà parti pour cette officine cette semaine ?
     *
     * Limite connue : la notification est `ShouldQueue`, donc la ligne
     * n'apparaît qu'une fois le job traité. Deux exécutions à quelques
     * secondes d'intervalle pourraient doubler l'envoi — ce que la
     * planification hebdomadaire et `withoutOverlapping()` rendent
     * théorique. `--force` existe pour le rattrapage délibéré.
     */
    protected function alreadyNotifiedThisWeek(Pharmacy $pharmacy): bool
    {
        return DB::table('notifications')
            ->where('type', OverduePaymentsDigest::class)
            ->where('data->pharmacy_id', $pharmacy->id)
            ->where('created_at', '>=', now()->startOfWeek())
            ->exists();
    }
}
```

- [ ] **Step 6 : planifier la commande**

Ajouter à la fin de `routes/console.php` :

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('declarations:notify-overdue')
    // Lundi matin, heure du Bénin : app.timezone vaut UTC, et sans zone
    // explicite le récapitulatif arriverait une heure plus tard sur place.
    ->weeklyOn(1, '07:00')
    ->timezone('Africa/Porto-Novo')
    ->withoutOverlapping()
    ->description('Weekly overdue payment digests');
```

- [ ] **Step 7 : lancer les tests et vérifier qu'ils passent**

Run: `vendor/bin/pest tests/Feature/Declarations/NotifyOverduePaymentsTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 8 : Pint, PHPStan puis commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress
git add app/Notifications/Declarations/OverduePaymentsDigest.php app/Console/Commands/NotifyOverduePayments.php routes/console.php database/migrations tests/Feature/Declarations/NotifyOverduePaymentsTest.php
git commit -m "feat: envoyer un récapitulatif hebdomadaire des factures en retard

Un envoi par officine plutôt qu'un par facture : c'est ce qui décide si
l'alerte est lue ou filtrée. L'anti-doublon est une lecture de la table
notifications sur la semaine en cours, sans table de suivi dédiée."
```

---

### Task 5 : le digest agrégé de l'APhaSPB

**Files:**
- Create: `app/Data/InsurerOverdueTotals.php`
- Modify: `app/Services/Declarations/OverduePaymentsService.php`
- Create: `app/Notifications/Declarations/NetworkOverdueDigest.php`
- Modify: `app/Console/Commands/NotifyOverduePayments.php`
- Test: `tests/Feature/Declarations/NetworkOverdueDigestTest.php`

**Interfaces:**
- Consumes: `OverduePaymentsService` (Task 2), la commande (Task 4).
- Produces: `App\Data\InsurerOverdueTotals` — `readonly`, `int $insurerId`, `string $insurerName`, `int $standardDelayDays`, `int $declarations`, `int $pharmacies`, `int $outstanding` ; `OverduePaymentsService::networkTotals(): array` → `list<InsurerOverdueTotals>`, le plus gros encours en tête.

- [ ] **Step 1 : écrire les tests qui échouent**

Créer `tests/Feature/Declarations/NetworkOverdueDigestTest.php` :

```php
<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Notifications\Declarations\NetworkOverdueDigest;
use App\Services\Declarations\OverduePaymentsService;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 31));
    $this->service = app(OverduePaymentsService::class);
});

/**
 * $count officines distinctes, chacune avec une facture en retard chez $insurer.
 */
function officinesInArrearsWith(Insurer $insurer, int $count, int $daysAgo = 60): void
{
    $deposited = CarbonImmutable::create(2026, 8, 31)->subDays($daysAgo);

    foreach (range(1, $count) as $ignored) {
        Declaration::factory()->create([
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => $deposited->year,
            'period_month' => $deposited->month,
            'amount_invoiced' => 1_000_000,
            'amount_received' => 400_000,
            'status' => DeclarationStatus::Partial,
            'is_status_manual' => false,
            'invoice_deposited_on' => $deposited,
            'paid_on' => null,
            'delay_days' => null,
        ]);
    }
}

test('an insurer below the anonymity threshold is left out entirely', function () {
    $minimum = app(SettingsRepository::class)->anonymityMinPharmacies();

    $shy = Insurer::factory()->create(['name' => 'Timide', 'standard_delay_days' => 30]);
    officinesInArrearsWith($shy, $minimum - 1);

    expect($this->service->networkTotals())->toBeEmpty();
});

test('an insurer at the threshold is aggregated', function () {
    $minimum = app(SettingsRepository::class)->anonymityMinPharmacies();

    $insurer = Insurer::factory()->create(['name' => 'Assureur A', 'standard_delay_days' => 30]);
    officinesInArrearsWith($insurer, $minimum);

    $totals = $this->service->networkTotals();

    expect($totals)->toHaveCount(1)
        ->and($totals[0]->insurerName)->toBe('Assureur A')
        ->and($totals[0]->pharmacies)->toBe($minimum)
        ->and($totals[0]->declarations)->toBe($minimum)
        ->and($totals[0]->outstanding)->toBe(600_000 * $minimum);
});

test('the digest payload names no officine', function () {
    Notification::fake();

    $minimum = app(SettingsRepository::class)->anonymityMinPharmacies();
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    officinesInArrearsWith($insurer, $minimum);

    $admin = User::factory()->networkAdmin()->create();

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertSentTo($admin, NetworkOverdueDigest::class, function (NetworkOverdueDigest $digest) use ($admin) {
        $encoded = json_encode($digest->toArray($admin));
        $names = Pharmacy::query()->pluck('name');

        foreach ($names as $name) {
            expect($encoded)->not->toContain($name);
        }

        return true;
    });
});

test('the network digest is not sent when nothing clears the threshold', function () {
    Notification::fake();

    $admin = User::factory()->networkAdmin()->create();
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    officinesInArrearsWith($insurer, 1);

    $this->artisan('declarations:notify-overdue')->assertSuccessful();

    Notification::assertNotSentTo($admin, NetworkOverdueDigest::class);
});
```

- [ ] **Step 2 : les lancer et vérifier qu'ils échouent**

Run: `vendor/bin/pest tests/Feature/Declarations/NetworkOverdueDigestTest.php`
Expected: FAIL — `Call to undefined method ...::networkTotals()`.

- [ ] **Step 3 : créer la classe de données**

```bash
php artisan make:class Data/InsurerOverdueTotals --no-interaction
```

```php
<?php

namespace App\Data;

/**
 * Ce qu'un assureur doit au réseau, tous retards confondus.
 *
 * Aucune propriété ne peut identifier une officine : cet objet ne franchit le
 * seuil d'anonymat que parce qu'il n'agrège rien de nominatif.
 */
readonly class InsurerOverdueTotals
{
    public function __construct(
        public int $insurerId,
        public string $insurerName,
        public int $standardDelayDays,
        public int $declarations,
        public int $pharmacies,
        public int $outstanding,
    ) {
        //
    }
}
```

- [ ] **Step 4 : ajouter `networkTotals()` au service**

Ajouter les `use` nécessaires (`App\Data\InsurerOverdueTotals`, `App\Services\Settings\SettingsRepository`), injecter le dépôt de réglages par le constructeur :

```php
public function __construct(protected SettingsRepository $settings)
{
    //
}
```

puis la méthode :

```php
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
```

- [ ] **Step 5 : écrire la notification réseau**

```bash
php artisan make:notification Declarations/NetworkOverdueDigest --no-interaction
```

```php
<?php

namespace App\Notifications\Declarations;

use App\Data\InsurerOverdueTotals;
use App\Support\Fcfa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le retard du réseau vu par l'APhaSPB.
 *
 * Agrégé par assureur, jamais par officine : le CDC n'autorise l'agrégation
 * qu'au-delà du seuil d'anonymat, et nommer ici une officine à côté d'un
 * montant contournerait cette protection.
 */
class NetworkOverdueDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<InsurerOverdueTotals>  $totals  le plus gros encours en tête
     */
    public function __construct(public array $totals)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Retards de paiement du réseau — '.now()->translatedFormat('j F Y'))
            ->line(sprintf(
                'Au %s, %s FCFA restent dus au réseau au-delà des délais convenus.',
                now()->translatedFormat('j F Y'),
                Fcfa::format($this->outstanding()),
            ));

        foreach ($this->totals as $total) {
            $message->line(sprintf(
                '• %s (délai convenu : %d j) · %d facture%s sur %d officines · %s FCFA',
                $total->insurerName,
                $total->standardDelayDays,
                $total->declarations,
                $total->declarations > 1 ? 's' : '',
                $total->pharmacies,
                Fcfa::format($total->outstanding),
            ));
        }

        return $message
            ->action('Ouvrir l’évolution du réseau', route('admin.trends'))
            ->line("Les assureurs comptant trop peu d'officines déclarantes sont omis, conformément au seuil d'anonymat.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'outstanding' => $this->outstanding(),
            'insurers' => array_map(fn (InsurerOverdueTotals $total) => [
                'insurer_id' => $total->insurerId,
                'insurer_name' => $total->insurerName,
                'standard_delay_days' => $total->standardDelayDays,
                'declarations' => $total->declarations,
                'pharmacies' => $total->pharmacies,
                'outstanding' => $total->outstanding,
            ], $this->totals),
        ];
    }

    protected function outstanding(): int
    {
        return array_sum(array_map(fn (InsurerOverdueTotals $total) => $total->outstanding, $this->totals));
    }
}
```

- [ ] **Step 6 : envoyer le digest depuis la commande**

Ajouter les `use` (`App\Models\User`, `App\Notifications\Declarations\NetworkOverdueDigest`), puis, juste avant le `$this->info(...)` final de `handle()` :

```php
$this->notifyNetworkAdmins($overdue, $dryRun);
```

et la méthode :

```php
/**
 * Le digest agrégé de l'APhaSPB.
 *
 * Les admins ne sont pas un rôle en base mais un groupe Joomla, et la shadow
 * table ne connaît que les comptes déjà connectés au moins une fois : ce
 * digest ne touche que ceux-là, ce qui est une propriété du modèle
 * d'authentification, pas un défaut de cet envoi.
 */
protected function notifyNetworkAdmins(OverduePaymentsService $overdue, bool $dryRun): void
{
    $totals = $overdue->networkTotals();

    if ($totals === []) {
        $this->line('· Réseau — rien au-delà du seuil d’anonymat');

        return;
    }

    $admins = User::query()
        ->where(function ($query) {
            foreach ((array) config('joomla.groups.admin') as $group) {
                $query->orWhereJsonContains('joomla_groups', $group);
            }
        })
        ->get();

    $this->line(sprintf(
        '· Réseau — %d assureur(s), %d destinataire(s)',
        count($totals),
        $admins->count(),
    ));

    if ($dryRun || $admins->isEmpty()) {
        return;
    }

    Notification::send($admins, new NetworkOverdueDigest($totals));
}
```

- [ ] **Step 7 : lancer les deux fichiers de tests**

Run: `vendor/bin/pest tests/Feature/Declarations/`
Expected: PASS. Si `NotifyOverduePaymentsTest` échoue maintenant sur un compte d'envois, c'est que le digest réseau part en plus — vérifier que ces tests ne créent pas d'admin réseau ; ils n'en créent pas.

- [ ] **Step 8 : Pint, PHPStan puis commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress
git add app/Data/InsurerOverdueTotals.php app/Services/Declarations/OverduePaymentsService.php app/Notifications/Declarations/NetworkOverdueDigest.php app/Console/Commands/NotifyOverduePayments.php tests/Feature/Declarations/NetworkOverdueDigestTest.php
git commit -m "feat: digest réseau des retards, agrégé par assureur

Le seuil d'anonymat s'applique ici comme sur les écrans réseau : un digest
qui nommerait les officines en retard contournerait la règle du CDC par la
porte de derrière. Un test vérifie qu'aucun nom d'officine ne figure dans
la charge utile."
```

---

### Vérification finale

- [ ] **Lancer la CI en entier**

Run: `composer ci:check`
Expected: eslint, Prettier, vue-tsc, Pint, PHPStan 0 erreur, et la suite Pest au complet au vert.

- [ ] **Mesurer avant d'envoyer quoi que ce soit en production**

```bash
php artisan declarations:notify-overdue --dry-run
```

Lire le volume ligne par ligne. C'est le moment de décider s'il faut une reprise des déclarations sans date de dépôt (spec §4.1), question qu'aucune tâche de ce plan ne tranche.

---

## Hors plan

**La cloche in-app n'a pas de conception.** Le spec la liste en §9.6 et la table `notifications` la rend possible (Task 4), mais rien n'y décrit l'interface : où elle vit dans le shell console, ce qu'elle affiche, comment une notification se marque lue, s'il faut une page dédiée ou un menu déroulant, et quelle route sert le marquage. Écrire des tâches là-dessus reviendrait à inventer un écran plutôt qu'à exécuter une décision. **Elle mérite son propre cycle conception → plan.** À l'issue de ce plan, les notifications sont bien enregistrées en base : la cloche pourra les lire sans rien changer au back-end.

Restent également hors périmètre, comme le spec l'indique en §10 :

- Les préférences de désabonnement par utilisateur.
- La relance par facture et l'escalade, écartées avec le choix du digest groupé.
- La reprise des déclarations existantes sans date de dépôt, à décider une fois le volume de production mesuré.
- La configuration du mailer (`MAIL_MAILER=log`) et du worker de file, sans lesquels rien ne partira réellement.
- L'ajout du canal `database` à `PharmacyInvitation` (spec §6.6), gratuit mais non décidé.
