# Pénalités de retard — lot A — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner à chaque assureur un délai de déclenchement et un taux de pénalité, calculer la pénalité courue déclaration par déclaration, et l'exposer dans le tableau de bord officine, une page par assureur et les exports officine.

**Architecture :** Deux colonnes nullables sur `insurers` et **aucune colonne de cache** sur `declarations` — la pénalité croît avec le temps, donc elle est recalculée à chaque lecture par trois petites classes pures (`PenaltyCalculator`, `LongestDelay`, `InsurerRelationshipReport`). Les surfaces existantes (`OverduePaymentsService`, `PharmacyExportRows`, `PharmacyPdfExport`) sont enrichies, jamais dupliquées.

**Tech Stack :** Laravel 13 · PHP 8.4 · Pest · Inertia 3 · Vue 3 `<script setup>` · Tailwind 4 · Wayfinder · dompdf · OpenSpout

**Spec :** `docs/superpowers/specs/2026-09-19-penalites-de-retard-lot-a-design.md`

## Global Constraints

Ces contraintes valent pour **toutes** les tâches. Elles sortent de la spec, de `CLAUDE.md` et de `.ai/rules/`.

- **PHP** : accolades obligatoires même sur un corps d'une ligne ; promotion de propriétés dans le constructeur ; types de retour et de paramètres explicites partout ; PHPDoc plutôt que commentaires en ligne ; formes de tableaux (`array{...}`) dans les PHPDoc.
- **Langue** : le code et les PHPDoc de ce projet mêlent anglais et français selon le fichier — **suivre la langue du fichier voisin**. Tout texte visible par l'utilisateur est en français.
- **Pénalité** : taux par tranche de **30 jours**, **non composé**, base **dégressive**, pénalité **acquise conservée** quand le mois finit par être soldé.
- **Points de base** : `penalty_rate_bp`, entier. `250` = 2,50 %. Calcul par `intdiv($base * $rateBp, 10_000)`, **jamais** en flottant.
- **Clause optionnelle** : les deux colonnes à `NULL` = pas de pénalité. Affichage « — », **jamais** « 0 ».
- **Aucune colonne de cache** pour la pénalité. Ne pas l'ajouter au hook `saving` de `Declaration`.
- **Migrations** : aucune valeur par défaut, aucun remplissage — les assureurs existants sortent sans clause.
- **Tests** : Pest. `php artisan make:test --pest {name}` sans le dossier de suite dans le nom. Une colonne d'export se référence **par son nom** via `array_search(...)` sur `COLUMNS`, **jamais par son index**.
- **Vue** : `<script setup>` uniquement, un seul élément racine, `<style scoped>` **obligatoire** dans les pages et layouts.
- **`formatFcfa()` rend une chaîne vide pour `0` et les négatifs** (`resources/js/lib/fcfa.ts`, contrôlé). Or un zéro est une information — « rien encaissé », « rien à réclamer ». Toute page de ce lot passe par `formatAmount()` (ajouté à `lib/fcfa.ts` en Task 5), jamais par `formatFcfa()` nu.
- **Icônes** : composants `@lucide/vue` uniquement, jamais un caractère Unicode de Geometric Shapes (U+25A0–U+25FF) — la police ne les couvre pas et le navigateur ne dessine rien.
- **Wayfinder** : après toute modification de `routes/web.php`, régénérer avec **`npm run build`**, jamais `php artisan wayfinder:generate` (qui casse les variantes `.form`).
- **Formatage** : `vendor/bin/pint --dirty --format agent` après toute modification PHP.
- **Vérification finale** : `composer ci:check` en entier, pas seulement `pint --dirty`.

---

## File Structure

**Créés**

| Fichier | Responsabilité |
|---|---|
| `database/migrations/*_add_penalty_clause_to_insurers_table.php` | Les deux colonnes |
| `app/Services/Declarations/PenaltyCalculator.php` | L'algorithme de pénalité, et lui seul |
| `app/Services/Declarations/LongestDelay.php` | La définition du « délai le plus long », partagée |
| `app/Data/InsurerRelationship.php` | Les agrégats d'un couple officine × assureur |
| `app/Services/Pharmacy/InsurerRelationshipReport.php` | Ce que montre l'écran par assureur |
| `app/Http/Controllers/Pharmacy/InsurerRelationshipController.php` | L'écran par assureur |
| `resources/js/pages/pharmacy/Insurer.vue` | Sa page |
| `tests/Unit/Services/PenaltyCalculatorTest.php` | |
| `tests/Unit/Services/LongestDelayTest.php` | |
| `tests/Feature/Pharmacy/InsurerRelationshipTest.php` | |

**Modifiés**

| Fichier | Changement |
|---|---|
| `app/Models/Insurer.php` | `Fillable`, casts, `hasPenaltyClause()`, `penaltyRatePercent`, `PENALTY_TRANCHE_DAYS` |
| `database/factories/InsurerFactory.php` | état `withPenalty()` |
| `app/Http/Requests/Admin/SaveInsurerRequest.php` | les deux règles et leurs messages |
| `app/Http/Controllers/Admin/InsurerManagementController.php` | conversion % → bp, exposition des champs |
| `resources/js/pages/admin/Insurers.vue` | colonne « CLAUSE DE PÉNALITÉ » |
| `app/Data/OverdueLine.php` | `public ?int $penalty` |
| `app/Services/Declarations/OverduePaymentsService.php` | charge les versements, calcule la pénalité |
| `app/Http/Controllers/Pharmacy/PaymentJourneyController.php` | props `overdue` et `overdueSummary` |
| `resources/js/pages/pharmacy/Dashboard.vue` | bandeau + table |
| `resources/js/lib/fcfa.ts` | `formatAmount()`, partagé par les deux écrans |
| `resources/js/pages/pharmacy/Insurers.vue` | lien vers la page par assureur |
| `routes/web.php` | `pharmacy.insurers.show` |
| `app/Services/Pharmacy/PharmacyExportRows.php` | 3 colonnes |
| `app/Services/Pharmacy/PharmacyPdfExport.php` | 2 colonnes dans `perInsurer()` |
| `resources/views/exports/pharmacy.blade.php` | 2 en-têtes + 2 cellules |
| `tests/Feature/Declarations/InsurerTest.php` | |
| `tests/Feature/Admin/InsurerManagementTest.php` | |
| `tests/Feature/Declarations/OverduePaymentsServiceTest.php` | |
| `tests/Feature/Pharmacy/PaymentJourneyTest.php` | |
| `tests/Feature/Pharmacy/PharmacyExportTest.php` | |

---

### Task 1 : Les deux colonnes et le modèle `Insurer`

**Files:**
- Create: `database/migrations/<généré>_add_penalty_clause_to_insurers_table.php`
- Modify: `app/Models/Insurer.php`
- Modify: `database/factories/InsurerFactory.php`
- Test: `tests/Feature/Declarations/InsurerTest.php`

**Interfaces:**
- Consumes: rien.
- Produces:
  - `insurers.penalty_trigger_days` : `?int`
  - `insurers.penalty_rate_bp` : `?int`
  - `Insurer::PENALTY_TRANCHE_DAYS` : `int` (= 30)
  - `Insurer::hasPenaltyClause(): bool`
  - `Insurer->penalty_rate_percent` : `?float` (accesseur, lecture seule)
  - `InsurerFactory::withPenalty(int $triggerDays = 60, float $ratePercent = 2.0): static`

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à la fin de `tests/Feature/Declarations/InsurerTest.php` :

```php
test('an insurer has no penalty clause by default', function () {
    $insurer = Insurer::factory()->create();

    expect($insurer->penalty_trigger_days)->toBeNull()
        ->and($insurer->penalty_rate_bp)->toBeNull()
        ->and($insurer->hasPenaltyClause())->toBeFalse()
        ->and($insurer->penalty_rate_percent)->toBeNull();
});

test('a penalty clause is read back as a percentage', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 90, ratePercent: 2.5)->create();

    expect($insurer->penalty_trigger_days)->toBe(90)
        ->and($insurer->penalty_rate_bp)->toBe(250)
        ->and($insurer->hasPenaltyClause())->toBeTrue()
        ->and($insurer->penalty_rate_percent)->toBe(2.5);
});

test('a clause needs both halves to count as one', function () {
    $trigger = Insurer::factory()->create(['penalty_trigger_days' => 90]);
    $rate = Insurer::factory()->create(['penalty_rate_bp' => 250]);

    expect($trigger->hasPenaltyClause())->toBeFalse()
        ->and($rate->hasPenaltyClause())->toBeFalse();
});

test('the tranche is thirty days', function () {
    expect(Insurer::PENALTY_TRANCHE_DAYS)->toBe(30);
});

test('the migration leaves every existing insurer without a clause', function () {
    // La migration ne sème rien, à rebours de celle de standard_delay_days qui
    // devait préserver l'ancien seuil global : ici il n'y a pas d'ancienne
    // valeur, et une valeur par défaut ferait apparaître des pénalités le jour
    // du déploiement sur des conventions qui n'en prévoient aucune.
    DB::table('insurers')->insert([
        'name' => 'Assureur historique',
        'is_active' => true,
        'standard_delay_days' => 45,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $insurer = Insurer::query()->where('name', 'Assureur historique')->sole();

    expect($insurer->penalty_trigger_days)->toBeNull()
        ->and($insurer->penalty_rate_bp)->toBeNull();
});
```

> Ajouter `use Illuminate\Support\Facades\DB;` en tête du fichier s'il n'y est pas.

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

Run : `php artisan test --compact tests/Feature/Declarations/InsurerTest.php`
Expected : FAIL — `Call to undefined method App\Models\Insurer::hasPenaltyClause()`

- [ ] **Step 3 : Créer la migration**

Run : `php artisan make:migration add_penalty_clause_to_insurers_table --no-interaction`

Puis remplir le fichier généré :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Une convention de tiers payant prévoit couramment une pénalité au-delà
     * d'un délai qui n'est pas celui du remboursement : un second seuil, plus
     * tardif, à partir duquel l'assureur doit majorer sa dette.
     *
     * Les deux colonnes sont nullables et **sans valeur par défaut** : tout
     * assureur existant sort d'ici sans clause, donc sans pénalité, et aucun
     * chiffre ne bouge le jour du déploiement. C'est l'inverse du choix fait
     * pour `standard_delay_days`, qui devait semer chaque ligne avec l'ancien
     * seuil global — ici il n'y a pas d'ancienne valeur à préserver.
     */
    public function up(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->unsignedSmallInteger('penalty_trigger_days')
                ->nullable()
                ->after('standard_delay_days');

            // En points de base plutôt qu'en décimal : une pénalité est de
            // l'argent, et tout montant de ce projet est un entier FCFA.
            // 250 = 2,50 %, et intdiv($base * 250, 10000) reste exact là où
            // $base * 2.5 / 100 passerait par un flottant.
            $table->unsignedSmallInteger('penalty_rate_bp')
                ->nullable()
                ->after('penalty_trigger_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->dropColumn(['penalty_trigger_days', 'penalty_rate_bp']);
        });
    }
};
```

- [ ] **Step 4 : Étendre le modèle `Insurer`**

Dans `app/Models/Insurer.php` — ajouter au PHPDoc de classe, à `Fillable`, aux casts, et les trois membres :

```php
/**
 * @property int|null $penalty_trigger_days
 * @property int|null $penalty_rate_bp
 * @property-read float|null $penalty_rate_percent
 */
#[Fillable(['name', 'is_active', 'standard_delay_days', 'penalty_trigger_days', 'penalty_rate_bp'])]
```

```php
    /**
     * How long a penalty tranche runs, in days.
     *
     * The clause bites on the trigger day, then again every tranche for as
     * long as something remains owed.
     */
    public const PENALTY_TRANCHE_DAYS = 30;
```

Dans `casts()` :

```php
            'penalty_trigger_days' => 'integer',
            'penalty_rate_bp' => 'integer',
```

Puis, après `casts()` :

```php
    /**
     * Whether a penalty was actually agreed with this insurer.
     *
     * The two columns are meaningless apart — a trigger without a rate accrues
     * nothing, a rate without a trigger never starts — so they are read as one
     * clause, and half a clause is no clause.
     */
    public function hasPenaltyClause(): bool
    {
        return $this->penalty_trigger_days !== null && $this->penalty_rate_bp !== null;
    }

    /**
     * The penalty rate as the convention writes it: 250 basis points → 2.5 %.
     *
     * Read-only. The column stays the source of truth, in basis points, so
     * every amount derived from it is integer arithmetic.
     *
     * @return Attribute<float|null, never>
     */
    protected function penaltyRatePercent(): Attribute
    {
        return Attribute::get(
            fn (): ?float => $this->penalty_rate_bp === null
                ? null
                : $this->penalty_rate_bp / 100,
        );
    }
