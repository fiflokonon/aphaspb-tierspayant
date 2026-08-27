# Domaine métier : assureurs, déclarations, agrégation réseau (incrément 2B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Poser le cœur métier — une officine déclare, par assureur et par mois, ce qu'elle a facturé et ce qu'elle a reçu — et l'unique chemin par lequel l'APhaSPB lit ces données sous forme agrégée et anonymisée.

**Architecture:** Le statut de paiement n'est pas saisi, il se déduit du rapport reçu/facturé, avec correction manuelle possible. Toute lecture par un compte admin passe par un service unique, `NetworkStatsService`, ce qui rend la règle d'anonymat vérifiable en un point au lieu d'être répétée dans chaque contrôleur.

**Tech Stack:** Laravel 13.29, PHP 8.4, Pest 5.

**Spec:** `docs/superpowers/specs/2026-08-26-aphaspb-design-implementation-design.md` (§5.2 à §6, incrément 2 de §13)

## Global Constraints

- Nommage du code, des routes et des colonnes **en anglais** ; le français reste dans les libellés d'interface.
- Aucun identifiant de groupe Joomla hors de `config/joomla.php`.
- Montants en **FCFA entiers** : le XOF n'a pas de décimale, donc `bigint unsigned`, jamais de flottant.
- **Aucun montant rattachable à une officine, aucune note privée, aucune déclaration individuelle** ne doit être atteignable depuis un chemin admin.
- Agrégats calculés en SQL (`GROUP BY`), pas en PHP : le CDC projette 126 officines × 7 assureurs × 12 mois.
- Régénérer les routes avec `npm run build`, jamais `php artisan wayfinder:generate` seul (cf. `.ai/rules/js.md`). Ce plan ne touche aucune route, donc aucune régénération n'est nécessaire.
- `npm install` / `npm uninstall` sont cassés (cf. `.ai/rules/general.md`). Ce plan n'ajoute aucune dépendance.
- Après toute modification PHP : `vendor/bin/pint --dirty --format agent`.
- L'application n'est pas déployée : nouvelles migrations pour de nouvelles tables, pas de correctives.

## Périmètre

Ce plan est **exclusivement backend**. Aucune route, aucun contrôleur, aucun écran : l'incrément 3 les branchera sur ce que produit ce plan. Le test de confidentialité porte donc sur le service, pas sur des routes admin qui n'existent pas encore — un second test, côté routes, sera dû à l'incrément 3.

---

### Task 1: Assureurs et rattachement des officines

**Files:**
- Create: `database/migrations/2026_02_01_000001_create_insurers_table.php`
- Create: `app/Models/Insurer.php`
- Create: `database/factories/InsurerFactory.php`
- Create: `database/seeders/InsurerSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `app/Models/Pharmacy.php`
- Test: `tests/Feature/Declarations/InsurerTest.php`

**Interfaces:**
- Consumes: `Pharmacy` (incrément 2A)
- Produces:
  - Table `insurers` : `id`, `name` (unique), `is_active` (défaut vrai), timestamps
  - Table `pharmacy_insurer` : clé primaire composite `(pharmacy_id, insurer_id)`
  - `Insurer::$name`, `$is_active` ; scope `Insurer::scopeActive()`
  - `Insurer::pharmacies(): BelongsToMany`
  - `Pharmacy::insurers(): BelongsToMany`
  - `InsurerFactory`, avec état `inactive()`
  - `InsurerSeeder`, idempotent

- [x] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Declarations/InsurerTest.php`:

```php
<?php

use App\Models\Insurer;
use App\Models\Pharmacy;

test('an insurer name is unique', function () {
    Insurer::factory()->create(['name' => 'SUNU Assurances']);

    expect(fn () => Insurer::factory()->create(['name' => 'SUNU Assurances']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('an insurer is active by default', function () {
    expect(Insurer::factory()->create()->is_active)->toBeTrue()
        ->and(Insurer::factory()->inactive()->create()->is_active)->toBeFalse();
});

test('the active scope hides deactivated insurers', function () {
    Insurer::factory()->count(3)->create();
    Insurer::factory()->inactive()->count(2)->create();

    expect(Insurer::query()->active()->count())->toBe(3);
});

test('a pharmacy ticks the insurers it works with', function () {
    $pharmacy = Pharmacy::factory()->create();
    $insurers = Insurer::factory()->count(3)->create();

    $pharmacy->insurers()->attach($insurers);

    expect($pharmacy->insurers)->toHaveCount(3)
        ->and($insurers->first()->pharmacies)->toHaveCount(1);
});

test('a pharmacy cannot tick the same insurer twice', function () {
    $pharmacy = Pharmacy::factory()->create();
    $insurer = Insurer::factory()->create();

    $pharmacy->insurers()->attach($insurer);

    expect(fn () => $pharmacy->insurers()->attach($insurer))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('the seeder loads the Benin insurers and can run twice', function () {
    $this->seed(Database\Seeders\InsurerSeeder::class);
    $first = Insurer::query()->count();

    $this->seed(Database\Seeders\InsurerSeeder::class);

    expect(Insurer::query()->count())->toBe($first)
        ->and($first)->toBeGreaterThanOrEqual(6)
        ->and(Insurer::query()->where('name', 'NSIA Assurances')->exists())->toBeTrue();
});
```

- [x] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Declarations/InsurerTest.php`
Expected: FAIL — `Class "App\Models\Insurer" not found`

- [x] **Step 3: Créer la migration**

Create `database/migrations/2026_02_01_000001_create_insurers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pharmacy_insurer', function (Blueprint $table) {
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurer_id')->constrained()->cascadeOnDelete();

            $table->primary(['pharmacy_id', 'insurer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_insurer');
        Schema::dropIfExists('insurers');
    }
};
```

- [x] **Step 4: Créer le modèle**

Create `app/Models/Insurer.php`:

```php
<?php

namespace App\Models;