```

Ajouter l'import `use Illuminate\Database\Eloquent\Casts\Attribute;`.

- [ ] **Step 5 : Ajouter l'état de factory**

Dans `database/factories/InsurerFactory.php`, après `inactive()` :

```php
    /**
     * A penalty clause, as a convention would spell it out.
     *
     * Takes a percentage because that is what a convention says; the column
     * holds basis points, and the conversion belongs here rather than in every
     * test that needs a clause.
     */
    public function withPenalty(int $triggerDays = 60, float $ratePercent = 2.0): static
    {
        return $this->state(fn (array $attributes) => [
            'penalty_trigger_days' => $triggerDays,
            'penalty_rate_bp' => (int) round($ratePercent * 100),
        ]);
    }
```

- [ ] **Step 6 : Lancer les tests et vérifier qu'ils passent**

Run : `php artisan test --compact tests/Feature/Declarations/InsurerTest.php`
Expected : PASS

- [ ] **Step 7 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/Insurer.php database/factories/InsurerFactory.php tests/Feature/Declarations/InsurerTest.php
git commit -m "feat: porter la clause de pénalité sur l'assureur

Délai de déclenchement et taux en points de base, tous deux nullables :
un assureur sans clause n'en porte aucune trace, et la migration ne
déplace aucun chiffre existant.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2 : `PenaltyCalculator` et `LongestDelay`

**Files:**
- Create: `app/Services/Declarations/PenaltyCalculator.php`
- Create: `app/Services/Declarations/LongestDelay.php`
- Test: `tests/Unit/Services/PenaltyCalculatorTest.php`
- Test: `tests/Unit/Services/LongestDelayTest.php`

**Interfaces:**
- Consumes: `Insurer::PENALTY_TRANCHE_DAYS`, `Insurer::hasPenaltyClause()` (Task 1).
- Produces:
  - `PenaltyCalculator::accrued(int $amountInvoiced, int $amountReceived, CarbonImmutable $depositedOn, ?CarbonImmutable $paidOn, int $triggerDays, int $rateBp, array $payments): int`
    où `$payments` est `list<array{amount: int, paid_on: CarbonImmutable}>`
  - `PenaltyCalculator::for(Declaration $declaration): ?int` — exige `insurer` et `payments` préchargés
  - `PenaltyCalculator::total(iterable $declarations): ?int` — `null` si aucune déclaration ne porte de clause
  - `LongestDelay::for(iterable $declarations): ?int`

> **Note pour l'implémenteur :** ces deux classes sont pures et testées en `Unit`, donc **sans base de données**. `tests/Unit` n'utilise pas `RefreshDatabase` (voir `tests/Pest.php`) : les déclarations des tests sont construites avec `Declaration::factory()->make()` et `setRelation()`, jamais `create()`.

- [ ] **Step 1 : Écrire les tests de `PenaltyCalculator`**

Créer `tests/Unit/Services/PenaltyCalculatorTest.php` :

```php
<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\DeclarationPayment;
use App\Models\Insurer;
use App\Services\Declarations\PenaltyCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->calculator = new PenaltyCalculator;
});

/**
 * Une déclaration en mémoire, avec son assureur et ses versements attachés.
 *
 * @param  list<array{amount: int, paid_on: string}>  $payments
 */
function declarationFor(
    Insurer $insurer,
    int $invoiced,
    string $depositedOn,
    array $payments = [],
    DeclarationStatus $status = DeclarationStatus::Unpaid,
): Declaration {
    $received = array_sum(array_column($payments, 'amount'));

    $declaration = Declaration::factory()->make([
        'amount_invoiced' => $invoiced,
        'amount_received' => $received,
        'status' => $status,
        'is_status_manual' => true,
        'invoice_deposited_on' => $depositedOn,
        'paid_on' => $payments === [] ? null : end($payments)['paid_on'],
        'delay_days' => null,
    ]);

    $declaration->setRelation('insurer', $insurer);
    $declaration->setRelation('payments', new Collection(array_map(
        fn (array $payment): DeclarationPayment => (new DeclarationPayment)->forceFill([
            'amount' => $payment['amount'],
            'paid_on' => $payment['paid_on'],
        ]),
        $payments,
    )));

    return $declaration;
}

test('an insurer without a clause accrues nothing', function () {
    $insurer = Insurer::factory()->make(['standard_delay_days' => 30]);

    $declaration = declarationFor($insurer, 1_000_000, '2026-01-01');

    expect($this->calculator->for($declaration))->toBeNull();
});

test('half a clause is no clause', function () {
    $insurer = Insurer::factory()->make([
        'penalty_trigger_days' => 60,
        'penalty_rate_bp' => null,
    ]);

    expect($this->calculator->for(declarationFor($insurer, 1_000_000, '2026-01-01')))->toBeNull();
});

test('a declaration with no deposit date accrues nothing', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    $declaration = declarationFor($insurer, 1_000_000, '2026-01-01');
    $declaration->invoice_deposited_on = null;

    expect($this->calculator->for($declaration))->toBeNull();
});

test('a rejected month accrues nothing', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    $declaration = declarationFor(
        $insurer,
        1_000_000,
        '2026-01-01',
        status: DeclarationStatus::Rejected,
    );

    expect($this->calculator->for($declaration))->toBeNull();
});

test('the day before the trigger accrues nothing', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    // Déposée il y a 59 jours : la pénalité mord au 60e.
    $declaration = declarationFor($insurer, 1_000_000, '2026-07-22');

    expect($this->calculator->for($declaration))->toBe(0);
});

test('the trigger day itself accrues exactly one tranche', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    // Déposée il y a exactement 60 jours.
    $declaration = declarationFor($insurer, 1_000_000, '2026-07-21');

    expect($this->calculator->for($declaration))->toBe(20_000);
});

test('an unpaid month accrues one tranche every thirty days', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    // Déposée il y a 120 jours : tranches au 60e, 90e et 120e jour.
    $declaration = declarationFor($insurer, 1_000_000, '2026-05-22');

    expect($this->calculator->for($declaration))->toBe(60_000);
});

test('each instalment shrinks the base of the tranches that follow', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    // Déposée le 2026-05-22 : tranches les 2026-07-21, 2026-08-20, 2026-09-19.
    // 400 000 arrivent le 2026-08-01, donc entre la première et la deuxième.
    $declaration = declarationFor($insurer, 1_000_000, '2026-05-22', [
        ['amount' => 400_000, 'paid_on' => '2026-08-01'],
    ]);

    // 20 000 sur 1 000 000, puis deux fois 12 000 sur 600 000.
    expect($this->calculator->for($declaration))->toBe(44_000);
});

test('money arriving on the tranche day itself lightens that tranche', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    // Tranche unique le 2026-07-21, versement le même jour.
    $declaration = declarationFor($insurer, 1_000_000, '2026-05-22', [
        ['amount' => 400_000, 'paid_on' => '2026-07-21'],
    ]);

    // La base de la première tranche vaut déjà 600 000.
    expect($this->calculator->for($declaration))->toBe(12_000 + 12_000 + 12_000);
});

test('a month settled late keeps the penalty it had accrued', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    // Déposée le 2026-05-22, soldée le 2026-08-25 : tranches les 2026-07-21
    // et 2026-08-20, la seconde sur la base encore entière.
    $declaration = declarationFor($insurer, 1_000_000, '2026-05-22', [
        ['amount' => 1_000_000, 'paid_on' => '2026-08-25'],
    ]);

    expect($this->calculator->for($declaration))->toBe(40_000);
});

test('a settled month stops accruing as time passes', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    $declaration = declarationFor($insurer, 1_000_000, '2026-05-22', [
        ['amount' => 1_000_000, 'paid_on' => '2026-08-25'],
    ]);

    $before = $this->calculator->for($declaration);

    $this->travelTo(CarbonImmutable::create(2027, 3, 1));

    expect($this->calculator->for($declaration))->toBe($before);
});

test('an unpaid month keeps accruing as time passes', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    $declaration = declarationFor($insurer, 1_000_000, '2026-05-22');

    $before = $this->calculator->for($declaration);

    $this->travelTo(CarbonImmutable::create(2026, 10, 19));

    expect($this->calculator->for($declaration))->toBe($before + 20_000);
});

test('a base fallen to zero stops the clock for good', function () {
    $insurer = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);

    // Soldée le 2026-07-25, donc après la première tranche et avant la seconde.
    $declaration = declarationFor($insurer, 1_000_000, '2026-05-22', [
        ['amount' => 1_000_000, 'paid_on' => '2026-07-25'],
    ]);

    expect($this->calculator->for($declaration))->toBe(20_000);
});

test('the total is null when not one declaration carries a clause', function () {
    $insurer = Insurer::factory()->make(['standard_delay_days' => 30]);

    expect($this->calculator->total([
        declarationFor($insurer, 1_000_000, '2026-01-01'),
        declarationFor($insurer, 2_000_000, '2026-02-01'),
    ]))->toBeNull();
});

test('the total sums what carries a clause and ignores what does not', function () {
    $withClause = Insurer::factory()->make(['penalty_trigger_days' => 60, 'penalty_rate_bp' => 200]);
    $without = Insurer::factory()->make(['standard_delay_days' => 30]);

    expect($this->calculator->total([
        declarationFor($withClause, 1_000_000, '2026-07-21'),
        declarationFor($without, 9_000_000, '2026-01-01'),
    ]))->toBe(20_000);
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `vendor/bin/pest tests/Unit/Services/PenaltyCalculatorTest.php`
Expected : FAIL — `Class "App\Services\Declarations\PenaltyCalculator" not found`

- [ ] **Step 3 : Écrire `PenaltyCalculator`**

Créer `app/Services/Declarations/PenaltyCalculator.php` :

```php
<?php

namespace App\Services\Declarations;

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\DeclarationPayment;
use App\Models\Insurer;
use Carbon\CarbonImmutable;

/**
 * Ce qu'un assureur doit en plus pour avoir payé trop tard.
 *
 * Calcul pur, sans écriture, sans colonne de cache — et c'est la rupture
 * assumée avec `delay_days`. Ce dernier est stocké parce qu'il ne dépend que
 * des données saisies : deux dates entrent, un entier sort. La pénalité, elle,
 * **croît toute seule** : un mois jamais réglé voit la sienne augmenter tous
 * les trente jours sans qu'aucune écriture ne survienne. Une colonne exigerait
 * une tâche quotidienne, et le chiffre serait faux entre deux passages.
 *
 * Conséquence à connaître : ce calcul ne se somme pas en SQL. Il lui faut les
 * versements de chaque déclaration. À l'échelle d'une officine c'est une
 * boucle sur une centaine de lignes ; à l'échelle du réseau il faudra une
 * autre stratégie.
 */
class PenaltyCalculator
{
    /**
     * La pénalité courue par une déclaration, ou null s'il n'y a rien à courir.
     *
     * Exige `insurer` et `payments` préchargés : appelé en boucle sur une
     * collection, il produirait sinon deux requêtes par ligne.
     */
    public function for(Declaration $declaration): ?int
    {
        $insurer = $declaration->insurer;

        if (! $insurer->hasPenaltyClause()) {
            return null;
        }

        if ($declaration->invoice_deposited_on === null) {
            return null;
        }

        // Une facture refusée n'est pas due, donc rien ne la majore. Même
        // exclusion que dans OverduePaymentsService::overdueQuery().
        if ($declaration->status === DeclarationStatus::Rejected) {
            return null;
        }

        return $this->accrued(
            amountInvoiced: $declaration->amount_invoiced,
            amountReceived: $declaration->amount_received,
            depositedOn: $declaration->invoice_deposited_on,
            paidOn: $declaration->paid_on,
            triggerDays: (int) $insurer->penalty_trigger_days,
            rateBp: (int) $insurer->penalty_rate_bp,
            payments: $declaration->payments->map(fn (DeclarationPayment $payment): array => [
                'amount' => $payment->amount,
                'paid_on' => $payment->paid_on,
            ])->all(),
        );
    }

    /**
     * La pénalité cumulée d'un lot, ou null si aucune ligne ne porte de clause.
     *
     * Null et zéro disent deux choses différentes : « aucune clause avec cet
     * assureur » se rend par un tiret, « une clause mais rien à réclamer » par
     * un zéro. Les confondre ferait lire une convention absente comme une
     * convention respectée.
     *
     * @param  iterable<Declaration>  $declarations
     */
    public function total(iterable $declarations): ?int
    {
        $total = null;

        foreach ($declarations as $declaration) {
            $penalty = $this->for($declaration);

            if ($penalty === null) {
                continue;
            }

            $total = ($total ?? 0) + $penalty;
        }

        return $total;
    }

    /**
     * L'algorithme, sur des valeurs nues.
     *
     * Séparé de for() parce que OverduePaymentsService lit en query builder et
     * n'hydrate jamais de Declaration — il ne doit pas charger `private_note`.
     * Une seule implémentation, deux portes d'entrée.
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
        $end = $this->clockStopsOn($amountInvoiced, $amountReceived, $paidOn);

        if ($end === null) {
            return 0;
        }

        $total = 0;
        $tranche = $depositedOn->startOfDay()->addDays($triggerDays);

        while ($tranche <= $end) {
            $base = $amountInvoiced - $this->receivedBy($payments, $tranche);

            // Les versements ne font que s'ajouter : une base retombée à zéro
            // ne peut plus remonter, donc l'arrêt est définitif.
            if ($base <= 0) {
                break;
            }

            $total += intdiv($base * $rateBp, 10_000);
            $tranche = $tranche->addDays(Insurer::PENALTY_TRANCHE_DAYS);
        }

        return $total;
    }

    /**
     * Jusqu'à quand la pénalité court.
     *
     * Un mois entièrement soldé cesse de courir au dernier versement, et ce
     * qu'il avait accumulé lui reste acquis. Un mois qui doit encore quelque
     * chose court jusqu'à aujourd'hui.
     */
    protected function clockStopsOn(int $amountInvoiced, int $amountReceived, ?CarbonImmutable $paidOn): ?CarbonImmutable
    {
        if ($amountReceived < $amountInvoiced) {
            return CarbonImmutable::now()->startOfDay();
        }

        return $paidOn?->startOfDay();
    }

    /**
     * Ce qui était arrivé au plus tard le jour où la tranche mord.
     *
     * Comparaison inclusive : de l'argent viré le jour même allège cette
     * tranche-là plutôt que la suivante.
     *
     * @param  list<array{amount: int, paid_on: CarbonImmutable}>  $payments
     */
    protected function receivedBy(array $payments, CarbonImmutable $tranche): int
    {
        $total = 0;

        foreach ($payments as $payment) {
            if ($payment['paid_on']->startOfDay() <= $tranche) {
                $total += $payment['amount'];
            }
        }

        return $total;
    }
}
```

- [ ] **Step 4 : Lancer les tests de `PenaltyCalculator`**

Run : `vendor/bin/pest tests/Unit/Services/PenaltyCalculatorTest.php`
Expected : PASS (16 tests)

- [ ] **Step 5 : Écrire les tests de `LongestDelay`**

Créer `tests/Unit/Services/LongestDelayTest.php` :

```php
<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Services\Declarations\LongestDelay;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->longest = new LongestDelay;
});

/** Un mois réglé, avec le délai voulu. */
function settledWithDelay(int $delayDays): Declaration
{
    return Declaration::factory()->make([
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'status' => DeclarationStatus::Paid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-01-01',
        'paid_on' => CarbonImmutable::create(2026, 1, 1)->addDays($delayDays),
        'delay_days' => $delayDays,
    ]);
}

/** Un mois encore dû, déposé il y a $ageDays jours. */
function openSince(int $ageDays): Declaration
{
    return Declaration::factory()->make([
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 9, 19)->subDays($ageDays),
        'paid_on' => null,
        'delay_days' => null,
    ]);
}

test('nothing declared yields no delay', function () {
    expect($this->longest->for([]))->toBeNull();
});

test('only settled months yields the worst settled delay', function () {
    expect($this->longest->for([settledWithDelay(12), settledWithDelay(48)]))->toBe(48);
});

test('only open months yields the age of the oldest', function () {
    expect($this->longest->for([openSince(30), openSince(210)]))->toBe(210);
});

test('an open month can beat every settled one', function () {
    expect($this->longest->for([settledWithDelay(48), openSince(400)]))->toBe(400);
});

test('a settled month can beat every open one', function () {
    expect($this->longest->for([settledWithDelay(300), openSince(20)]))->toBe(300);
});

test('a rejected month is not an open debt', function () {
    $rejected = openSince(400);
    $rejected->status = DeclarationStatus::Rejected;

    expect($this->longest->for([settledWithDelay(48), $rejected]))->toBe(48);
});

test('an open month never deposited has no age', function () {
    $undeposited = openSince(400);
    $undeposited->invoice_deposited_on = null;

    expect($this->longest->for([$undeposited]))->toBeNull();
});
```

- [ ] **Step 6 : Lancer pour vérifier l'échec**

Run : `vendor/bin/pest tests/Unit/Services/LongestDelayTest.php`
Expected : FAIL — `Class "App\Services\Declarations\LongestDelay" not found`

- [ ] **Step 7 : Écrire `LongestDelay`**

Créer `app/Services/Declarations/LongestDelay.php` :

```php
<?php

namespace App\Services\Declarations;

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use Carbon\CarbonImmutable;

/**
 * Le pire retard constaté avec un assureur, soldé ou non.
 *
 * Deux natures de retard dans un seul chiffre, délibérément : le plus long
 * délai d'un mois réglé, et l'âge de la plus vieille facture encore due. Ne
 * garder que le premier ferait disparaître une facture ouverte depuis quatre
 * cents jours — exactement le cas le plus grave, et celui qui pèse le plus en
 * négociation.
 *
 * Une classe à part plutôt qu'une méthode : la synthèse du PDF officine et
 * l'écran par assureur affichent tous deux ce chiffre, et deux
 * implémentations divergeraient.
 */
class LongestDelay
{
    /**
     * @param  iterable<Declaration>  $declarations
     */
    public function for(iterable $declarations): ?int
    {
        $today = CarbonImmutable::now()->startOfDay();
        $longest = null;

        foreach ($declarations as $declaration) {
            $candidate = $this->settledDelay($declaration) ?? $this->openAge($declaration, $today);

            if ($candidate === null) {
                continue;
            }

            $longest = $longest === null ? $candidate : max($longest, $candidate);
        }

        return $longest;
    }

    /**
     * Le délai d'un mois réglé, tel que le hook `saving` l'a dérivé.
     */
    protected function settledDelay(Declaration $declaration): ?int
    {
        return $declaration->delay_days;
    }

    /**
     * L'âge d'une facture encore due, comptée depuis le dépôt.
     *
     * Même horloge que `OverdueLine::ageDays` et que `delay_days` — surtout
     * pas depuis la fin du mois déclaré, qui est celle des tranches
     * d'ancienneté et donnerait un second chiffre pour la même facture.
     */
    protected function openAge(Declaration $declaration, CarbonImmutable $today): ?int
    {
        if ($declaration->status === DeclarationStatus::Rejected) {
            return null;
        }

        if ($declaration->amount_invoiced <= $declaration->amount_received) {
            return null;
        }

        if ($declaration->invoice_deposited_on === null) {
            return null;
        }

        return (int) $declaration->invoice_deposited_on->startOfDay()->diffInDays($today);
    }
}
```

- [ ] **Step 8 : Lancer les tests et vérifier qu'ils passent**

Run : `vendor/bin/pest tests/Unit/Services/`
Expected : PASS (23 tests)

- [ ] **Step 9 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Declarations/PenaltyCalculator.php app/Services/Declarations/LongestDelay.php tests/Unit/Services
git commit -m "feat: calculer la pénalité courue et le pire retard

Base dégressive, tranche de 30 jours, pénalité acquise conservée quand
le mois finit par être soldé. Aucun cache : la pénalité croît avec le
temps, une colonne serait fausse entre deux passages d'un job.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3 : Saisie de la clause dans l'écran d'administration

**Files:**
- Modify: `app/Http/Requests/Admin/SaveInsurerRequest.php`
- Modify: `app/Http/Controllers/Admin/InsurerManagementController.php`
- Modify: `resources/js/pages/admin/Insurers.vue`
- Test: `tests/Feature/Admin/InsurerManagementTest.php`

**Interfaces:**
- Consumes: `Insurer` (Task 1).
- Produces: props `penaltyTriggerDays: number|null` et `penaltyRatePercent: number|null` dans `admin/Insurers` ; champs de formulaire `penalty_trigger_days` et `penalty_rate_percent`.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Admin/InsurerManagementTest.php` :

```php
test('an admin records a penalty clause as a percentage', function () {
    $insurer = Insurer::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $insurer), [
            'penalty_trigger_days' => 90,
            'penalty_rate_percent' => 2.5,
        ])
        ->assertRedirect(route('admin.insurers'));

    expect($insurer->fresh()->penalty_trigger_days)->toBe(90)
        ->and($insurer->fresh()->penalty_rate_bp)->toBe(250);
});

test('a trigger without a rate is refused', function () {
    $insurer = Insurer::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $insurer), ['penalty_trigger_days' => 90])
        ->assertSessionHasErrors('penalty_rate_percent');

    expect($insurer->fresh()->penalty_trigger_days)->toBeNull();
});

test('a rate without a trigger is refused', function () {
    $insurer = Insurer::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $insurer), ['penalty_rate_percent' => 2.5])
        ->assertSessionHasErrors('penalty_trigger_days');

    expect($insurer->fresh()->penalty_rate_bp)->toBeNull();
});

test('submitting both fields empty clears the clause', function () {
    $insurer = Insurer::factory()->withPenalty()->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $insurer), [
            'penalty_trigger_days' => '',
            'penalty_rate_percent' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($insurer->fresh()->penalty_trigger_days)->toBeNull()
        ->and($insurer->fresh()->penalty_rate_bp)->toBeNull()
        ->and($insurer->fresh()->hasPenaltyClause())->toBeFalse();
});

test('renaming an insurer leaves its clause alone', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 90, ratePercent: 2.5)->create();

    $this->actingAs($this->admin)
        ->patch(route('admin.insurers.update', $insurer), ['name' => 'NSIA Bénin'])
        ->assertSessionHasNoErrors();

    expect($insurer->fresh()->name)->toBe('NSIA Bénin')
        ->and($insurer->fresh()->penalty_trigger_days)->toBe(90)
        ->and($insurer->fresh()->penalty_rate_bp)->toBe(250);
});