use Database\Factories\InsurerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'is_active'])]
class Insurer extends Model
{
    /** @use HasFactory<InsurerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Limit the query to insurers still offered in the forms.
     *
     * @param  Builder<Insurer>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the pharmacies that declared working with this insurer.
     *
     * @return BelongsToMany<Pharmacy, $this>
     */
    public function pharmacies(): BelongsToMany
    {
        return $this->belongsToMany(Pharmacy::class);
    }

    /**
     * Get the declarations recorded against this insurer.
     *
     * @return HasMany<Declaration, $this>
     */
    public function declarations(): HasMany
    {
        return $this->hasMany(Declaration::class);
    }
}
```

Note : la relation `declarations()` et l'import `HasMany` référencent un modèle créé en tâche 2. Si l'exécution est strictement séquentielle, ajouter cette méthode à la fin de la tâche 2 plutôt qu'ici.

- [x] **Step 5: Ajouter la relation côté Pharmacy**

Dans `app/Models/Pharmacy.php`, ajouter :

```php
    /**
     * Get the insurers this pharmacy works with.
     *
     * @return BelongsToMany<Insurer, $this>
     */
    public function insurers(): BelongsToMany
    {
        return $this->belongsToMany(Insurer::class);
    }
```

- [x] **Step 6: Créer la factory**

Create `database/factories/InsurerFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Insurer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insurer>
 */
class InsurerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Assurances',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the insurer no longer appears in the forms.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
```

- [x] **Step 7: Créer le seeder**

Create `database/seeders/InsurerSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Insurer;
use Illuminate\Database\Seeder;

class InsurerSeeder extends Seeder
{
    /**
     * The insurers and brokers named in the CDC and the design canvas.
     *
     * Kept as a literal list rather than a config file: the admin edits this
     * table through the interface, the seeder only bootstraps it.
     */
    protected const INSURERS = [
        'SUNU Assurances',
        'NSIA Assurances',
        "L'Africaine des Assurances",
        'Sanlam Assurances',
        'Atlantique Assurances',
        'Courtier — Ascoma Bénin',
    ];

    public function run(): void
    {
        foreach (self::INSURERS as $name) {
            Insurer::query()->firstOrCreate(['name' => $name]);
        }
    }
}
```

Dans `database/seeders/DatabaseSeeder.php`, appeler `$this->call(InsurerSeeder::class);`.

- [x] **Step 8: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Declarations/InsurerTest.php`
Expected: PASS, 6 tests

- [x] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: assureurs et rattachement des officines"
```

---

### Task 2: Déclarations et déduction du statut

**Files:**
- Create: `database/migrations/2026_02_01_000002_create_declarations_table.php`
- Create: `app/Enums/DeclarationStatus.php`
- Create: `app/Models/Declaration.php`
- Create: `database/factories/DeclarationFactory.php`
- Modify: `app/Models/Pharmacy.php`, `app/Models/Insurer.php`
- Test: `tests/Feature/Declarations/DeclarationTest.php`

**Interfaces:**
- Consumes: `Pharmacy`, `Insurer` (tâche 1)
- Produces:
  - Table `declarations` — colonnes de la spec §5.3, index unique `(pharmacy_id, insurer_id, period_year, period_month)`
  - `App\Enums\DeclarationStatus` : `Paid`, `Partial`, `Unpaid`, `Rejected` (valeurs `paid`, `partial`, `unpaid`, `rejected`), plus `label(): string` et `isSettled(): bool` (vrai pour `Paid` et `Partial` — les deux seuls statuts qui portent un délai)
  - `Declaration::deriveStatus(): DeclarationStatus` et l'application automatique à la sauvegarde
  - `Declaration::$amount_outstanding` (accesseur calculé)
  - `Declaration::scopeForPeriod(int $year, int $month)`, `scopeSettled()`
  - `Declaration::EARLIEST_MONTHS_BACK = 12`
  - `DeclarationFactory` avec états `paid()`, `partial()`, `unpaid()`, `rejected()`
  - `Pharmacy::declarations()`, `Insurer::declarations()`

- [x] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Declarations/DeclarationTest.php`:

```php
<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;

test('receiving nothing derives an unpaid status', function () {
    $declaration = Declaration::factory()->create([
        'amount_invoiced' => 1_240_000,
        'amount_received' => 0,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Unpaid);
});

test('receiving the full amount derives a paid status', function () {
    $declaration = Declaration::factory()->create([
        'amount_invoiced' => 1_240_000,
        'amount_received' => 1_240_000,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Paid);
});

test('receiving part of the amount derives a partial status', function () {
    $declaration = Declaration::factory()->create([
        'amount_invoiced' => 1_240_000,
        'amount_received' => 860_000,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Partial)
        ->and($declaration->amount_outstanding)->toBe(380_000);
});

test('a rejected status is never derived and survives a resave', function () {
    $declaration = Declaration::factory()->rejected()->create([
        'amount_invoiced' => 1_240_000,
        'amount_received' => 0,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Rejected)
        ->and($declaration->is_status_manual)->toBeTrue();

    $declaration->update(['amount_received' => 1_240_000]);

    expect($declaration->fresh()->status)->toBe(DeclarationStatus::Rejected);
});

test('a manual correction is not overwritten by the derivation', function () {
    $declaration = Declaration::factory()->create([
        'amount_invoiced' => 1_000_000,
        'amount_received' => 400_000,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Partial);

    $declaration->update([
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
    ]);

    $declaration->update(['amount_received' => 500_000]);

    expect($declaration->fresh()->status)->toBe(DeclarationStatus::Unpaid);
});

test('the outstanding amount is derived, never stored', function () {
    expect(Illuminate\Support\Facades\Schema::hasColumn('declarations', 'amount_outstanding'))->toBeFalse();
});

test('one declaration per insurer per month', function () {
    $pharmacy = Pharmacy::factory()->create();
    $insurer = Insurer::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    expect(fn () => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('the same month for two insurers is allowed', function () {
    $pharmacy = Pharmacy::factory()->create();

    Declaration::factory()->count(2)->sequence(
        ['insurer_id' => Insurer::factory()],
        ['insurer_id' => Insurer::factory()],
    )->create([
        'pharmacy_id' => $pharmacy->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    expect($pharmacy->declarations()->count())->toBe(2);
});

test('the settled scope keeps only the statuses that carry a delay', function () {
    Declaration::factory()->paid()->count(2)->create();
    Declaration::factory()->partial()->count(3)->create();
    Declaration::factory()->unpaid()->count(4)->create();
    Declaration::factory()->rejected()->count(5)->create();

    expect(Declaration::query()->settled()->count())->toBe(5);
});

test('the period scope filters on year and month together', function () {
    Declaration::factory()->create(['period_year' => 2026, 'period_month' => 8]);
    Declaration::factory()->create(['period_year' => 2025, 'period_month' => 8]);
    Declaration::factory()->create(['period_year' => 2026, 'period_month' => 7]);

    expect(Declaration::query()->forPeriod(2026, 8)->count())->toBe(1);
});

test('a private note is capped at 150 characters', function () {
    $declaration = Declaration::factory()->create([
        'private_note' => str_repeat('a', 150),
    ]);

    expect(strlen($declaration->private_note))->toBe(150);
});
```