test('the management screen carries each clause', function () {
    Insurer::factory()->withPenalty(triggerDays: 90, ratePercent: 2.5)->create(['name' => 'NSIA']);

    $this->actingAs($this->admin)
        ->get(route('admin.insurers'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Insurers')
            ->where('insurers.0.penaltyTriggerDays', 90)
            ->where('insurers.0.penaltyRatePercent', 2.5),
        );
});
```

> `$this->admin` est posé par le `beforeEach` de ce fichier (`User::factory()->networkAdmin()->notOnboarded()->create()`). Ne pas en fabriquer un autre.

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Admin/InsurerManagementTest.php`
Expected : FAIL — la clause n'est pas persistée, les props n'existent pas

- [ ] **Step 3 : Étendre `SaveInsurerRequest`**

Dans `rules()`, après `'standard_delay_days'` :

```php
            // Les deux moitiés de la clause voyagent ensemble, à rebours de la
            // règle « un champ, un formulaire » qui vaut pour le reste de cet
            // écran : un déclenchement sans taux n'accumule rien, un taux sans
            // déclenchement ne démarre jamais. required_with tient donc dans
            // les deux sens, et les deux vides effacent la clause.
            'penalty_trigger_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365', 'required_with:penalty_rate_percent'],
            'penalty_rate_percent' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:100', 'required_with:penalty_trigger_days'],
```

Dans `messages()` :

```php
            'penalty_trigger_days.required_with' => 'Indiquez à partir de combien de jours la pénalité se déclenche.',
            'penalty_trigger_days.integer' => 'Le délai de déclenchement se compte en jours entiers.',
            'penalty_trigger_days.min' => 'Le délai de déclenchement doit valoir au moins 1 jour.',
            'penalty_trigger_days.max' => 'Le délai de déclenchement ne peut pas dépasser 365 jours.',
            'penalty_rate_percent.required_with' => 'Indiquez le taux de pénalité prévu par la convention.',
            'penalty_rate_percent.numeric' => 'Le taux de pénalité est un pourcentage.',
            'penalty_rate_percent.min' => 'Le taux de pénalité doit être supérieur à zéro.',
            'penalty_rate_percent.max' => 'Le taux de pénalité ne peut pas dépasser 100 %.',
```

- [ ] **Step 4 : Étendre `InsurerManagementController`**

Dans `index()`, ajouter au `map()` :

```php
                    'penaltyTriggerDays' => $insurer->penalty_trigger_days,
                    'penaltyRatePercent' => $insurer->penalty_rate_percent,
```

Dans `update()`, ajouter au tableau passé à `$insurer->update([...])` :

```php
                // Les deux champs arrivent ensemble ou pas du tout : tester
                // l'un suffit, et le faire sur `penalty_trigger_days` garde la
                // clause intacte quand l'écran ne renvoie qu'un renommage.
                ...$request->has('penalty_trigger_days') ? [
                    'penalty_trigger_days' => $request->integer('penalty_trigger_days') ?: null,
                    'penalty_rate_bp' => $this->rateInBasisPoints($request),
                ] : [],
```

Puis ajouter la méthode, après `update()` :

```php
    /**
     * Le taux saisi en pourcentage, ramené en points de base.
     *
     * La conversion vit ici plutôt que dans le modèle : la base de données est
     * la source de vérité et elle compte en points de base ; le pourcentage
     * n'est qu'une commodité de saisie, comme le montant formaté d'AmountField.
     */
    protected function rateInBasisPoints(SaveInsurerRequest $request): ?int
    {
        $percent = $request->validated('penalty_rate_percent');

        return $percent === null || $percent === '' ? null : (int) round((float) $percent * 100);
    }
```

`store()` ne change pas : un assureur naît sans clause, et l'administrateur la renseigne ensuite.

- [ ] **Step 5 : Lancer les tests back-end**

Run : `php artisan test --compact tests/Feature/Admin/InsurerManagementTest.php`
Expected : PASS

- [ ] **Step 6 : Ajouter la colonne à `admin/Insurers.vue`**

Dans le `<script setup>` — étendre le type et la grille :

```ts
type Row = {
    id: number;
    name: string;
    isActive: boolean;
    standardDelayDays: number;
    penaltyTriggerDays: number | null;
    penaltyRatePercent: number | null;
    pharmacies: number;
};
```

```ts
const TEMPLATE = '1.8fr .7fr 1fr 1.4fr .8fr 1fr';
const COLUMNS = [
    'ASSUREUR',
    'OFFICINES (n)',
    'DÉLAI STANDARD',
    'CLAUSE DE PÉNALITÉ',
    'ÉTAT',
    'ACTION',
];
```

Dans le `<template>`, insérer une cellule **juste après** le `<div>` du formulaire `standard_delay_days` et **avant** celui de l'état :

```vue
                    <!--
                        Les deux champs dans un seul formulaire, contrairement
                        au délai standard : un déclenchement sans taux
                        n'accumule rien, et required_with refuserait la moitié
                        d'une clause. Les deux vides l'effacent.
                    -->
                    <div>
                        <Form
                            :action="`/admin/insurers/${row.id}`"
                            method="patch"
                            #default="{ submit, processing, errors }"
                        >
                            <div class="row-penalty-control">
                                <input
                                    :value="row.penaltyTriggerDays ?? ''"
                                    name="penalty_trigger_days"
                                    type="number"
                                    min="1"
                                    max="365"
                                    placeholder="—"
                                    :disabled="processing"
                                    :aria-label="`Déclenchement de la pénalité de ${row.name}, en jours`"
                                    class="row-delay-input"
                                    @change="submit"
                                />

                                <span class="row-delay-unit"> j · </span>

                                <input
                                    :value="row.penaltyRatePercent ?? ''"
                                    name="penalty_rate_percent"
                                    type="number"
                                    min="0.01"
                                    max="100"
                                    step="0.01"
                                    placeholder="—"
                                    :disabled="processing"
                                    :aria-label="`Taux de pénalité de ${row.name}, en pourcent`"
                                    class="row-delay-input"
                                    @change="submit"
                                />

                                <span class="row-delay-unit"> % </span>
                            </div>

                            <p
                                v-if="errors.penalty_trigger_days"
                                class="form-error"
                            >
                                {{ errors.penalty_trigger_days }}
                            </p>

                            <p
                                v-if="errors.penalty_rate_percent"
                                class="form-error"
                            >
                                {{ errors.penalty_rate_percent }}
                            </p>
                        </Form>
                    </div>
```

Dans le `<style scoped>`, à côté de `.row-delay-control` :

```css
.row-penalty-control {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}
```

- [ ] **Step 7 : Vérifier le front**

Run : `npm run build && npm run types:check && npm run format:check`
Expected : les trois passent

- [ ] **Step 8 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Admin/SaveInsurerRequest.php app/Http/Controllers/Admin/InsurerManagementController.php resources/js/pages/admin/Insurers.vue tests/Feature/Admin/InsurerManagementTest.php
git commit -m "feat: saisir la clause de pénalité par assureur

Les deux champs voyagent dans un seul formulaire — une demi-clause
n'accumule rien — et les deux vides l'effacent.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4 : La pénalité sur les lignes en retard

**Files:**
- Modify: `app/Data/OverdueLine.php`
- Modify: `app/Services/Declarations/OverduePaymentsService.php`
- Test: `tests/Feature/Declarations/OverduePaymentsServiceTest.php`

**Interfaces:**
- Consumes: `PenaltyCalculator::accrued()` (Task 2), les colonnes de clause (Task 1).
- Produces: `OverdueLine->insurerId : int` (**avant** `insurerName`) et `OverdueLine->penalty : ?int` (**après** `outstanding`).

> **Pourquoi `accrued()` et pas `for()` :** `OverduePaymentsService` lit délibérément en *query builder* et n'hydrate aucune `Declaration`, pour ne jamais charger `private_note`. Il passe donc des valeurs nues.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Declarations/OverduePaymentsServiceTest.php` :

```php
test('an overdue line carries no penalty when the insurer has no clause', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);
    overdueCandidate($this->pharmacy, $insurer, 100);

    expect($this->service->forPharmacy($this->pharmacy)[0]->penalty)->toBeNull();
});

test('an overdue line carries the penalty accrued since the trigger', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    // Déposée il y a 120 jours : tranches au 60e, 90e et 120e jour, sur une
    // base jamais entamée.
    overdueCandidate($this->pharmacy, $insurer, 120);

    expect($this->service->forPharmacy($this->pharmacy)[0]->penalty)->toBe(60_000);
});

test('an instalment lightens the penalty of an overdue line', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    $declaration = overdueCandidate($this->pharmacy, $insurer, 120);

    app(RecordPaymentInstalments::class)->handle($declaration, [[
        'amount' => 400_000,
        'paid_on' => CarbonImmutable::create(2026, 8, 31)->subDays(70)->toDateString(),
    ]]);

    // 20 000 sur la base entière, puis deux tranches sur 600 000.
    expect($this->service->forPharmacy($this->pharmacy)[0]->penalty)->toBe(44_000);
});

test('reading the overdue lines costs three queries whatever their number', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    foreach (range(1, 6) as $offset) {
        overdueCandidate($this->pharmacy, $insurer, 100 + $offset);
    }

    DB::enableQueryLog();
    $this->service->forPharmacy($this->pharmacy);

    // Les délais standards distincts, les lignes en retard, leurs versements.
    expect(DB::getQueryLog())->toHaveCount(3);
});
```

Ajouter en tête du fichier les imports manquants :

```php
use App\Actions\Declarations\RecordPaymentInstalments;
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Declarations/OverduePaymentsServiceTest.php`
Expected : FAIL — `Undefined property: App\Data\OverdueLine::$penalty`

- [ ] **Step 3 : Étendre `OverdueLine`**

Ajouter `insurerId` **avant** `insurerName` — la table du tableau de bord (Task 5) lie chaque nom vers la page de son assureur, et la ligne ne porte pas encore son id :

```php
        public int $insurerId,
```

Puis la pénalité, après `outstanding` :

```php
        public int $outstanding,
        /**
         * Ce que le retard a majoré, ou null si aucune clause n'a été convenue.
         *
         * Null et zéro disent deux choses : « pas de clause » se rend par un
         * tiret, « une clause mais rien encore » par un zéro.
         */
        public ?int $penalty,
```

- [ ] **Step 4 : Étendre `OverduePaymentsService`**

Injecter le calculateur :

```php
    public function __construct(
        protected SettingsRepository $settings,
        protected PenaltyCalculator $penalties,
    ) {
        //
    }
```

Dans `forPharmacy()`, ajouter au `select()` :

```php
                'insurers.id as insurer_id',
                'insurers.penalty_trigger_days',
                'insurers.penalty_rate_bp',
                'declarations.amount_invoiced',
                'declarations.amount_received',
```

Puis, entre `->get()` et la construction des lignes :

```php
        $instalments = $this->instalmentsOf($rows->pluck('id'));
```

Et dans le `map()`, après le calcul de `$deposited` :

```php
            $penalty = $row->penalty_trigger_days === null || $row->penalty_rate_bp === null
                ? null
                : $this->penalties->accrued(
                    amountInvoiced: (int) $row->amount_invoiced,
                    amountReceived: (int) $row->amount_received,
                    depositedOn: $deposited,
                    paidOn: null,
                    triggerDays: (int) $row->penalty_trigger_days,
                    rateBp: (int) $row->penalty_rate_bp,
                    payments: $instalments[$row->id] ?? [],
                );
```

`paidOn: null` est correct et non un oubli : une ligne en retard doit encore quelque chose par définition — `whereColumn('amount_invoiced', '>', 'amount_received')` —, donc l'horloge court jusqu'à aujourd'hui et la date de solde n'est jamais lue.

Passer `insurerId: (int) $row->insurer_id,` avant `insurerName`, et `penalty: $penalty` après `outstanding`.

Enfin, ajouter la méthode :

```php
    /**
     * Les versements de ces déclarations, groupés, en une requête.
     *
     * Une par déclaration ferait un N+1 sur l'écran qui ouvre le tableau de
     * bord — première cause de lenteur perçue de cette application.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $declarationIds
     * @return array<int, list<array{amount: int, paid_on: CarbonImmutable}>>
     */
    protected function instalmentsOf($declarationIds): array
    {
        if ($declarationIds->isEmpty()) {
            return [];
        }

        return DB::table('declaration_payments')
            ->whereIn('declaration_id', $declarationIds)
            ->orderBy('paid_on')
            ->get(['declaration_id', 'amount', 'paid_on'])
            ->groupBy('declaration_id')
            ->map(fn ($payments): array => array_values($payments->map(fn (object $payment): array => [
                'amount' => (int) $payment->amount,
                'paid_on' => CarbonImmutable::parse((string) $payment->paid_on),
            ])->all()))
            ->all();
    }
```

Ajouter l'import `use App\Services\Declarations\PenaltyCalculator;` — même espace de noms, donc **aucun import nécessaire** ; supprimer cette ligne si l'éditeur l'ajoute.

- [ ] **Step 5 : Corriger l'appelant du digest**

`NetworkOverdueDigest` et `OverduePaymentsDigest` construisent-ils des `OverdueLine` à la main ? Vérifier :

Run : `grep -rn "new OverdueLine" app tests`

Pour chaque occurrence hors `OverduePaymentsService`, ajouter `penalty: null` — le digest n'affiche pas la pénalité dans ce lot.

- [ ] **Step 6 : Lancer les tests**

Run : `php artisan test --compact tests/Feature/Declarations/`
Expected : PASS — y compris `NotifyOverduePaymentsTest` et `NetworkOverdueDigestTest`, inchangés

- [ ] **Step 7 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/OverdueLine.php app/Services/Declarations/OverduePaymentsService.php tests/Feature/Declarations
git commit -m "feat: chiffrer la pénalité sur chaque facture en retard

Les versements sont chargés en une requête plutôt qu'une par ligne, et
le service continue de lire en query builder pour ne jamais toucher la
note privée.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5 : Bandeau et table sur le tableau de bord

**Files:**
- Modify: `app/Http/Controllers/Pharmacy/PaymentJourneyController.php`
- Modify: `resources/js/lib/fcfa.ts`
- Modify: `resources/js/pages/pharmacy/Dashboard.vue`
- Test: `tests/Feature/Pharmacy/PaymentJourneyTest.php`
- Test: `resources/js/lib/fcfa.test.ts`

**Interfaces:**
- Consumes: `OverduePaymentsService::forPharmacy()` avec `penalty` (Task 4).
- Produces: props Inertia
  - `overdue: list<array{declarationId: int, insurerName: string, insurerId: int, monthLabel: string, depositedOn: string, overdueDays: int, standardDelayDays: int, outstanding: int, penalty: int|null, insurerUrl: string}>` — **8 entrées au plus**
  - `overdueSummary: array{count: int, outstanding: int, penalty: int|null, worst: array{insurerName: string, monthLabel: string, overdueDays: int}|null, hidden: int, historyUrl: string}|null` — `null` quand rien n'est en retard

> `insurerUrl` pointe sur une route créée en **Task 7**. Cette tâche la produit déjà : le lien sera mort jusqu'à la Task 7. Implémenter les deux à la suite.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Pharmacy/PaymentJourneyTest.php` :

```php
test('a dashboard with nothing overdue shows no banner', function () {
    $user = User::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => Insurer::factory()->create(['standard_delay_days' => 30]),
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 1_000_000,
        'delay_days' => 10,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overdueSummary', null)
            ->has('overdue', 0),
        );
});

test('the banner sums the overdue invoices and names the worst', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['name' => 'Mutuelle Bénin', 'standard_delay_days' => 30]);

    // Déposée il y a 120 jours, rien encaissé : 90 jours de dépassement.
    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 4,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overdueSummary.count', 1)
            ->where('overdueSummary.outstanding', 1_000_000)
            ->where('overdueSummary.penalty', 60_000)
            ->where('overdueSummary.worst.insurerName', 'Mutuelle Bénin')
            ->where('overdueSummary.worst.overdueDays', 90)
            ->has('overdue', 1)
            ->where('overdue.0.penalty', 60_000)
            ->where('overdue.0.overdueDays', 90),
        );
});

test('an insurer without a clause leaves the penalty empty', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 4,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(120),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overdueSummary.penalty', null)
            ->where('overdue.0.penalty', null),
        );
});

test('the table shows the eight worst and says how many it hides', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    foreach (range(1, 11) as $offset) {
        Declaration::factory()->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2025,
            'period_month' => (($offset - 1) % 12) + 1,
            'amount_invoiced' => 100_000,
            'amount_received' => 0,
            'status' => DeclarationStatus::Unpaid,
            'is_status_manual' => true,
            'invoice_deposited_on' => CarbonImmutable::create(2026, 8, 15)->subDays(60 + $offset),
            'paid_on' => null,
            'delay_days' => null,
        ]);
    }

    $this->actingAs($user)
        ->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('overdue', 8)
            ->where('overdueSummary.count', 11)
            ->where('overdueSummary.hidden', 3),
        );
});
```

Ajouter les imports manquants en tête du fichier : `use App\Enums\DeclarationStatus;`.

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Pharmacy/PaymentJourneyTest.php`
Expected : FAIL — `Inertia property [overdueSummary] does not exist`

- [ ] **Step 3 : Étendre `PaymentJourneyController`**

Ajouter la constante, l'injection et les deux props :

```php
    /** Combien de lignes en retard la table montre avant de renvoyer au registre. */
    protected const OVERDUE_SHOWN = 8;
```

```php
    public function __construct(
        protected PharmacyStatsService $stats,
        protected DeclarationCalendar $calendar,
        protected OverduePaymentsService $overdue,
    ) {
        //
    }
```

Dans `__invoke()`, avant le `return` :

```php
        $overdue = $this->overdue->forPharmacy($pharmacy);
```

Et dans le tableau de props, après `'owed'` :

```php
            'overdue' => $this->overdueLines($overdue),
            'overdueSummary' => $this->overdueSummary($overdue, $pharmacy),
```

Puis les deux méthodes, après `outstandingMonths()` :

```php
    /**
     * Les pires factures en retard, prêtes à l'affichage.
     *
     * Tronquée volontairement : une officine portant quarante factures en
     * retard noierait le reste du tableau de bord, et le registre — qui sait
     * déjà filtrer par assureur — est fait pour la liste complète.
     *
     * @param  list<OverdueLine>  $overdue
     * @return list<array{declarationId: int, insurerId: int, insurerName: string, monthLabel: string, depositedOn: string, overdueDays: int, standardDelayDays: int, outstanding: int, penalty: int|null, insurerUrl: string}>
     */
    protected function overdueLines(array $overdue): array
    {
        return array_map(fn (OverdueLine $line): array => [
            'declarationId' => $line->declarationId,
            'insurerId' => $line->insurerId,
            'insurerName' => $line->insurerName,
            'monthLabel' => MonthLabel::short($line->periodMonth, $line->periodYear),
            'depositedOn' => $line->invoiceDepositedOn->toDateString(),
            // Le dépassement, pas l'âge brut : chaque assureur a son propre
            // délai, et « 120 jours » ne veut rien dire sans lui.
            'overdueDays' => $line->ageDays - $line->standardDelayDays,
            'standardDelayDays' => $line->standardDelayDays,
            'outstanding' => $line->outstanding,
            'penalty' => $line->penalty,
            'insurerUrl' => route('pharmacy.insurers.show', $line->insurerId, absolute: false),
        ], array_slice($overdue, 0, self::OVERDUE_SHOWN));
    }

    /**
     * Ce que le bandeau annonce, ou null quand rien n'est en retard.
     *
     * La pénalité annoncée ne couvre **que les factures en retard**, pas les
     * mois déjà soldés tardivement qui gardent leur pénalité acquise : un
     * bandeau chiffrant un total que la table juste en dessous ne retrouve pas
     * serait illisible. Le total réclamable vit sur la page par assureur.
     *
     * Le bandeau nomme la pire ligne plutôt que de compter au-delà d'un seuil
     * d'ancienneté : ConsoleNavigation::chaseNotice() compte déjà « au-delà de
     * 60 jours » depuis la fin du mois déclaré, là où ageDays compte depuis le
     * dépôt. Deux seuils voisins sur deux horloges se contrediraient.
     *
     * @param  list<OverdueLine>  $overdue
     * @return array{count: int, outstanding: int, penalty: int|null, worst: array{insurerName: string, monthLabel: string, overdueDays: int}|null, hidden: int, historyUrl: string}|null
     */
    protected function overdueSummary(array $overdue, Pharmacy $pharmacy): ?array
    {
        if ($overdue === []) {
            return null;
        }

        $penalties = array_filter(
            array_map(fn (OverdueLine $line): ?int => $line->penalty, $overdue),
            fn (?int $penalty): bool => $penalty !== null,
        );

        $worst = $overdue[0];

        return [
            'count' => count($overdue),
            'outstanding' => array_sum(array_map(fn (OverdueLine $line): int => $line->outstanding, $overdue)),
            'penalty' => $penalties === [] ? null : array_sum($penalties),
            'worst' => [
                'insurerName' => $worst->insurerName,
                'monthLabel' => MonthLabel::long($worst->periodMonth, $worst->periodYear),
                'overdueDays' => $worst->ageDays - $worst->standardDelayDays,
            ],
            'hidden' => max(0, count($overdue) - self::OVERDUE_SHOWN),
            'historyUrl' => route('pharmacy.history', absolute: false),
        ];
    }
```

Ajouter les imports : `use App\Data\OverdueLine;`, `use App\Services\Declarations\OverduePaymentsService;`, `use App\Support\MonthLabel;`.

- [ ] **Step 4 : Lancer les tests back-end**

Run : `php artisan test --compact tests/Feature/Pharmacy/PaymentJourneyTest.php`
Expected : FAIL sur `route('pharmacy.insurers.show')` — **attendu**, la route arrive en Task 7. Enchaîner sur l'étape 5 puis revenir ici après la Task 7.

> Pour dérouler la Task 5 seule, ajouter d'abord à `routes/web.php`, dans le groupe `pharmacy.`, la ligne de la Task 7 :
> `Route::get('insurers/{insurer}', InsurerRelationshipController::class)->name('insurers.show');`
> Elle échouera à l'appel tant que le contrôleur n'existe pas, mais `route()` la résoudra.

- [ ] **Step 5 : Écrire le bandeau et la table**

Dans `resources/js/pages/pharmacy/Dashboard.vue`, ajouter aux props :

```ts
type OverdueRow = {
    declarationId: number;
    insurerId: number;
    insurerName: string;
    monthLabel: string;
    depositedOn: string;
    overdueDays: number;
    standardDelayDays: number;
    outstanding: number;
    penalty: number | null;
    insurerUrl: string;
};

type OverdueSummary = {
    count: number;
    outstanding: number;
    penalty: number | null;
    worst: {
        insurerName: string;
        monthLabel: string;
        overdueDays: number;
    } | null;
    hidden: number;
    historyUrl: string;
};
```

```ts
    overdue: OverdueRow[];
    overdueSummary: OverdueSummary | null;
```

D'abord le garde partagé. Dans `resources/js/lib/fcfa.ts`, après `formatFcfa()` :

```ts
/**
 * Un montant de tableau, avec zéro rendu comme zéro et null comme un tiret.
 *
 * formatFcfa() rend une chaîne vide dès que la valeur est nulle ou négative,
 * ce qui convient à un champ de saisie mais pas à une cellule : « 0 » dit
 * « rien encaissé », une cellule vide ne dit rien. Le tiret est réservé à
 * l'absence de donnée — typiquement une clause de pénalité jamais convenue.
 */
export function formatAmount(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return value === 0 ? '0' : formatFcfa(value);
}
```

Et son test, `resources/js/lib/fcfa.test.ts` — Vitest, environnement `node`, pas de DOM :

```ts
import { describe, expect, it } from 'vitest';
import { formatAmount, formatFcfa } from './fcfa';

describe('formatAmount', () => {
    it('renders null as a dash, for an absent clause', () => {
        expect(formatAmount(null)).toBe('—');
    });

    it('renders zero as zero, where formatFcfa renders nothing', () => {
        expect(formatFcfa(0)).toBe('');
        expect(formatAmount(0)).toBe('0');
    });

    it('groups thousands like formatFcfa above zero', () => {
        expect(formatAmount(1_240_000)).toBe(formatFcfa(1_240_000));
    });
});
```

Run : `npm run test:js`
Expected : PASS

Puis, dans `Dashboard.vue`, importer l'icône et le garde :

```ts
import { TriangleAlert } from '@lucide/vue';
import { formatAmount } from '@/lib/fcfa';
```

Le bandeau, **tout en haut du `<template>`, avant `<section class="dashboard-intro">`** :

```vue
        <section v-if="overdueSummary" class="overdue-banner">
            <TriangleAlert class="overdue-banner-icon" :size="20" />

            <div>
                <p class="overdue-banner-headline">
                    {{ overdueSummary.count }} facture{{
                        overdueSummary.count > 1 ? 's' : ''
                    }}
                    en retard · {{ formatAmount(overdueSummary.outstanding) }} FCFA
                </p>

                <p v-if="overdueSummary.worst" class="overdue-banner-detail">
                    la plus ancienne : {{ overdueSummary.worst.insurerName }},
                    {{ overdueSummary.worst.monthLabel }}, +{{
                        overdueSummary.worst.overdueDays
                    }}
                    j<template v-if="overdueSummary.penalty !== null">
                        · pénalité courue
                        {{ formatAmount(overdueSummary.penalty) }} FCFA</template
                    >
                </p>
            </div>
        </section>
```

La table, **après `</KpiRow>` et avant `<section class="dashboard-card journey-card">`** :

```vue
        <section v-if="overdue.length > 0" class="dashboard-card overdue-card">
            <div class="card-header">
                <div class="card-title-group">
                    <div class="card-icon terracotta">
                        <TriangleAlert :size="18" />
                    </div>

                    <div>
                        <h2>Factures en retard</h2>

                        <p class="card-caption">
                            Au-delà du délai convenu avec chaque assureur,
                            compté depuis le dépôt de la facture.
                        </p>
                    </div>
                </div>
            </div>

            <DataTable
                :columns="OVERDUE_COLUMNS"
                :template="OVERDUE_TEMPLATE"
            >
                <DataTableRow
                    v-for="row in overdue"
                    :key="row.declarationId"
                    :template="OVERDUE_TEMPLATE"
                >
                    <div>
                        <Link :href="row.insurerUrl" class="overdue-insurer">
                            {{ row.insurerName }}
                        </Link>
                    </div>

                    <div>{{ row.monthLabel }}</div>

                    <div>{{ row.depositedOn }}</div>

                    <div
                        class="overdue-days"
                        :title="`Délai convenu : ${row.standardDelayDays} jours`"
                    >
                        +{{ row.overdueDays }} j
                    </div>

                    <div>{{ formatAmount(row.outstanding) }}</div>

                    <!--
                        formatAmount() rend « — » sur null et « 0 » sur zéro : « pas
                        de clause de pénalité » et « une clause mais rien
                        encore à réclamer » ne doivent pas se lire pareil.
                    -->
                    <div>{{ formatAmount(row.penalty) }}</div>
                </DataTableRow>
            </DataTable>

            <p v-if="overdueSummary && overdueSummary.hidden > 0" class="overdue-more">
                <Link :href="overdueSummary.historyUrl">
                    et {{ overdueSummary.hidden }} autre{{
                        overdueSummary.hidden > 1 ? 's' : ''
                    }}
                    dans le registre
                </Link>
            </p>
        </section>
```

Et les constantes dans le `<script setup>` :

```ts
const OVERDUE_TEMPLATE = '1.6fr .8fr 1fr .8fr 1.1fr 1.1fr';
const OVERDUE_COLUMNS = [
    'ASSUREUR',
    'MOIS',
    'DÉPOSÉE',
    'RETARD',
    'RESTE DÛ',
    'PÉNALITÉ',
];
```

Styles à ajouter dans le `<style scoped>` existant :

```css
.overdue-banner {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--color-terracotta, #c0553b);
    border-left-width: 4px;
    border-radius: 0.5rem;
    background: color-mix(in srgb, var(--color-terracotta, #c0553b) 8%, transparent);
}

.overdue-banner-icon {
    flex-shrink: 0;
    margin-top: 0.125rem;
    color: var(--color-terracotta, #c0553b);
}

.overdue-banner-headline {
    font-weight: 700;
}

.overdue-banner-detail {
    margin-top: 0.125rem;
    font-size: 0.875rem;
    opacity: 0.85;
}

.overdue-card {
    margin-bottom: 1.5rem;
}

.overdue-insurer {
    font-weight: 600;
    text-decoration: underline;
}

.overdue-days {
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    color: var(--color-terracotta, #c0553b);
}

.overdue-more {
    margin-top: 0.75rem;
    font-size: 0.875rem;
}
```

> Vérifier le nom réel de la variable de couleur dans `resources/css/app.css` et remplacer `--color-terracotta` par celui qui existe ; la classe `.card-icon.terracotta` est déjà utilisée dans ce fichier, donc la couleur existe sous un nom.

- [ ] **Step 6 : Vérifier**

Run : `npm run build && npm run types:check && npm run format:check && npm run lint:check`
Expected : tout passe

- [ ] **Step 7 : Committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Pharmacy/PaymentJourneyController.php resources/js/lib/fcfa.ts resources/js/lib/fcfa.test.ts resources/js/pages/pharmacy/Dashboard.vue tests/Feature/Pharmacy/PaymentJourneyTest.php
git commit -m "feat: mettre les impayés et les retards en tête du tableau de bord

Un bandeau qui nomme la pire facture plutôt que d'inventer un troisième
seuil d'ancienneté, et les huit pires lignes juste sous les KPI.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6 : `InsurerRelationship` et son rapport

**Files:**
- Create: `app/Data/InsurerRelationship.php`
- Create: `app/Services/Pharmacy/InsurerRelationshipReport.php`
- Test: `tests/Feature/Pharmacy/InsurerRelationshipTest.php` (partie service)

**Interfaces:**
- Consumes: `PenaltyCalculator::total()`, `LongestDelay::for()` (Task 2) ; `Period` ; `Insurer` (Task 1).
- Produces:
  - `InsurerRelationshipReport::build(Pharmacy $pharmacy, Insurer $insurer, Period $from, Period $to): array{summary: InsurerRelationship, months: list<array<string, mixed>>}`
  - `App\Data\InsurerRelationship` — voir le constructeur ci-dessous

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Pharmacy/InsurerRelationshipTest.php` avec la partie service :

```php
<?php

use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Pharmacy\InsurerRelationshipReport;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
    $this->report = app(InsurerRelationshipReport::class);
    $this->pharmacy = Pharmacy::factory()->create();
    $this->bounds = [new Period(2025, 10), new Period(2026, 9)];
});

test('nothing declared yields empty figures', function () {
    $insurer = Insurer::factory()->create();

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect($built['summary']->declarations)->toBe(0)
        ->and($built['summary']->invoiced)->toBe(0)
        ->and($built['summary']->longestDelayDays)->toBeNull()
        ->and($built['summary']->penalty)->toBeNull()
        ->and($built['months'])->toBe([]);
});

test('the longest delay can come from an open invoice rather than a settled month', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    Declaration::factory()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 500_000,
        'amount_received' => 500_000,
        'delay_days' => 40,
    ]);

    Declaration::factory()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 1,
        'amount_invoiced' => 300_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => CarbonImmutable::create(2026, 9, 19)->subDays(200),
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect($built['summary']->longestDelayDays)->toBe(200);
});

test('the claimable penalty includes a month settled late', function () {
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 60, ratePercent: 2.0)
        ->create(['standard_delay_days' => 30]);

    // Déposée le 2026-03-01, soldée le 2026-06-10 : tranches les 2026-04-30 et
    // 2026-05-30, toutes deux sur la base entière.
    Declaration::factory()->instalments([
        ['amount' => 1_000_000, 'paid_on' => '2026-06-10'],
    ])->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 2,
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-03-01',
    ]);

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect($built['summary']->penalty)->toBe(40_000);
});

test('an insurer with no clause has no penalty at all', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    Declaration::factory()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 500_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-01-01',
        'paid_on' => null,
        'delay_days' => null,
    ]);

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect($built['summary']->penalty)->toBeNull()
        ->and($built['months'][0]['penalty'])->toBeNull();
});

test('a narrower period drops what falls outside it', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    Declaration::factory()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 1,
        'amount_invoiced' => 700_000,
        'amount_received' => 700_000,
        'delay_days' => 12,
    ]);

    $built = $this->report->build($this->pharmacy, $insurer, new Period(2026, 6), new Period(2026, 9));

    expect($built['summary']->declarations)->toBe(0)
        ->and($built['summary']->invoiced)->toBe(0);
});

test('the report never carries the private note', function () {
    $insurer = Insurer::factory()->create(['standard_delay_days' => 30]);

    Declaration::factory()->create([
        'pharmacy_id' => $this->pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 7,
        'amount_invoiced' => 500_000,
        'amount_received' => 500_000,
        'delay_days' => 12,
        'private_note' => 'Relancer la comptabilite',
    ]);

    $built = $this->report->build($this->pharmacy, $insurer, ...$this->bounds);

    expect(json_encode($built['months'], JSON_THROW_ON_ERROR))
        ->not->toContain('Relancer la comptabilite');
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Pharmacy/InsurerRelationshipTest.php`
Expected : FAIL — `Target class [App\Services\Pharmacy\InsurerRelationshipReport] does not exist.`

- [ ] **Step 3 : Écrire le DTO**

Créer `app/Data/InsurerRelationship.php` :

```php
<?php

namespace App\Data;

/**
 * Ce qu'une officine a vécu avec un assureur, sur une période.
 *
 * Le pendant nominatif d'InsurerIndicators : celui-là agrège le réseau sous
 * seuil d'anonymat, celui-ci ne lit qu'une officine et ne cache rien — c'est
 * son propre dossier qui lui revient.
 */
readonly class InsurerRelationship
{
    public function __construct(
        public int $insurerId,
        public string $insurerName,
        /** Le délai de remboursement convenu, en jours. */
        public int $standardDelayDays,
        /** Le jour où la pénalité mord, ou null faute de clause. */
        public ?int $penaltyTriggerDays,
        /** Le taux par tranche de 30 jours, en pourcent, ou null. */
        public ?float $penaltyRatePercent,
        public int $declarations,
        public int $invoiced,
        public int $received,
        public int $outstanding,
        public ?float $recoveryRate,
        /** Pondéré par les montants reçus, comme partout ailleurs. */
        public ?float $weightedDelayDays,
        /**
         * Le pire retard constaté, soldé ou non.
         *
         * Voir LongestDelay : un mois réglé avec 40 jours de délai et une
         * facture ouverte depuis 200 jours donnent 200.
         */
        public ?int $longestDelayDays,
        /**
         * La pénalité réclamable sur la période, mois soldés tardivement
         * compris — là où le bandeau du tableau de bord ne compte que les
         * factures encore en retard.
         */
        public ?int $penalty,
    ) {
        //
    }
}
```

- [ ] **Step 4 : Écrire le rapport**

Créer `app/Services/Pharmacy/InsurerRelationshipReport.php` :

```php
<?php

namespace App\Services\Pharmacy;

use App\Data\InsurerRelationship;
use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Declarations\LongestDelay;
use App\Services\Declarations\PenaltyCalculator;
use App\Support\MonthLabel;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tout ce que montre l'écran d'une officine face à un assureur.
 *
 * Pas une méthode de PharmacyStatsService, et pour deux raisons que l'en-tête
 * de cette classe-là énonce : elle ne lit qu'en query builder, précisément
 * pour ne jamais charger `private_note`, et elle ne produit que des agrégats.
 * Cet écran a besoin des versements de chaque déclaration — la pénalité ne se
 * somme pas en SQL — et d'une ligne par mois. Y greffer cette lecture
 * obligerait à réécrire cet en-tête pour dire l'inverse de ce qu'il dit.
 *
 * Les colonnes sont sélectionnées explicitement et `private_note` reste dehors :
 * cet écran ne l'affiche pas, et `pharmacy/History` demeure le seul endroit où
 * la note apparaît.
 */
class InsurerRelationshipReport
{
    public function __construct(
        protected PenaltyCalculator $penalties,
        protected LongestDelay $longestDelay,
    ) {
        //
    }

    /**
     * Les agrégats et le détail mois par mois, en une seule lecture.
     *
     * Les deux sortent de la même collection : deux méthodes la chargeraient
     * deux fois, et rien ne garantirait qu'elles voient les mêmes lignes.
     *
     * @return array{summary: InsurerRelationship, months: list<array<string, mixed>>}
     */
    public function build(Pharmacy $pharmacy, Insurer $insurer, Period $from, Period $to): array
    {
        $declarations = $this->declarations($pharmacy, $insurer, $from, $to);

        return [
            'summary' => $this->summary($insurer, $declarations),
            'months' => $this->months($declarations),
        ];
    }

    /**
     * Les déclarations de la période, versements préchargés.
     *
     * Filtrées sur l'ordinal (year * 12 + month), comme
     * PharmacyExportRows::declarations() — surtout pas via la fenêtre glissante
     * de PharmacyStatsService::window(), sinon l'écran et le fichier qu'il
     * propose de télécharger ne couvriraient pas les mêmes mois.
     *
     * @return Collection<int, Declaration>
     */
    protected function declarations(Pharmacy $pharmacy, Insurer $insurer, Period $from, Period $to)
    {
        return Declaration::query()
            ->select([
                'id',
                'insurer_id',
                'period_year',
                'period_month',
                'amount_invoiced',
                'amount_received',
                'status',
                'invoice_deposited_on',
                'paid_on',
                'delay_days',
            ])
            ->with('payments')
            ->where('pharmacy_id', $pharmacy->id)
            ->where('insurer_id', $insurer->id)
            ->whereRaw(
                '(period_year * 12 + period_month) BETWEEN ? AND ?',
                [$from->toOrdinal(), $to->toOrdinal()],
            )
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get();
    }

    /**
     * @param  Collection<int, Declaration>  $declarations
     */
    protected function summary(Insurer $insurer, Collection $declarations): InsurerRelationship
    {
        $invoiced = (int) $declarations->sum('amount_invoiced');
        $received = (int) $declarations->sum('amount_received');
        $settled = $declarations->whereNotNull('delay_days');
        $basis = (int) $settled->sum('amount_received');

        // La collection est déjà chargée avec son assureur en mémoire ; le
        // passer explicitement évite à PenaltyCalculator::for() de le relire
        // une fois par ligne.
        $declarations->each(fn (Declaration $one) => $one->setRelation('insurer', $insurer));

        return new InsurerRelationship(
            insurerId: $insurer->id,
            insurerName: $insurer->name,
            standardDelayDays: $insurer->standard_delay_days,
            penaltyTriggerDays: $insurer->penalty_trigger_days,
            penaltyRatePercent: $insurer->penalty_rate_percent,
            declarations: $declarations->count(),
            invoiced: $invoiced,
            received: $received,
            outstanding: max(0, $invoiced - $received),
            recoveryRate: $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            weightedDelayDays: $basis > 0
                ? round($settled->sum(
                    fn (Declaration $one): int => (int) $one->delay_days * $one->amount_received,
                ) / $basis, 1)
                : null,
            longestDelayDays: $this->longestDelay->for($declarations),
            penalty: $this->penalties->total($declarations),
        );
    }

    /**
     * Une ligne par mois déclaré, du plus récent au plus ancien.
     *
     * @param  Collection<int, Declaration>  $declarations
     * @return list<array<string, mixed>>
     */
    protected function months(Collection $declarations): array
    {
        return array_values($declarations->map(fn (Declaration $one): array => [
            'id' => $one->id,
            'year' => $one->period_year,
            'month' => $one->period_month,
            'monthLabel' => MonthLabel::short($one->period_month, $one->period_year),
            'status' => $one->status->value,
            'statusLabel' => $one->status->label(),
            'invoiced' => $one->amount_invoiced,
            'received' => $one->amount_received,
            'outstanding' => $one->amount_outstanding,
            'depositedOn' => $one->invoice_deposited_on?->toDateString(),
            'paidOn' => $one->paid_on?->toDateString(),
            'delayDays' => $one->delay_days,
            'instalments' => $one->payments->count(),
            'penalty' => $this->penalties->for($one),
            'editUrl' => route('pharmacy.declare', [
                'insurer' => $one->insurer_id,
                'year' => $one->period_year,
                'month' => $one->period_month,
            ], absolute: false),
        ])->all());
    }
}
```

> `summary()` attache l'assureur **avant** que `months()` appelle `for()` — `build()` respecte cet ordre. Ne pas l'inverser.

- [ ] **Step 5 : Lancer les tests**

Run : `php artisan test --compact tests/Feature/Pharmacy/InsurerRelationshipTest.php`
Expected : PASS (6 tests)

- [ ] **Step 6 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/InsurerRelationship.php app/Services/Pharmacy/InsurerRelationshipReport.php tests/Feature/Pharmacy/InsurerRelationshipTest.php
git commit -m "feat: agréger la relation d'une officine avec un assureur

Classe à part plutôt qu'une méthode de PharmacyStatsService, qui ne lit
qu'en query builder pour ne jamais toucher la note privée et ne produit
que des agrégats — cet écran a besoin des deux contraires.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7 : L'écran par assureur

**Files:**
- Create: `app/Http/Controllers/Pharmacy/InsurerRelationshipController.php`
- Create: `resources/js/pages/pharmacy/Insurer.vue`
- Modify: `routes/web.php`
- Modify: `resources/js/pages/pharmacy/Insurers.vue`
- Test: `tests/Feature/Pharmacy/InsurerRelationshipTest.php` (partie écran)

**Interfaces:**
- Consumes: `InsurerRelationshipReport::build()` (Task 6), `StatsPeriod`, `PeriodPicker.vue`.
- Produces: route nommée `pharmacy.insurers.show`, page `pharmacy/Insurer`.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Pharmacy/InsurerRelationshipTest.php` :

```php
test('the screen refuses an insurer this officine never touched', function () {
    $user = User::factory()->create();
    $stranger = Insurer::factory()->create();

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $stranger))
        ->assertNotFound();
});

test('the screen opens on a ticked insurer with nothing declared', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();
    $user->currentPharmacy->insurers()->attach($insurer);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Insurer')
            ->where('relationship.declarations', 0)
            ->has('months', 0),
        );
});

test('the screen opens on an insurer declared to but no longer ticked', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 400_000,
        'amount_received' => 400_000,
        'delay_days' => 15,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationship.declarations', 1)
            ->has('months', 1),
        );
});

test('the screen restates the convention', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()
        ->withPenalty(triggerDays: 90, ratePercent: 2.5)
        ->create(['standard_delay_days' => 45]);
    $user->currentPharmacy->insurers()->attach($insurer);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationship.standardDelayDays', 45)
            ->where('relationship.penaltyTriggerDays', 90)
            ->where('relationship.penaltyRatePercent', 2.5),
        );
});

test('a period in the query string narrows the figures', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();
    $user->currentPharmacy->insurers()->attach($insurer);

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 1,
        'amount_invoiced' => 400_000,
        'amount_received' => 400_000,
        'delay_days' => 15,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $insurer).'?period=current-quarter')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('period', 'current-quarter')
            ->where('relationship.declarations', 0),
        );
});

test('the screen never carries the private note', function () {
    $user = User::factory()->create();
    $insurer = Insurer::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 400_000,
        'amount_received' => 400_000,
        'delay_days' => 15,
        'private_note' => 'Relancer la comptabilite',
    ]);

    $response = $this->actingAs($user)->get(route('pharmacy.insurers.show', $insurer));

    expect(inertiaPropsJson($response))->not->toContain('Relancer la comptabilite');
});
```

Ajouter l'import `use App\Models\User;` et `use Inertia\Testing\AssertableInertia;` en tête du fichier.

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Pharmacy/InsurerRelationshipTest.php`
Expected : FAIL — `Route [pharmacy.insurers.show] not defined.`