- [x] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Declarations/DeclarationTest.php`
Expected: FAIL — `Class "App\Enums\DeclarationStatus" not found`

- [x] **Step 3: Créer l'énumération de statut**

Create `app/Enums/DeclarationStatus.php`:

```php
<?php

namespace App\Enums;

enum DeclarationStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Rejected = 'rejected';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payé',
            self::Partial => 'Partiel',
            self::Unpaid => 'Non payé',
            self::Rejected => 'Rejeté',
        };
    }

    /**
     * Determine whether the status carries a payment delay.
     *
     * Only these two feed the average-delay statistics: an unpaid or rejected
     * invoice has no payment date to measure from.
     */
    public function isSettled(): bool
    {
        return $this === self::Paid || $this === self::Partial;
    }
}
```

- [x] **Step 4: Créer la migration**

Create `database/migrations/2026_02_01_000002_create_declarations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedBigInteger('amount_invoiced')->default(0);
            $table->unsignedBigInteger('amount_received')->default(0);
            $table->string('status', 20);
            $table->boolean('is_status_manual')->default(false);
            $table->unsignedSmallInteger('delay_days')->nullable();
            $table->string('private_note', 150)->nullable();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'insurer_id', 'period_year', 'period_month']);
            $table->index(['insurer_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declarations');
    }
};
```

L'index secondaire sert les agrégats de la tâche 4, qui groupent par assureur sur une période.

- [x] **Step 5: Créer le modèle**

Create `app/Models/Declaration.php`:

```php
<?php

namespace App\Models;

use App\Enums\DeclarationStatus;
use Database\Factories\DeclarationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $pharmacy_id
 * @property int $insurer_id
 * @property int $period_year
 * @property int $period_month
 * @property int $amount_invoiced
 * @property int $amount_received
 * @property DeclarationStatus $status
 * @property bool $is_status_manual
 * @property int|null $delay_days
 * @property string|null $private_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $amount_outstanding
 * @property-read Pharmacy $pharmacy
 * @property-read Insurer $insurer
 */
#[Fillable([
    'pharmacy_id',
    'insurer_id',
    'period_year',
    'period_month',
    'amount_invoiced',
    'amount_received',
    'status',
    'is_status_manual',
    'delay_days',
    'private_note',
])]
class Declaration extends Model
{
    /** @use HasFactory<DeclarationFactory> */
    use HasFactory;

    /**
     * How far back a pharmacy may still record a missed month.
     */
    public const EARLIEST_MONTHS_BACK = 12;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Declaration $declaration) {
            if (! $declaration->is_status_manual) {
                $declaration->status = $declaration->deriveStatus();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeclarationStatus::class,
            'is_status_manual' => 'boolean',
            'amount_invoiced' => 'integer',
            'amount_received' => 'integer',
            'delay_days' => 'integer',
        ];
    }

    /**
     * Work out the status from the two amounts.
     *
     * Rejected is never derived: no pair of amounts implies an insurer refused
     * the invoice, so that status is always an explicit choice.
     */
    public function deriveStatus(): DeclarationStatus
    {
        if ($this->amount_received === 0) {
            return DeclarationStatus::Unpaid;
        }

        return $this->amount_received >= $this->amount_invoiced
            ? DeclarationStatus::Paid
            : DeclarationStatus::Partial;
    }

    /**
     * What the insurer still owes on this month.
     *
     * Derived rather than stored: a column would be a second source of truth.
     *
     * @return Attribute<int, never>
     */
    protected function amountOutstanding(): Attribute
    {
        return Attribute::get(
            fn (): int => max(0, $this->amount_invoiced - $this->amount_received),
        );
    }

    /**
     * Limit the query to a single declared month.
     *
     * @param  Builder<Declaration>  $query
     */
    #[Scope]
    protected function forPeriod(Builder $query, int $year, int $month): void
    {
        $query->where('period_year', $year)->where('period_month', $month);
    }

    /**
     * Limit the query to the statuses that carry a payment delay.
     *
     * @param  Builder<Declaration>  $query
     */
    #[Scope]
    protected function settled(Builder $query): void
    {
        $query->whereIn('status', [
            DeclarationStatus::Paid->value,
            DeclarationStatus::Partial->value,
        ]);
    }

    /**
     * @return BelongsTo<Pharmacy, $this>
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    /**
     * @return BelongsTo<Insurer, $this>
     */
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }
}
```

- [x] **Step 6: Créer la factory**

Create `database/factories/DeclarationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Declaration>
 */
class DeclarationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoiced = fake()->numberBetween(2, 40) * 50_000;

        return [
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => Insurer::factory(),
            'period_year' => 2026,
            'period_month' => fake()->numberBetween(1, 12),
            'amount_invoiced' => $invoiced,
            'amount_received' => $invoiced,
            'status' => DeclarationStatus::Paid,
            'is_status_manual' => false,
            'delay_days' => fake()->numberBetween(8, 95),
            'private_note' => null,
        ];
    }

    /**
     * Fully paid — the status the default state already produces.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_received' => $attributes['amount_invoiced'],
        ]);
    }

    /**
     * Partly paid, so a share remains outstanding.
     */
    public function partial(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_received' => (int) ($attributes['amount_invoiced'] * 0.6),
        ]);
    }

    /**
     * Nothing received, so no delay to record either.
     */
    public function unpaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_received' => 0,
            'delay_days' => null,
        ]);
    }

    /**
     * Refused by the insurer — always an explicit choice, never derived.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_received' => 0,
            'delay_days' => null,
            'status' => DeclarationStatus::Rejected,
            'is_status_manual' => true,
        ]);
    }
}
```

- [x] **Step 7: Ajouter les relations inverses**

Dans `app/Models/Pharmacy.php` et `app/Models/Insurer.php` :

```php
    /**
     * @return HasMany<Declaration, $this>
     */
    public function declarations(): HasMany
    {
        return $this->hasMany(Declaration::class);
    }
```

- [x] **Step 8: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Declarations/DeclarationTest.php`
Expected: PASS, 11 tests

- [x] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: déclarations mensuelles avec montants et statut déduit"
```

---

### Task 3: Bornes de saisie et réglages

**Files:**
- Create: `app/Rules/DeclarablePeriod.php`
- Create: `database/migrations/2026_02_01_000003_create_settings_table.php`
- Create: `app/Models/Setting.php`
- Create: `app/Services/Settings/SettingsRepository.php`
- Test: `tests/Feature/Declarations/DeclarablePeriodTest.php`, `tests/Feature/SettingsRepositoryTest.php`

**Interfaces:**
- Consumes: `Declaration::EARLIEST_MONTHS_BACK`
- Produces:
  - `App\Rules\DeclarablePeriod` — règle de validation sur un couple `[year, month]`, refuse le futur et au-delà de 12 mois en arrière
  - Table `settings` : `key` (primaire), `value`
  - `SettingsRepository::paymentDelayThresholdDays(): int` (défaut 30)
  - `SettingsRepository::anonymityMinPharmacies(): int` (défaut 5)
  - `SettingsRepository::set(string $key, int|string $value): void` — vide le cache

- [x] **Step 1: Écrire les tests qui échouent**

Create `tests/Feature/Declarations/DeclarablePeriodTest.php`:

```php
<?php

use App\Rules\DeclarablePeriod;
use Illuminate\Support\Facades\Validator;

function validatePeriod(int $year, int $month): bool
{
    return Validator::make(
        ['period' => [$year, $month]],
        ['period' => new DeclarablePeriod],
    )->passes();
}

beforeEach(fn () => $this->travelTo(Carbon\CarbonImmutable::create(2026, 8, 15)));

test('the current month is declarable', function () {
    expect(validatePeriod(2026, 8))->toBeTrue();
});

test('the twelfth month back is still declarable', function () {
    expect(validatePeriod(2025, 8))->toBeTrue();
});

test('the thirteenth month back is refused', function () {
    expect(validatePeriod(2025, 7))->toBeFalse();
});

test('a future month is refused', function () {
    expect(validatePeriod(2026, 9))->toBeFalse()
        ->and(validatePeriod(2027, 1))->toBeFalse();
});

test('a month outside one to twelve is refused', function () {
    expect(validatePeriod(2026, 0))->toBeFalse()
        ->and(validatePeriod(2026, 13))->toBeFalse();
});
```

Create `tests/Feature/SettingsRepositoryTest.php`:

```php
<?php

use App\Services\Settings\SettingsRepository;

beforeEach(fn () => $this->settings = app(SettingsRepository::class));

test('the thresholds fall back to the values the CDC sets', function () {
    expect($this->settings->paymentDelayThresholdDays())->toBe(30)
        ->and($this->settings->anonymityMinPharmacies())->toBe(5);
});

test('a stored value overrides the default', function () {
    $this->settings->set('payment_delay_threshold_days', 45);

    expect($this->settings->paymentDelayThresholdDays())->toBe(45);
});

test('writing a setting clears the cached read', function () {
    expect($this->settings->anonymityMinPharmacies())->toBe(5);

    $this->settings->set('anonymity_min_pharmacies', 8);

    expect($this->settings->anonymityMinPharmacies())->toBe(8);
});
```

- [x] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `vendor/bin/pest tests/Feature/Declarations/DeclarablePeriodTest.php tests/Feature/SettingsRepositoryTest.php`
Expected: FAIL — les classes `DeclarablePeriod` et `SettingsRepository` n'existent pas.

- [x] **Step 3: Créer la règle de période**

Create `app/Rules/DeclarablePeriod.php`:

```php
<?php

namespace App\Rules;

use App\Models\Declaration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validate a [year, month] pair a pharmacy may still declare.
 *
 * The CDC allows catching up on missed months, but only twelve back: beyond
 * that the recollection is unreliable and the statistics stop being useful.
 */
class DeclarablePeriod implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || count($value) !== 2) {
            $fail('La période doit être un couple année et mois.');