- [ ] **Step 3 : Déclarer la route**

Dans `routes/web.php`, dans le groupe `pharmacy.`, **après** la ligne `pharmacy.insurers.update` :

```php
        // Nommée « show » et non « exports » ou un mot réservé : Wayfinder
        // dérive le symbole TypeScript du dernier segment du nom de route.
        Route::get('insurers/{insurer}', InsurerRelationshipController::class)->name('insurers.show');
```

Ajouter l'import `use App\Http\Controllers\Pharmacy\InsurerRelationshipController;`.

- [ ] **Step 4 : Écrire le contrôleur**

Créer `app/Http/Controllers/Pharmacy/InsurerRelationshipController.php` :

```php
<?php

namespace App\Http\Controllers\Pharmacy;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Pharmacy\InsurerRelationshipReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Une officine face à un assureur : ce qu'il lui doit, et depuis quand.
 *
 * Contrôleur à part de PharmacyInsurersController, dont le métier est la case
 * à cocher « je travaille avec cet assureur ». Celui-ci n'écrit rien et ne fait
 * que lire — ce sont deux écrans, donc deux contrôleurs, comme partout dans cet
 * espace.
 */
class InsurerRelationshipController extends Controller
{
    /** La fenêtre que l'écran ouvre, tant que l'officine n'en choisit pas d'autre. */
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(protected InsurerRelationshipReport $report)
    {
        //
    }

    public function __invoke(Request $request, Insurer $insurer): Response
    {
        $pharmacy = $request->user()->currentPharmacy;

        abort_unless($this->isKnownTo($pharmacy, $insurer), 404);

        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        [$from, $to] = $period->bounds();

        $built = $this->report->build($pharmacy, $insurer, $from, $to);

        return Inertia::render('pharmacy/Insurer', [
            'relationship' => $built['summary'],
            'months' => $built['months'],
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'exportUrl' => route('pharmacy.data-exports.download', [
                'insurer' => $insurer->id,
                'period' => $period->value,
            ], absolute: false),
        ]);
    }

    /**
     * Si cet assureur regarde cette officine, d'une manière ou d'une autre.
     *
     * Déclaré **ou** coché : une officine qui a cessé de travailler avec un
     * assureur garde son passé, et une qui vient de le cocher n'a encore rien
     * déclaré. Un 404 plutôt qu'une page vide, qui se lirait « rien déclaré »
     * et non « pas votre assureur ».
     */
    protected function isKnownTo(Pharmacy $pharmacy, Insurer $insurer): bool
    {
        if ($pharmacy->insurers()->whereKey($insurer->id)->exists()) {
            return true;
        }

        return $pharmacy->declarations()->where('insurer_id', $insurer->id)->exists();
    }
}
```