            return;
        }

        [$year, $month] = array_values($value);

        if (! is_numeric($year) || ! is_numeric($month)) {
            $fail('La période doit être numérique.');

            return;
        }

        $month = (int) $month;
        $year = (int) $year;

        if ($month < 1 || $month > 12) {
            $fail('Le mois doit être compris entre 1 et 12.');

            return;
        }

        $declared = $year * 12 + $month;
        $now = now();
        $current = $now->year * 12 + $now->month;

        if ($declared > $current) {
            $fail('Une période future ne peut pas être déclarée.');

            return;
        }

        if ($current - $declared > Declaration::EARLIEST_MONTHS_BACK) {
            $fail('Le rattrapage est limité à 12 mois en arrière.');
        }
    }
}
```

- [x] **Step 4: Créer la table et le modèle de réglages**

Create `database/migrations/2026_02_01_000003_create_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
```

Create `app/Models/Setting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';
}
```

- [x] **Step 5: Créer le dépôt de réglages**

Create `app/Services/Settings/SettingsRepository.php`:

```php
<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Read and write the two thresholds the APhaSPB admin controls.
 *
 * Cached because every aggregate query reads them, and invalidated on write so
 * a threshold change takes effect on the next request rather than at the end of
 * the cache window.
 */
class SettingsRepository
{
    public const PAYMENT_DELAY_THRESHOLD_DAYS = 'payment_delay_threshold_days';

    public const ANONYMITY_MIN_PHARMACIES = 'anonymity_min_pharmacies';

    protected const DEFAULTS = [
        self::PAYMENT_DELAY_THRESHOLD_DAYS => 30,
        self::ANONYMITY_MIN_PHARMACIES => 5,
    ];

    /**
     * The regulatory reference the network is measured against, in days.
     */
    public function paymentDelayThresholdDays(): int
    {
        return $this->integer(self::PAYMENT_DELAY_THRESHOLD_DAYS);
    }

    /**
     * How many declaring pharmacies an insurer needs before its figures show.
     */
    public function anonymityMinPharmacies(): int
    {
        return $this->integer(self::ANONYMITY_MIN_PHARMACIES);
    }

    public function set(string $key, int|string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);

        Cache::forget($this->cacheKey($key));
    }

    protected function integer(string $key): int
    {
        return (int) Cache::rememberForever(
            $this->cacheKey($key),
            fn (): string => Setting::query()->find($key)?->value
                ?? (string) self::DEFAULTS[$key],
        );
    }

    protected function cacheKey(string $key): string
    {
        return 'settings:'.$key;
    }
}
```

- [x] **Step 6: Lancer les tests pour vérifier qu'ils passent**

Run: `vendor/bin/pest tests/Feature/Declarations/DeclarablePeriodTest.php tests/Feature/SettingsRepositoryTest.php`
Expected: PASS, 8 tests

- [x] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: bornes de saisie des périodes et réglages des seuils"
```

---

### Task 4: Agrégation réseau et seuil d'anonymat

Cette tâche porte la règle de confidentialité de tout le projet. C'est le seul chemin par lequel un compte APhaSPB lira des déclarations.

**Files:**
- Create: `app/Data/InsufficientData.php`
- Create: `app/Data/InsurerIndicators.php`
- Create: `app/Data/Period.php`
- Create: `app/Services/Network/NetworkStatsService.php`
- Test: `tests/Feature/Network/NetworkStatsServiceTest.php`, `tests/Feature/Network/AnonymityThresholdTest.php`

**Interfaces:**
- Consumes: `Declaration`, `Insurer`, `Pharmacy`, `DeclarationStatus`, `SettingsRepository`
- Produces:
  - `App\Data\Period` — `__construct(int $year, int $month)`, plus `Period::lastMonths(int $count): array{0: Period, 1: Period}` renvoyant les bornes incluses
  - `App\Data\InsufficientData` — `int $declaringPharmacies`, `int $required`
  - `App\Data\InsurerIndicators` — `string $insurerName`, `int $declaringPharmacies`, `?float $averageDelayDays`, `?float $withinThresholdShare`, `?float $rejectionRate`, `?float $unpaidRate`, `int $amountInvoiced`, `int $amountReceived`, `int $amountOutstanding`, `?float $recoveryRate`
  - `NetworkStatsService::perInsurer(Period $from, Period $to, ?string $city = null): array<int, InsurerIndicators|InsufficientData>` — clés = `insurer_id`
  - `NetworkStatsService::delayTrend(int $months): array` — une entrée par assureur au-dessus du seuil, plus la moyenne réseau
  - `NetworkStatsService::aggregatedAmounts(Period $from, Period $to): array`

- [x] **Step 1: Écrire le test du seuil d'anonymat — le test qui compte le plus**

Create `tests/Feature/Network/AnonymityThresholdTest.php`:

```php
<?php

use App\Data\InsufficientData;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkStatsService;

beforeEach(function () {
    $this->service = app(NetworkStatsService::class);
    $this->from = new Period(2026, 8);
    $this->to = new Period(2026, 8);
});

/**
 * Give an insurer declarations from exactly $count distinct pharmacies.
 */
function declareFrom(Insurer $insurer, int $count): void
{
    Pharmacy::factory()->count($count)->create()->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]),
    );
}

test('an insurer just under the threshold yields no figures at all', function () {
    $insurer = Insurer::factory()->create();
    declareFrom($insurer, 4);

    $result = $this->service->perInsurer($this->from, $this->to)[$insurer->id];

    expect($result)->toBeInstanceOf(InsufficientData::class)
        ->and($result->declaringPharmacies)->toBe(4)
        ->and($result->required)->toBe(5);
});

test('an insurer at the threshold yields its indicators', function () {
    $insurer = Insurer::factory()->create();
    declareFrom($insurer, 5);

    $result = $this->service->perInsurer($this->from, $this->to)[$insurer->id];

    expect($result)->toBeInstanceOf(InsurerIndicators::class)
        ->and($result->declaringPharmacies)->toBe(5);
});

test('many declarations from few pharmacies stay below the threshold', function () {
    $insurer = Insurer::factory()->create();
    $pharmacies = Pharmacy::factory()->count(2)->create();

    foreach ($pharmacies as $pharmacy) {
        foreach (range(1, 8) as $month) {
            Declaration::factory()->paid()->create([
                'pharmacy_id' => $pharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => $month,
            ]);
        }
    }

    $result = $this->service->perInsurer(new Period(2026, 1), new Period(2026, 8))[$insurer->id];

    expect($result)->toBeInstanceOf(InsufficientData::class)
        ->and($result->declaringPharmacies)->toBe(2);
});

test('the threshold follows the admin setting', function () {
    app(App\Services\Settings\SettingsRepository::class)->set('anonymity_min_pharmacies', 3);

    $insurer = Insurer::factory()->create();
    declareFrom($insurer, 3);

    expect($this->service->perInsurer($this->from, $this->to)[$insurer->id])
        ->toBeInstanceOf(InsurerIndicators::class);
});

test('an insufficient insurer is absent from the delay trend', function () {
    $shown = Insurer::factory()->create(['name' => 'Assez de déclarants']);
    $hidden = Insurer::factory()->create(['name' => 'Trop peu de déclarants']);

    declareFrom($shown, 5);
    declareFrom($hidden, 2);

    $trend = $this->service->delayTrend(12);

    expect($trend['insurers'])->toHaveKey($shown->id)
        ->and($trend['insurers'])->not->toHaveKey($hidden->id);
});

test('no aggregate exposes anything traceable to one pharmacy', function () {
    $insurer = Insurer::factory()->create();
    $pharmacies = Pharmacy::factory()->count(5)->create();

    foreach ($pharmacies as $pharmacy) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
            'private_note' => 'note privée à ne jamais divulguer',
        ]);
    }

    $serialised = json_encode([
        $this->service->perInsurer($this->from, $this->to),
        $this->service->delayTrend(12),
        $this->service->aggregatedAmounts($this->from, $this->to),
    ], JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain('note privée')
        ->and($serialised)->not->toContain('pharmacy_id')
        ->and($serialised)->not->toContain('private_note');

    foreach ($pharmacies as $pharmacy) {
        expect($serialised)->not->toContain($pharmacy->name);
    }
});
```

- [x] **Step 2: Écrire le test des indicateurs**

Create `tests/Feature/Network/NetworkStatsServiceTest.php`:

```php
<?php

use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkStatsService;

beforeEach(function () {
    $this->service = app(NetworkStatsService::class);
    $this->insurer = Insurer::factory()->create();
});

/**
 * Record one declaration per pharmacy so the anonymity threshold is met.
 *
 * @param  list<array<string, mixed>>  $declarations
 */
function recordForDistinctPharmacies(Insurer $insurer, array $declarations): void
{
    foreach ($declarations as $attributes) {
        Declaration::factory()->create([
            ...$attributes,
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }
}

test('the average delay ignores unpaid and rejected declarations', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 20],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 40],
        ['amount_invoiced' => 100, 'amount_received' => 60, 'delay_days' => 60],
        ['amount_invoiced' => 100, 'amount_received' => 0, 'delay_days' => null],
        ['amount_invoiced' => 100, 'amount_received' => 0, 'delay_days' => null, 'status' => App\Enums\DeclarationStatus::Rejected, 'is_status_manual' => true],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators)->toBeInstanceOf(InsurerIndicators::class)
        ->and($indicators->averageDelayDays)->toBe(40.0);
});

test('the rejection and unpaid rates count against every declaration', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 0, 'delay_days' => null],
        ['amount_invoiced' => 100, 'amount_received' => 0, 'delay_days' => null, 'status' => App\Enums\DeclarationStatus::Rejected, 'is_status_manual' => true],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->unpaidRate)->toBe(20.0)
        ->and($indicators->rejectionRate)->toBe(20.0);
});

test('the within threshold share counts settled declarations under 30 days', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 10],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 30],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 31],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 90],
        ['amount_invoiced' => 100, 'amount_received' => 100, 'delay_days' => 5],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->withinThresholdShare)->toBe(60.0);
});

test('amounts are summed and the outstanding balance derived', function () {
    recordForDistinctPharmacies($this->insurer, [
        ['amount_invoiced' => 1_000_000, 'amount_received' => 1_000_000, 'delay_days' => 12],
        ['amount_invoiced' => 1_000_000, 'amount_received' => 500_000, 'delay_days' => 40],
        ['amount_invoiced' => 1_000_000, 'amount_received' => 0, 'delay_days' => null],
        ['amount_invoiced' => 1_000_000, 'amount_received' => 250_000, 'delay_days' => 55],
        ['amount_invoiced' => 1_000_000, 'amount_received' => 250_000, 'delay_days' => 55],
    ]);

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8))[$this->insurer->id];

    expect($indicators->amountInvoiced)->toBe(5_000_000)
        ->and($indicators->amountReceived)->toBe(2_000_000)
        ->and($indicators->amountOutstanding)->toBe(3_000_000)
        ->and($indicators->recoveryRate)->toBe(40.0);
});

test('the city filter narrows the aggregate to one city', function () {
    foreach (['Cotonou', 'Cotonou', 'Cotonou', 'Cotonou', 'Cotonou', 'Parakou'] as $city) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => Pharmacy::factory()->create(['city' => $city]),
            'insurer_id' => $this->insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    $indicators = $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8), 'Cotonou')[$this->insurer->id];

    expect($indicators->declaringPharmacies)->toBe(5);
});

test('the aggregation runs in a bounded number of queries', function () {
    Insurer::factory()->count(7)->create()->each(
        fn (Insurer $insurer) => Pharmacy::factory()->count(5)->create()->each(
            fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
                'pharmacy_id' => $pharmacy->id,
                'insurer_id' => $insurer->id,
                'period_year' => 2026,
                'period_month' => 8,
            ]),
        ),
    );

    Illuminate\Support\Facades\DB::enableQueryLog();

    $this->service->perInsurer(new Period(2026, 8), new Period(2026, 8));

    expect(Illuminate\Support\Facades\DB::getQueryLog())->toHaveCount(2);
});
```