- [ ] **Step 5 : Écrire la page**

Créer `resources/js/pages/pharmacy/Insurer.vue`. Structure imposée — un seul élément racine, `<style scoped>`, icônes lucide :

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Download } from '@lucide/vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { formatAmount } from '@/lib/fcfa';
import { formatMillions } from '@/lib/millions';

type Relationship = {
    insurerId: number;
    insurerName: string;
    standardDelayDays: number;
    penaltyTriggerDays: number | null;
    penaltyRatePercent: number | null;
    declarations: number;
    invoiced: number;
    received: number;
    outstanding: number;
    recoveryRate: number | null;
    weightedDelayDays: number | null;
    longestDelayDays: number | null;
    penalty: number | null;
};

type MonthRow = {
    id: number;
    monthLabel: string;
    statusLabel: string;
    invoiced: number;
    received: number;
    outstanding: number;
    depositedOn: string | null;
    paidOn: string | null;
    delayDays: number | null;
    instalments: number;
    penalty: number | null;
    editUrl: string;
};

const props = defineProps<{
    relationship: Relationship;
    months: MonthRow[];
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    exportUrl: string;
}>();

const period = ref(props.period);

watch(period, (value) => {
    router.get(
        window.location.pathname,
        { period: value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

const TEMPLATE = '.9fr .9fr 1fr 1fr 1fr .9fr .8fr .9fr .8fr';
const COLUMNS = [
    'MOIS',
    'STATUT',
    'FACTURÉ',
    'ENCAISSÉ',
    'RESTE DÛ',
    'DÉPÔT',
    'DERNIER VERS.',
    'DÉLAI',
    'PÉNALITÉ',
];
</script>

<template>
    <div class="insurer-page">
        <Head :title="relationship.insurerName" />

        <ConsoleHeader :title="relationship.insurerName" />

        <!--
            La convention est rappelée en tête parce que sans elle la colonne
            pénalité est un nombre tombé du ciel. Lecture seule : c'est
            l'APhaSPB qui la renseigne.
        -->
        <section class="convention">
            <p>
                Délai de remboursement convenu :
                <strong>{{ relationship.standardDelayDays }} jours</strong>.

                <template
                    v-if="
                        relationship.penaltyTriggerDays !== null &&
                        relationship.penaltyRatePercent !== null
                    "
                >
                    Pénalité à partir de
                    <strong>{{ relationship.penaltyTriggerDays }} jours</strong>,
                    <strong>{{ relationship.penaltyRatePercent }} %</strong>
                    par tranche de 30 jours.
                </template>

                <template v-else>
                    Aucune clause de pénalité enregistrée pour cet assureur.
                </template>
            </p>

            <p class="convention-source">
                Renseigné par l'APhaSPB d'après la convention signée.
            </p>
        </section>

        <div class="toolbar">
            <select v-model="period" aria-label="Période">
                <option
                    v-for="option in periods"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>

            <a :href="exportUrl" class="export-link">
                <Download :size="16" />

                Exporter
            </a>
        </div>

        <KpiRow :columns="3">
            <KpiCard
                label="FACTURÉ"
                :value="formatMillions(relationship.invoiced)"
                unit="FCFA"
                :hint="`${relationship.declarations} déclarations · ${periodLabel}`"
            />

            <KpiCard
                label="RESTE DÛ"
                :value="formatMillions(relationship.outstanding)"
                unit="FCFA"
                :hint="
                    relationship.recoveryRate === null
                        ? 'rien de déclaré'
                        : `${relationship.recoveryRate} % recouvrés`
                "
            />

            <KpiCard
                label="DÉLAI LE PLUS LONG"
                :value="relationship.longestDelayDays?.toString() ?? '—'"
                unit="jours"
                hint="mois réglés et encours confondus"
            />
        </KpiRow>

        <KpiRow :columns="2">
            <KpiCard
                label="VOTRE DÉLAI MOYEN"
                :value="
                    relationship.weightedDelayDays?.toLocaleString('fr-FR') ??
                    '—'
                "
                unit="jours"
                hint="pondéré par les montants reçus"
            />

            <!--
                Un tiret, jamais zéro : « pas de clause » et « une clause mais
                rien à réclamer » ne doivent pas se lire pareil.
            -->
            <KpiCard
                label="PÉNALITÉ RÉCLAMABLE"
                :value="
                    relationship.penalty === null
                        ? '—'
                        : formatMillions(relationship.penalty)
                "
                unit="FCFA"
                hint="mois soldés en retard compris"
            />
        </KpiRow>

        <DataTable :columns="COLUMNS" :template="TEMPLATE">
            <DataTableRow
                v-for="row in months"
                :key="row.id"
                :template="TEMPLATE"
            >
                <div>
                    <Link :href="row.editUrl">{{ row.monthLabel }}</Link>
                </div>

                <div>{{ row.statusLabel }}</div>

                <div>{{ formatAmount(row.invoiced) }}</div>

                <div>{{ formatAmount(row.received) }}</div>

                <div>{{ formatAmount(row.outstanding) }}</div>

                <div>{{ row.depositedOn ?? '—' }}</div>

                <div>{{ row.paidOn ?? '—' }}</div>

                <div>{{ row.delayDays === null ? '—' : `${row.delayDays} j` }}</div>

                <div>{{ formatAmount(row.penalty) }}</div>
            </DataTableRow>
        </DataTable>

        <p v-if="months.length === 0" class="empty">
            Aucune déclaration pour cet assureur sur {{ periodLabel }}.
        </p>
    </div>
</template>

<style scoped>
.convention {
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    border-radius: 0.5rem;
    background: rgb(0 0 0 / 3%);
}

.convention-source {
    margin-top: 0.25rem;
    font-size: 0.8125rem;
    opacity: 0.7;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.export-link {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-weight: 600;
}

.empty {
    padding: 2rem 0;
    text-align: center;
    opacity: 0.7;
}
</style>
```

> Confronter `ConsoleHeader`, `KpiCard`, `KpiRow`, `DataTable` et `DataTableRow` à leurs props réelles avant d'écrire — les lire dans `resources/js/components/aphaspb/` et `resources/js/layouts/console/`, ne pas deviner. Reprendre le squelette de `pharmacy/History.vue`, qui est la page la plus proche.

- [ ] **Step 6 : Lier depuis « Mes assureurs »**

Dans `resources/js/pages/pharmacy/Insurers.vue`, rendre chaque nom cliquable **seulement** pour un assureur ayant au moins une déclaration. Le contrôleur `Pharmacy\PharmacyInsurersController::edit()` doit donc exposer, par assureur, un booléen `hasDeclarations` et l'URL. Lire ce contrôleur, ajouter :

```php
'hasDeclarations' => in_array($insurer->id, $declaredInsurerIds, true),
'url' => route('pharmacy.insurers.show', $insurer->id, absolute: false),
```

où `$declaredInsurerIds` vient de `$pharmacy->declarations()->distinct()->pluck('insurer_id')->all()`.

Côté Vue, envelopper le nom :

```vue
<Link v-if="insurer.hasDeclarations" :href="insurer.url">
    {{ insurer.name }}
</Link>

<span v-else>{{ insurer.name }}</span>
```

- [ ] **Step 7 : Régénérer Wayfinder et vérifier**

Run : `npm run build && npm run types:check && npm run format:check && npm run lint:check`
Expected : tout passe. Si `types:check` sort `Property 'form' does not exist`, c'est que `php artisan wayfinder:generate` a été lancé seul — relancer `npm run build`.

- [ ] **Step 8 : Lancer la suite**

Run : `php artisan test --compact tests/Feature/Pharmacy/`
Expected : PASS — y compris les tests de la Task 5, dont le lien `insurerUrl` résout maintenant

- [ ] **Step 9 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Pharmacy/InsurerRelationshipController.php resources/js/pages/pharmacy/Insurer.vue resources/js/pages/pharmacy/Insurers.vue app/Http/Controllers/Pharmacy/PharmacyInsurersController.php routes/web.php resources/js/routes resources/js/actions tests/Feature/Pharmacy/InsurerRelationshipTest.php
git commit -m "feat: ouvrir une page par assureur pour l'officine

Convention rappelée en tête, pire retard et pénalité réclamable en KPI,
détail mois par mois. 404 sur un assureur ni déclaré ni coché plutôt
qu'une page vide qui se lirait « rien déclaré ».

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8 : Les exports officine

**Files:**
- Modify: `app/Services/Pharmacy/PharmacyExportRows.php`
- Modify: `app/Services/Pharmacy/PharmacyPdfExport.php`
- Modify: `resources/views/exports/pharmacy.blade.php`
- Test: `tests/Feature/Pharmacy/PharmacyExportTest.php`

**Interfaces:**
- Consumes: `PenaltyCalculator::for()`, `PenaltyCalculator::total()`, `LongestDelay::for()` (Task 2).
- Produces: colonnes `delai_declenchement_penalite_jours`, `taux_penalite_pct`, `penalite_fcfa` dans `PharmacyExportRows::COLUMNS` ; clés `longestDelayDays` et `penalty` dans chaque ligne de `PharmacyPdfExport::perInsurer()`.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à `tests/Feature/Pharmacy/PharmacyExportTest.php` :

```php
test('the csv carries the penalty and what produced it', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    $insurer->update(['penalty_trigger_days' => 10, 'penalty_rate_bp' => 200]);

    // Déposée le 2026-08-01, 400 000 le 06 puis 600 000 le 26. Tranches les
    // 11 et 21 août : 1 000 000 puis 600 000.
    declareSplit($pharmacy, $insurer);

    $rows = csvRowsFor($user);
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('delai_declenchement_penalite_jours'))->toBe('10')
        ->and($cell('taux_penalite_pct'))->toBe('2')
        ->and($cell('penalite_fcfa'))->toBe('32000');
});

test('an insurer without a clause leaves the three cells empty', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();

    declareSplit($pharmacy, $insurer);

    $rows = csvRowsFor($user);
    $header = $rows[0];
    $row = $rows[1];
    $cell = fn (string $column): string => $row[array_search($column, $header, true)];

    expect($cell('delai_declenchement_penalite_jours'))->toBe('')
        ->and($cell('taux_penalite_pct'))->toBe('')
        ->and($cell('penalite_fcfa'))->toBe('');
});

test('the pdf summary carries the longest delay and the penalty', function () {
    [$user, $pharmacy, $insurer] = exportingOfficine();
    $insurer->update(['penalty_trigger_days' => 10, 'penalty_rate_bp' => 200]);

    declareSplit($pharmacy, $insurer);

    $data = app(PharmacyPdfExport::class);
    $rows = (new ReflectionClass($data))->getMethod('perInsurer');
    $rows->setAccessible(true);

    $declarations = app(PharmacyExportRows::class)->declarations(
        $pharmacy,
        new Period(2025, 9),
        new Period(2026, 8),
    );

    $summary = $rows->invoke($data, $declarations);

    expect($summary[0])->toHaveKeys(['longestDelayDays', 'penalty'])
        ->and($summary[0]['penalty'])->toBe(32_000);
});
```

> `csvRowsFor(User $user, array $query = [])` est l'aide déjà présente en tête de ce fichier — elle parse le CSV et retire le BOM. Ajouter les imports `use App\Data\Period;` et `use App\Services\Pharmacy\PharmacyPdfExport;`.

- [ ] **Step 2 : Lancer pour vérifier l'échec**

Run : `php artisan test --compact tests/Feature/Pharmacy/PharmacyExportTest.php`
Expected : FAIL — `array_search()` rend `false`, la colonne n'existe pas

- [ ] **Step 3 : Étendre `PharmacyExportRows`**

Injecter le calculateur (la classe n'a pas encore de constructeur) :

```php
    public function __construct(protected PenaltyCalculator $penalties)
    {
        //
    }
```

Dans `COLUMNS`, insérer **après** `'dans_le_delai'` :

```php
        'delai_declenchement_penalite_jours',
        'taux_penalite_pct',
        'penalite_fcfa',
```

Dans `declarations()`, élargir le `select` de la relation :

```php
            ->with(['insurer:id,name,standard_delay_days,penalty_trigger_days,penalty_rate_bp', 'payments'])
```

Dans `row()`, insérer après la cellule `dans_le_delai` :

```php
            // Le délai et le taux accompagnent le montant pour le rendre
            // vérifiable : une pénalité sans le taux qui l'a produite est
            // inauditable dans un tableur.
            $declaration->insurer->penalty_trigger_days,
            $declaration->insurer->penalty_rate_percent,
            $this->penalties->for($declaration),
```

- [ ] **Step 4 : Étendre `PharmacyPdfExport`**

Injecter les deux services :

```php
    public function __construct(
        protected PharmacyExportRows $source,
        protected PharmacyStatsService $stats,
        protected PenaltyCalculator $penalties,
        protected LongestDelay $longestDelay,
    ) {
        //
    }
```

Dans `perInsurer()`, ajouter au tableau retourné :

```php
                // Calculés sur le groupe que ce fichier liste, jamais délégués
                // à InsurerRelationshipReport : une synthèse qui contredirait
                // sa propre table de détail serait pire que pas de synthèse.
                'longestDelayDays' => $this->longestDelay->for($group),
                'penalty' => $this->penalties->total($group),
```

- [ ] **Step 5 : Étendre la vue PDF**

Dans `resources/views/exports/pharmacy.blade.php`, table de synthèse (autour de la ligne 228) — ajouter deux `<th>` après `<th>Délai moyen</th>` :

```blade
                <th>Délai max</th>
                <th>Pénalité</th>
```

Et dans le `@foreach`, deux `<td>` à la même position :

```blade
                <td>{{ $row['longestDelayDays'] === null ? '—' : $row['longestDelayDays'] . ' j' }}</td>
                <td>{{ $row['penalty'] === null ? '—' : number_format($row['penalty'], 0, ',', ' ') }}</td>
```

Mettre à jour le `colspan` de la ligne « aucune donnée » (`@if (count($perInsurer) === 0)`) : huit colonnes deviennent dix.

- [ ] **Step 6 : Vérifier la mise en page PDF**

Run : `php artisan test --compact tests/Feature/Pharmacy/PharmacyExportTest.php`
Expected : PASS

La table passe de huit à dix colonnes en A4 portrait. Générer un PDF de contrôle et l'ouvrir :

```bash
php artisan tinker --execute 'file_put_contents("/tmp/controle.pdf", app(App\Services\Pharmacy\PharmacyPdfExport::class)->document(App\Models\Pharmacy::first(), new App\Data\Period(2025, 9), new App\Data\Period(2026, 8))->output());'
```

Si une colonne déborde, réduire `font-size` de `.grid` ou raccourcir les en-têtes (« Délai moy. », « Pénal. ») — ne pas passer en paysage, le reste du rapport est calibré pour le portrait.

- [ ] **Step 7 : Lancer la suite d'exports**

Run : `php artisan test --compact tests/Feature/Pharmacy/PharmacyExportTest.php tests/Feature/Admin/NetworkExportTest.php`
Expected : PASS — l'export réseau est intouché, c'est le lot B

- [ ] **Step 8 : Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Pharmacy/PharmacyExportRows.php app/Services/Pharmacy/PharmacyPdfExport.php resources/views/exports/pharmacy.blade.php tests/Feature/Pharmacy/PharmacyExportTest.php
git commit -m "feat: chiffrer la pénalité dans les exports officine

Trois colonnes par déclaration dans le CSV et le XLSX — le délai et le
taux rendent le montant vérifiable — et deux de plus dans la table de
synthèse du PDF, calculées sur les lignes que le fichier liste.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9 : Clôture

**Files:**
- Modify: aucun fichier de production — vérification et consignation

- [ ] **Step 1 : Lancer la chaîne complète**

Run : `composer ci:check`
Expected : PASS sur les six étapes (`lint:check`, `format:check`, `types:check`, `test:js`, pint, `artisan test`)

`pint --parallel --test` scanne **tout** le projet, là où `pint --dirty` ne voit que le diff : des fichiers touchés dans les tâches précédentes puis committés peuvent rester non formatés. Si `format:check` (Prettier) échoue, lancer `npm run format`.

- [ ] **Step 2 : Vérifier qu'aucun glyphe Unicode n'a été introduit**

Run : `grep -nP '[\x{25A0}-\x{25FF}]' resources/js/pages/pharmacy/Insurer.vue resources/js/pages/pharmacy/Dashboard.vue resources/js/pages/admin/Insurers.vue`
Expected : aucune sortie. Toute occurrence est une icône invisible au navigateur — la remplacer par un composant `@lucide/vue`.

- [ ] **Step 3 : Vérifier le compte de requêtes du tableau de bord**

Run : `php artisan test --compact tests/Feature/Pharmacy/PaymentJourneyTest.php tests/Feature/Declarations/OverduePaymentsServiceTest.php`
Expected : PASS, dont le test « three queries whatever their number ». Un échec ici signale un N+1 sur les versements — première cause de lenteur perçue de cette application.

- [ ] **Step 4 : Consigner les règles durables**

Trois décisions méritent de survivre à ce lot. Les enregistrer avec l'outil `record-rule` de Laravel Boost (jamais dans une note personnelle — seul `.ai/rules` est partagé avec l'équipe) :

1. glob `app/Services/Declarations/**` — titre « La pénalité n'a pas de colonne de cache, et c'est voulu » : `delay_days` est stocké parce qu'il ne dépend que des données saisies ; la pénalité croît avec le temps, donc une colonne serait fausse entre deux passages d'un job. Conséquence : elle ne se somme pas en SQL, elle exige `with('payments')`, et l'échelle réseau demandera une autre stratégie.
2. glob `app/Models/Insurer.php` — titre « Le taux de pénalité vit en points de base » : `penalty_rate_bp`, 250 = 2,50 %. `intdiv($base * $rateBp, 10000)` reste exact ; le pourcentage n'est qu'une commodité de saisie, converti dans `InsurerManagementController`. Les deux colonnes de clause sont nullables et se lisent comme un tout (`hasPenaltyClause()`).
3. glob `resources/js/pages/pharmacy/**` — titre « Trois horloges cohabitent, ne pas en inventer une quatrième » : `delay_days` et `OverdueLine::ageDays` comptent depuis le dépôt de facture ; `PharmacyStatsService::outstandingByMonth()` et `chaseNotice` comptent depuis la fin du mois déclaré. Le bandeau du tableau de bord nomme la pire ligne au lieu de compter au-delà d'un seuil, précisément pour ne pas en ajouter une troisième lecture.

- [ ] **Step 5 : Contrôle négatif du calcul**

Vérifier que la suite mord réellement : casser volontairement `PenaltyCalculator::receivedBy()` en passant la comparaison de `<=` à `<`, lancer `vendor/bin/pest tests/Unit/Services/PenaltyCalculatorTest.php`, constater l'échec de « money arriving on the tranche day itself lightens that tranche », puis rétablir.

- [ ] **Step 6 : Demander la suite complète**

Demander à l'utilisateur de lancer `php artisan test --compact` en entier et de confirmer avant d'ouvrir la PR.

---

## Ce que ce plan ne fait pas

Renvoyé au **lot B**, qui aura son propre spec et son propre plan :

- colonnes agrégées dans `NetworkExportRows` ;
- page admin par assureur, soumise au seuil d'anonymat ;
- stratégie d'agrégation de la pénalité à l'échelle du réseau.

Hors périmètre des deux lots, sauf demande explicite :

- mention de la pénalité dans `OverduePaymentsDigest` et `NetworkOverdueDigest` — `OverdueLine::penalty` leur est disponible mais n'est pas rendue ;
- refonte de `ConsoleNavigation::chaseNotice()` et de son horloge à 60 jours ;
- pénalités composées ;
- historisation des pénalités.