Le dernier test est le garde-fou contre le N+1 : deux requêtes, quel que soit le nombre d'assureurs — une pour les agrégats groupés, une pour les noms d'assureurs.

- [x] **Step 3: Lancer les tests pour vérifier qu'ils échouent**

Run: `vendor/bin/pest tests/Feature/Network`
Expected: FAIL — `Class "App\Data\Period" not found`

- [x] **Step 4: Créer les DTO**

Create `app/Data/Period.php`:

```php
<?php

namespace App\Data;

readonly class Period
{
    public function __construct(
        public int $year,
        public int $month,
    ) {
        //
    }

    /**
     * Build the inclusive bounds of the last $count months, ending this month.
     *
     * @return array{0: self, 1: self}
     */
    public static function lastMonths(int $count): array
    {
        $end = now();
        $start = $end->copy()->subMonths(max(0, $count - 1));

        return [
            new self($start->year, $start->month),
            new self($end->year, $end->month),
        ];
    }

    /**
     * Express the period as a single sortable integer.
     *
     * Comparing (year, month) pairs in SQL is awkward; this collapses them to
     * one monotonic value so a range filter is a plain BETWEEN.
     */
    public function toOrdinal(): int
    {
        return $this->year * 12 + $this->month;
    }
}
```

Create `app/Data/InsufficientData.php`:

```php
<?php

namespace App\Data;

/**
 * Stand-in for an insurer's figures when too few pharmacies declared.
 *
 * Carries the real count so the interface can explain the state — « 3 officines
 * déclarantes, les montants s'agrègent à partir de 5 » — rather than showing an
 * error. It deliberately holds no amount, rate or delay.
 */
readonly class InsufficientData
{
    public function __construct(
        public int $declaringPharmacies,
        public int $required,
    ) {
        //
    }
}
```

Create `app/Data/InsurerIndicators.php`:

```php
<?php

namespace App\Data;

/**
 * One insurer's aggregated performance over a period.
 *
 * Every figure here is a sum or an average over at least the anonymity
 * threshold of pharmacies. No property may ever identify one of them.
 */
readonly class InsurerIndicators
{
    public function __construct(
        public string $insurerName,
        public int $declaringPharmacies,
        public ?float $averageDelayDays,
        public ?float $withinThresholdShare,
        public ?float $rejectionRate,
        public ?float $unpaidRate,
        public int $amountInvoiced,
        public int $amountReceived,
        public int $amountOutstanding,
        public ?float $recoveryRate,
    ) {
        //
    }
}
```

- [x] **Step 5: Créer le service**

Create `app/Services/Network/NetworkStatsService.php`:

```php
<?php

namespace App\Services\Network;

use App\Data\InsufficientData;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The single path by which an APhaSPB account reads declarations.
 *
 * Concentrating every aggregate here is what makes the anonymity rule
 * verifiable: one place enforces the threshold, one test covers it. No
 * controller may query the declarations table directly.
 */
class NetworkStatsService
{
    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    /**
     * Aggregate each insurer's indicators over an inclusive period range.
     *
     * @return array<int, InsurerIndicators|InsufficientData>  keyed by insurer id
     */
    public function perInsurer(Period $from, Period $to, ?string $city = null): array
    {
        $threshold = $this->settings->paymentDelayThresholdDays();
        $minimum = $this->settings->anonymityMinPharmacies();

        $rows = $this->baseQuery($from, $to, $city)
            ->select([
                'declarations.insurer_id',
                DB::raw('COUNT(DISTINCT declarations.pharmacy_id) as declaring_pharmacies'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(declarations.amount_invoiced) as amount_invoiced'),
                DB::raw('SUM(declarations.amount_received) as amount_received'),
                DB::raw("SUM(CASE WHEN declarations.status IN ('paid','partial') THEN 1 ELSE 0 END) as settled"),
                DB::raw("SUM(CASE WHEN declarations.status IN ('paid','partial') THEN declarations.delay_days ELSE 0 END) as delay_total"),
                DB::raw("SUM(CASE WHEN declarations.status IN ('paid','partial') AND declarations.delay_days <= {$threshold} THEN 1 ELSE 0 END) as within_threshold"),
                DB::raw("SUM(CASE WHEN declarations.status = 'rejected' THEN 1 ELSE 0 END) as rejected"),
                DB::raw("SUM(CASE WHEN declarations.status = 'unpaid' THEN 1 ELSE 0 END) as unpaid"),
            ])
            ->groupBy('declarations.insurer_id')
            ->get();

        $names = Insurer::query()
            ->whereIn('id', $rows->pluck('insurer_id'))
            ->pluck('name', 'id');

        $indicators = [];

        foreach ($rows as $row) {
            $declaring = (int) $row->declaring_pharmacies;

            if ($declaring < $minimum) {
                $indicators[(int) $row->insurer_id] = new InsufficientData(
                    declaringPharmacies: $declaring,
                    required: $minimum,
                );

                continue;
            }

            $total = (int) $row->total;
            $settled = (int) $row->settled;
            $invoiced = (int) $row->amount_invoiced;
            $received = (int) $row->amount_received;

            $indicators[(int) $row->insurer_id] = new InsurerIndicators(
                insurerName: (string) $names[$row->insurer_id],
                declaringPharmacies: $declaring,
                averageDelayDays: $settled > 0 ? round((int) $row->delay_total / $settled, 1) : null,
                withinThresholdShare: $settled > 0 ? round((int) $row->within_threshold / $settled * 100, 1) : null,
                rejectionRate: $total > 0 ? round((int) $row->rejected / $total * 100, 1) : null,
                unpaidRate: $total > 0 ? round((int) $row->unpaid / $total * 100, 1) : null,
                amountInvoiced: $invoiced,
                amountReceived: $received,
                amountOutstanding: max(0, $invoiced - $received),
                recoveryRate: $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            );
        }

        return $indicators;
    }

    /**
     * Monthly average delay per insurer, plus the network average.
     *
     * @return array{insurers: array<int, array{name: string, points: array<string, float>}>, network: array<string, float>, threshold: int}
     */
    public function delayTrend(int $months): array
    {
        [$from, $to] = Period::lastMonths($months);

        $eligible = array_keys(array_filter(
            $this->perInsurer($from, $to),
            fn (InsurerIndicators|InsufficientData $entry) => $entry instanceof InsurerIndicators,
        ));

        $rows = $this->baseQuery($from, $to)
            ->whereIn('declarations.insurer_id', $eligible)
            ->settledOnly()
            ->select([
                'declarations.insurer_id',
                'declarations.period_year',
                'declarations.period_month',
                DB::raw('AVG(declarations.delay_days) as average_delay'),
            ])
            ->groupBy('declarations.insurer_id', 'declarations.period_year', 'declarations.period_month')
            ->get();

        $names = Insurer::query()->whereIn('id', $eligible)->pluck('name', 'id');

        $insurers = [];
        $networkTotals = [];

        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', $row->period_year, $row->period_month);
            $insurerId = (int) $row->insurer_id;

            $insurers[$insurerId]['name'] ??= (string) $names[$insurerId];
            $insurers[$insurerId]['points'][$key] = round((float) $row->average_delay, 1);

            $networkTotals[$key][] = (float) $row->average_delay;
        }

        $network = [];
        foreach ($networkTotals as $key => $values) {
            $network[$key] = round(array_sum($values) / count($values), 1);
        }

        ksort($network);

        return [
            'insurers' => $insurers,
            'network' => $network,
            'threshold' => $this->settings->paymentDelayThresholdDays(),
        ];
    }

    /**
     * Network-wide totals over a period, in FCFA and as shares.
     *
     * @return array{invoiced: int, received: int, outstanding: int, recovery_rate: float|null, declaring_pharmacies: int}
     */
    public function aggregatedAmounts(Period $from, Period $to): array
    {
        $row = $this->baseQuery($from, $to)
            ->select([
                DB::raw('COUNT(DISTINCT declarations.pharmacy_id) as declaring_pharmacies'),
                DB::raw('SUM(declarations.amount_invoiced) as invoiced'),
                DB::raw('SUM(declarations.amount_received) as received'),
            ])
            ->first();

        $invoiced = (int) ($row->invoiced ?? 0);
        $received = (int) ($row->received ?? 0);

        return [
            'invoiced' => $invoiced,
            'received' => $received,
            'outstanding' => max(0, $invoiced - $received),
            'recovery_rate' => $invoiced > 0 ? round($received / $invoiced * 100, 1) : null,
            'declaring_pharmacies' => (int) ($row->declaring_pharmacies ?? 0),
        ];
    }

    /**
     * Build the shared filter: period range, optional city.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Declaration>
     */
    protected function baseQuery(Period $from, Period $to, ?string $city = null)
    {
        return Declaration::query()
            ->whereRaw(
                '(declarations.period_year * 12 + declarations.period_month) BETWEEN ? AND ?',
                [$from->toOrdinal(), $to->toOrdinal()],
            )
            ->when($city, fn ($query) => $query->whereExists(
                fn (QueryBuilder $sub) => $sub->from('pharmacies')
                    ->whereColumn('pharmacies.id', 'declarations.pharmacy_id')
                    ->where('pharmacies.city', $city),
            ));
    }
}
```

Note d'implémentation : `->settledOnly()` dans `delayTrend()` n'existe pas — remplacer par `->settled()`, le scope de la tâche 2. Corriger à l'écriture.

- [x] **Step 6: Lancer les tests pour vérifier qu'ils passent**

Run: `vendor/bin/pest tests/Feature/Network`
Expected: PASS, 12 tests. Si le test de comptage de requêtes échoue avec plus de 2 requêtes, une boucle interroge la base par assureur : la corriger, ne pas relever la borne.

- [x] **Step 7: Vérifier l'ensemble**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Expected: suite verte, phpstan 0 erreur.

- [x] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: agrégation réseau par assureur sous seuil d'anonymat"
```

---

## Auto-revue du plan

**Couverture de la spec :** §5.2 assureurs et rattachement (tâche 1) ; §5.3 table `declarations` (tâche 2) ; §5.4 déduction du statut et correction manuelle (tâche 2) ; §5.5 bornes de saisie (tâche 3) ; §5.6 table `settings` (tâche 3) ; §6 `NetworkStatsService`, les trois méthodes, le seuil et la règle « aucun montant rattachable » (tâche 4).

**Non couvert, et c'est voulu :** le rappel mensuel par email du 25 (CDC §3.6) — c'est une commande planifiée qui n'a de sens qu'avec des officines réelles et des déclarations manquantes à détecter ; il appartient à l'incrément 3 ou à un incrément 2C. Et l'export CSV, prévu V1.1 par le CDC.

**Cohérence des types :** `Period` est produit par la tâche 4 mais consommé uniquement en son sein et par l'incrément 3. `SettingsRepository` (tâche 3) est injecté dans `NetworkStatsService` (tâche 4). `Declaration::scopeSettled()` (tâche 2) est utilisé par `delayTrend()` (tâche 4). `DeclarationStatus::isSettled()` est défini en tâche 2 mais **n'est consommé par aucune tâche de ce plan** — il sert l'affichage de l'incrément 3 ; si l'exécutant préfère ne pas écrire de code non utilisé, le retirer et le rajouter à l'incrément 3.

**Risque principal :** les requêtes d'agrégation utilisent `DB::raw` avec des `CASE WHEN` sur des valeurs de statut écrites en dur (`'paid'`, `'partial'`). Elles doivent rester alignées sur `DeclarationStatus`. Un renommage de valeur d'énumération casserait silencieusement les statistiques, sans faire échouer le typage — c'est le test des indicateurs de la tâche 4 qui l'attrape.
