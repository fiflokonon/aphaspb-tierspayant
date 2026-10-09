# Clôture des pénalités - plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** laisser l'officine marquer « payée » ou « annulée » la pénalité d'un mois entièrement réglé, en retirer le montant de tout ce qui dit « dû », et lever la clôture d'elle-même quand une correction rouvre le mois ou change le montant.

**Architecture :** quatre colonnes de clôture sur `declarations` (enum `PenaltySettlement`), posées et levées par deux méthodes du modèle. `SettlePenalty` porte le geste et sa condition ; `ReconcilePenaltySettlement`, appelée à la fin de `RecordPaymentInstalments`, lève la clôture (cas A / cas B). `PenaltyCalculator` expose `due()` / `dueTotal()`, lus par tous les affichages « à réclamer ». Le journal et les agrégats réseau lisent la colonne de clôture dans leurs requêtes existantes.

**Tech Stack :** Laravel 13, PHP 8.4, Pest, Inertia 3, Vue 3 `<script setup>`, Tailwind 4, dompdf, OpenSpout.

**Spec :** `docs/superpowers/specs/2026-09-27-cloture-penalites-design.md`

## Global Constraints

- Montants en entiers FCFA ; aucune colonne de pénalité **courue** (règle « pas de cache ») - `penalty_settled_amount` est un fait, pas un cache.
- Les quatre colonnes de clôture se posent et se lèvent **ensemble**, uniquement par `Declaration::settlePenalty()` / `clearPenaltySettlement()` (jamais par `fill()` depuis une requête).
- Condition du geste (spec §5.1) : `amount_received >= amount_invoiced` **et** `hasPenaltyClause()` **et** `PenaltyCalculator::for() > 0`.
- Levée automatique (spec §5.3) : mois plus couvert **ou** pénalité recalculée ≠ `penalty_settled_amount`.
- Déclaration d'une autre officine → **404**. L'officine vient toujours de la session.
- `penalite_fcfa` (export officine) garde le sens **courue** ; `penalite_potentielle_fcfa` (export réseau) devient la **due**.
- Anonymat réseau : aucune nouvelle colonne pour un assureur retenu ; mois retenu du journal → nouvelles colonnes vidées.
- Null ≠ zéro : « - » = pas de convention, « 0 » = rien à réclamer.
- Tests : colonnes d'export référencées par leur nom ; décor de référence = exemple du §2 de la spec.
- Messages en français ; toasts via `Inertia::flash('toast', ['type' => …, 'message' => …])`, testés par `assertInertiaFlash`.
- Chaque tâche : `vendor/bin/pint --dirty --format agent`, PHPStan sur les fichiers touchés, commit terminé par `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Dernière tâche : `composer ci:check`.

## Review Focus

1. **Formulaire pré-rempli sur « payée » pendant une correction (cas B via le formulaire)** : la levée automatique ne doit pas être aussitôt annulée par le choix renvoyé tel quel. Le choix n'est appliqué que s'il **diffère de l'état d'avant l'enregistrement**. Test : Task 6 (`an unchanged choice does not re-settle a penalty the correction just reopened`).
2. **Déclaration rejetée après clôture** : `for()` rend null → recalcul 0 ≠ montant clos → levée. Test : Task 3 (`rejecting a settled month reopens its penalty`).
3. **Double clic / geste répété** sur un mois déjà clos de la même façon : pas de nouvelle date, pas de révision. Test : Task 4 (`settling twice the same way changes nothing`).
4. **Journal : facture close dont les tranches tombent sur plusieurs mois** - chaque tranche va à « dont payée » de son propre mois. Test : Task 8.
5. **Réseau : la due ne peut pas devenir négative** et un assureur retenu ne reçoit aucune des deux nouvelles colonnes. Test : Task 7.

---

### Task 1 : colonnes, enum, modèle, révisions

**Files :**
- Create : `app/Enums/PenaltySettlement.php`, migration `add_penalty_settlement_to_declarations_table`
- Modify : `app/Models/Declaration.php`, `app/Models/DeclarationRevision.php`, `app/Actions/Declarations/RecordDeclarationRevision.php`, `database/factories/DeclarationFactory.php`
- Test : `tests/Feature/Declarations/PenaltySettlementModelTest.php`

**Interfaces - Produces :**
- `enum PenaltySettlement: string { case Paid = 'paid'; case Waived = 'waived'; public function label(): string }` - « Payée » / « Annulée ».
- `Declaration::settlePenalty(PenaltySettlement $outcome, int $amount, User $by): void`, `clearPenaltySettlement(): void`, `isPenaltySettled(): bool`, `isFullyCovered(): bool`.
- Propriétés : `?PenaltySettlement $penalty_settlement`, `?int $penalty_settled_amount`, `?CarbonImmutable $penalty_settled_on`, `?int $penalty_settled_by`.
- Fabrique : `DeclarationFactory::penaltySettled(PenaltySettlement $outcome, int $amount)`.

- [ ] **Step 1 : test qui échoue**

```php
<?php

use App\Actions\Declarations\RecordDeclarationRevision;
use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
});

test('settling a penalty sets the four columns together, clearing unsets them together', function () {
    $user = User::factory()->create();
    $declaration = Declaration::factory()->create(['amount_invoiced' => 1_000_000, 'amount_received' => 1_000_000]);

    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);
    $fresh = $declaration->fresh();

    expect($fresh->penalty_settlement)->toBe(PenaltySettlement::Paid)
        ->and($fresh->penalty_settled_amount)->toBe(32_000)
        ->and($fresh->penalty_settled_on->toDateString())->toBe('2026-08-05')
        ->and($fresh->penalty_settled_by)->toBe($user->id)
        ->and($fresh->isPenaltySettled())->toBeTrue();

    $fresh->clearPenaltySettlement();

    expect($fresh->fresh()->only(['penalty_settlement', 'penalty_settled_amount', 'penalty_settled_on', 'penalty_settled_by']))
        ->toBe(['penalty_settlement' => null, 'penalty_settled_amount' => null, 'penalty_settled_on' => null, 'penalty_settled_by' => null]);
});

test('a settlement is part of the revision snapshot', function () {
    $user = User::factory()->create();
    $declaration = Declaration::factory()->create(['amount_invoiced' => 1_000_000, 'amount_received' => 1_000_000]);
    $revisions = app(RecordDeclarationRevision::class);

    $revisions->handle($declaration->load('payments'), $user);
    $declaration->settlePenalty(PenaltySettlement::Waived, 12_000, $user);

    expect($revisions->handle($declaration->fresh()->load('payments'), $user))->not->toBeNull()
        ->and($revisions->handle($declaration->fresh()->load('payments'), $user))->toBeNull()
        ->and($declaration->revisions()->latest('id')->first()->penalty_settlement)->toBe(PenaltySettlement::Waived);
});

test('a settlement cannot be mass-assigned', function () {
    $declaration = Declaration::factory()->create();

    $declaration->fill(['penalty_settlement' => 'paid', 'penalty_settled_amount' => 1])->save();

    expect($declaration->fresh()->penalty_settlement)->toBeNull();
});

test('a month is fully covered only when nothing is left to receive', function () {
    expect(Declaration::factory()->make(['amount_invoiced' => 100, 'amount_received' => 100])->isFullyCovered())->toBeTrue()
        ->and(Declaration::factory()->make(['amount_invoiced' => 100, 'amount_received' => 99])->isFullyCovered())->toBeFalse();
});
```

- [ ] **Step 2 : le voir échouer** - `php artisan test --compact tests/Feature/Declarations/PenaltySettlementModelTest.php` → FAIL (`PenaltySettlement` introuvable).

- [ ] **Step 3 : implémenter**

`php artisan make:migration add_penalty_settlement_to_declarations_table --no-interaction`, puis :

```php
    /**
     * La clôture d'une pénalité par l'officine : payée par l'assureur, ou
     * abandonnée. Quatre colonnes nullables, posées et levées ensemble par
     * Declaration::settlePenalty() / clearPenaltySettlement().
     *
     * `penalty_settled_amount` n'est pas un cache de la pénalité courue (qui
     * n'en a toujours pas) : c'est le montant constaté au moment du geste. Un
     * écart avec le calcul lève la clôture (ReconcilePenaltySettlement).
     *
     * La révision garde l'issue et le montant, pas l'auteur ni la date : elle
     * porte déjà les siens.
     */
    public function up(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            $table->string('penalty_settlement', 16)->nullable()->after('delay_days');
            $table->unsignedBigInteger('penalty_settled_amount')->nullable()->after('penalty_settlement');
            $table->date('penalty_settled_on')->nullable()->after('penalty_settled_amount');
            $table->foreignId('penalty_settled_by')->nullable()->after('penalty_settled_on')->constrained('users')->nullOnDelete();
        });

        Schema::table('declaration_revisions', function (Blueprint $table) {
            $table->string('penalty_settlement', 16)->nullable()->after('delay_days');
            $table->unsignedBigInteger('penalty_settled_amount')->nullable()->after('penalty_settlement');
        });
    }

    public function down(): void
    {
        Schema::table('declaration_revisions', function (Blueprint $table) {
            $table->dropColumn(['penalty_settlement', 'penalty_settled_amount']);
        });

        Schema::table('declarations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penalty_settled_by');
            $table->dropColumn(['penalty_settlement', 'penalty_settled_amount', 'penalty_settled_on']);
        });
    }
```

`app/Enums/PenaltySettlement.php` :

```php
<?php

namespace App\Enums;

/**
 * Comment une pénalité a été close par l'officine.
 *
 * Deux issues et non une : « payée » est de l'argent recouvré, « annulée » de
 * l'argent abandonné. Toutes deux sortent de la pénalité due ; le réseau les
 * compte à part.
 */
enum PenaltySettlement: string
{
    case Paid = 'paid';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payée',
            self::Waived => 'Annulée',
        };
    }
}
```

`Declaration` : PHPDoc des quatre propriétés ; casts `'penalty_settlement' => PenaltySettlement::class`, `'penalty_settled_amount' => 'integer'`, `'penalty_settled_on' => 'immutable_date'` ; **ne pas** les ajouter à `#[Fillable]` ; méthodes :

```php
    /**
     * Clore la pénalité : les quatre colonnes ensemble, jamais une seule.
     *
     * Hors de Fillable exprès : une clôture ne vient jamais d'un fill() de
     * requête, seulement de SettlePenalty, qui a vérifié la condition.
     */
    public function settlePenalty(PenaltySettlement $outcome, int $amount, User $by): void
    {
        $this->forceFill([
            'penalty_settlement' => $outcome,
            'penalty_settled_amount' => $amount,
            'penalty_settled_on' => now()->toDateString(),
            'penalty_settled_by' => $by->id,
        ])->save();
    }

    public function clearPenaltySettlement(): void
    {
        $this->forceFill([
            'penalty_settlement' => null,
            'penalty_settled_amount' => null,
            'penalty_settled_on' => null,
            'penalty_settled_by' => null,
        ])->save();
    }

    public function isPenaltySettled(): bool
    {
        return $this->penalty_settlement !== null;
    }

    /** Couvert à 100 % : la seule condition de mois sous laquelle une pénalité se clôt. */
    public function isFullyCovered(): bool
    {
        return $this->amount_received >= $this->amount_invoiced;
    }
```

`DeclarationRevision` : PHPDoc + `#[Fillable]` + casts (`PenaltySettlement::class`, `integer`) pour `penalty_settlement` et `penalty_settled_amount`.

`RecordDeclarationRevision::snapshot()` : ajouter

```php
            'penalty_settlement' => $subject->penalty_settlement?->value,
            'penalty_settled_amount' => $subject->penalty_settled_amount,
```

`DeclarationFactory` :

```php
    /**
     * Une pénalité close, sans passer par SettlePenalty : pour les décors de
     * lecture (exports, agrégats) qui n'éprouvent pas la condition du geste.
     */
    public function penaltySettled(PenaltySettlement $outcome, int $amount): static
    {
        return $this->afterCreating(fn (Declaration $declaration) => $declaration->forceFill([
            'penalty_settlement' => $outcome,
            'penalty_settled_amount' => $amount,
            'penalty_settled_on' => now()->toDateString(),
        ])->saveQuietly());
    }
```

- [ ] **Step 4 : les tests passent** - le fichier ci-dessus, plus `tests/Feature/Pharmacy/DeclarationTest.php` (révisions).
- [ ] **Step 5 : commit** - `feat: poser les colonnes de clôture de pénalité`

---

### Task 2 : la pénalité due dans `PenaltyCalculator`

**Files :** Modify `app/Services/Declarations/PenaltyCalculator.php` ; Test `tests/Unit/Services/PenaltyCalculatorTest.php`

**Interfaces - Produces :**
- `PenaltyCalculator::due(Declaration $declaration): ?int` - null si `for()` null **et** pas de clause ; 0 si close ; sinon `for()`.
- `PenaltyCalculator::dueTotal(iterable $declarations): ?int` - même règle null / zéro que `total()` (décidée sur la clause).

- [ ] **Step 1 : tests** (dans le fichier unitaire existant, avec ses helpers `insurerWith` / `declarationFor`) :

```php
test('a settled penalty is due no more, an open one is due in full', function () {
    // Déposée le 31/03, jamais réglée, on est le 19/09 : 4 tranches de 20 000.
    $open = declarationFor(insurerWith(60, 200), 1_000_000, '2026-03-31');
    $settled = declarationFor(insurerWith(60, 200), 1_000_000, '2026-03-31');
    $settled->forceFill(['penalty_settlement' => \App\Enums\PenaltySettlement::Paid, 'penalty_settled_amount' => 80_000]);

    expect($this->calculator->due($open))->toBe(80_000)
        ->and($this->calculator->due($settled))->toBe(0)
        ->and($this->calculator->for($settled))->toBe(80_000);
});

test('the due total keeps the null-or-zero rule of the accrued total', function () {
    $none = declarationFor(insurerWith(null, null), 1_000_000, '2026-03-31');
    $settled = declarationFor(insurerWith(60, 200), 1_000_000, '2026-03-31');
    $settled->forceFill(['penalty_settlement' => \App\Enums\PenaltySettlement::Waived, 'penalty_settled_amount' => 80_000]);

    expect($this->calculator->dueTotal([$none]))->toBeNull()
        ->and($this->calculator->dueTotal([$settled]))->toBe(0);
});
```

- [ ] **Step 2 : FAIL** (`due()` introuvable).
- [ ] **Step 3 : implémenter**

```php
    /**
     * Ce qu'on peut encore réclamer : la courue, sauf si l'officine l'a close.
     *
     * Une clôture efface toute la pénalité et non la seule part close : le
     * montant clos égale la courue, ReconcilePenaltySettlement le garantit en
     * levant toute clôture dont le montant ne correspond plus.
     */
    public function due(Declaration $declaration): ?int
    {
        $accrued = $this->for($declaration);

        if ($accrued === null) {
            return null;
        }

        return $declaration->isPenaltySettled() ? 0 : $accrued;
    }

    /**
     * La due d'un lot, avec la même règle null / zéro que total().
     *
     * @param  iterable<Declaration>  $declarations
     */
    public function dueTotal(iterable $declarations): ?int
    {
        $total = null;

        foreach ($declarations as $declaration) {
            if (! $declaration->insurer->hasPenaltyClause()) {
                continue;
            }

            $total = ($total ?? 0) + ($this->due($declaration) ?? 0);
        }

        return $total;
    }
```

- [ ] **Step 4 : PASS** ; **Step 5 : commit** - `feat: distinguer la pénalité due de la pénalité courue`

---

### Task 3 : `SettlePenalty` et la levée automatique

**Files :**
- Create : `app/Actions/Declarations/SettlePenalty.php`, `app/Actions/Declarations/ReconcilePenaltySettlement.php`, `app/Data/PenaltyReopened.php`
- Modify : `app/Actions/Declarations/RecordPaymentInstalments.php`
- Test : `tests/Feature/Declarations/PenaltySettlementTest.php`

**Interfaces - Produces :**
- `SettlePenalty::refusal(Declaration $declaration): ?string` - null si §5.1 tient, sinon le message.
- `SettlePenalty::settle(Declaration $declaration, PenaltySettlement $outcome, User $by): bool` - false si refusé ou déjà clos de la même façon ; true si écrit.
- `SettlePenalty::reopen(Declaration $declaration): bool` - false si rien à lever.
- `readonly class PenaltyReopened(string $reason /* 'uncovered'|'amountChanged' */, int $previousAmount, ?int $currentAmount)` + `message(string $monthLabel, string $insurerName): string`.
- `ReconcilePenaltySettlement::handle(Declaration $declaration): ?PenaltyReopened`.
- `RecordPaymentInstalments::handle(Declaration, array): ?PenaltyReopened` (était `void`).

Aucune de ces actions n'écrit de révision : leurs appelants le font (Tasks 4 et 6).

- [ ] **Step 1 : tests** - décor de référence. `referenceMonth()` va dans `tests/Pest.php` (section Functions) : les Tasks 4 à 6 le réutilisent.

`tests/Pest.php` :

```php
/**
 * L'exemple de la spec : 1 000 000 F déposés le 31/03, clause 60 j / 2 %,
 * 400 000 F le 15/06 puis 600 000 F le 20/07 → 32 000 F de pénalité.
 *
 * Le nom d'assureur est unique en base : un test qui crée deux mois de
 * référence passe un second nom.
 *
 * @param  list<array{amount: int, paid_on: string}>|null  $instalments
 */
function referenceMonth(\App\Models\User $user, ?array $instalments = null, string $insurerName = 'NSIA'): \App\Models\Declaration
{
    return \App\Models\Declaration::factory()
        ->instalments($instalments ?? referenceInstalments())
        ->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => \App\Models\Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => $insurerName])->id,
            'period_year' => 2026,
            'period_month' => 3,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-03-31',
        ])->fresh(['insurer', 'payments']);
}

/**
 * Les deux versements qui soldent le mois de référence.
 *
 * @return list<array{amount: int, paid_on: string}>
 */
function referenceInstalments(): array
{
    return [
        ['amount' => 400_000, 'paid_on' => '2026-06-15'],
        ['amount' => 600_000, 'paid_on' => '2026-07-20'],
    ];
}
```

`tests/Feature/Declarations/PenaltySettlementTest.php` :

```php
<?php

use App\Actions\Declarations\RecordPaymentInstalments;
use App\Actions\Declarations\SettlePenalty;
use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
    $this->user = User::factory()->create();
    $this->settle = app(SettlePenalty::class);
});

test('a covered month with a penalty can be marked paid, at the computed amount', function () {
    $declaration = referenceMonth($this->user);

    expect($this->settle->settle($declaration, PenaltySettlement::Paid, $this->user))->toBeTrue()
        ->and($declaration->fresh()->penalty_settled_amount)->toBe(32_000);
});

test('an uncovered month cannot be settled', function () {
    $declaration = referenceMonth($this->user, [['amount' => 400_000, 'paid_on' => '2026-06-15']]);

    expect($this->settle->refusal($declaration))->toBe("Le mois doit être entièrement réglé avant de clore sa pénalité.")
        ->and($this->settle->settle($declaration, PenaltySettlement::Paid, $this->user))->toBeFalse()
        ->and($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('a month without penalty or without clause cannot be settled', function () {
    $noClause = referenceMonth($this->user);
    $noClause->insurer->forceFill(['penalty_trigger_days' => null, 'penalty_rate_bp' => null])->save();

    $onTime = referenceMonth(User::factory()->create(), [['amount' => 1_000_000, 'paid_on' => '2026-04-10']], 'SUNU Assurances');

    expect($this->settle->refusal($noClause->fresh(['insurer', 'payments'])))->toBe("Cet assureur n'a pas de clause de pénalité.")
        ->and($this->settle->refusal($onTime))->toBe("Aucune pénalité n'a couru sur ce mois.");
});

test('settling twice the same way changes nothing', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);
    $this->travelTo(CarbonImmutable::create(2026, 8, 20));

    expect($this->settle->settle($declaration->fresh(['insurer', 'payments']), PenaltySettlement::Paid, $this->user))->toBeFalse()
        ->and($declaration->fresh()->penalty_settled_on->toDateString())->toBe('2026-08-05');
});

test('case A - a correction that reopens the month lifts the settlement', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);

    $reopened = app(RecordPaymentInstalments::class)->handle($declaration->fresh(), [
        ['amount' => 400_000, 'paid_on' => '2026-06-15'],
        ['amount' => 500_000, 'paid_on' => '2026-07-20'],
    ]);

    expect($reopened?->reason)->toBe('uncovered')
        ->and($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('case B - a correction that changes the amount lifts the settlement', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);

    $reopened = app(RecordPaymentInstalments::class)->handle($declaration->fresh(), [
        ['amount' => 400_000, 'paid_on' => '2026-05-25'],
        ['amount' => 600_000, 'paid_on' => '2026-07-20'],
    ]);

    expect($reopened?->reason)->toBe('amountChanged')
        ->and($reopened?->previousAmount)->toBe(32_000)
        ->and($reopened?->currentAmount)->toBe(24_000)
        ->and($reopened?->message('Mars 26', 'NSIA'))->toBe("La pénalité de Mars 26 (NSIA) n'est plus close : son montant a changé (24\u{202F}000 F au lieu de 32\u{202F}000 F).");
});

test('an identical re-save keeps the settlement', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);

    $reopened = app(RecordPaymentInstalments::class)->handle($declaration->fresh(), [
        ['amount' => 400_000, 'paid_on' => '2026-06-15'],
        ['amount' => 600_000, 'paid_on' => '2026-07-20'],
    ]);

    expect($reopened)->toBeNull()->and($declaration->fresh()->isPenaltySettled())->toBeTrue();
});

test('rejecting a settled month reopens its penalty', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Paid, $this->user);
    $declaration->forceFill(['status' => DeclarationStatus::Rejected, 'is_status_manual' => true])->save();

    $reopened = app(RecordPaymentInstalments::class)->handle($declaration->fresh(), [
        ['amount' => 400_000, 'paid_on' => '2026-06-15'],
        ['amount' => 600_000, 'paid_on' => '2026-07-20'],
    ]);

    expect($reopened?->reason)->toBe('amountChanged')->and($reopened?->currentAmount)->toBeNull();
});

test('reopening lifts a settlement, and says when there was none', function () {
    $declaration = referenceMonth($this->user);
    $this->settle->settle($declaration, PenaltySettlement::Waived, $this->user);

    expect($this->settle->reopen($declaration->fresh()))->toBeTrue()
        ->and($this->settle->reopen($declaration->fresh()))->toBeFalse();
});
```

(Le message pour `currentAmount` null : « … son montant a changé (aucune pénalité au lieu de 32 000 F). »)

- [ ] **Step 2 : FAIL.**
- [ ] **Step 3 : implémenter**

`app/Data/PenaltyReopened.php` :

```php
<?php

namespace App\Data;

use App\Support\Fcfa;

/**
 * Pourquoi une clôture de pénalité vient de tomber d'elle-même.
 */
readonly class PenaltyReopened
{
    public function __construct(
        /** 'uncovered' (cas A) ou 'amountChanged' (cas B) */
        public string $reason,
        public int $previousAmount,
        public ?int $currentAmount,
    ) {
        //
    }

    public function message(string $monthLabel, string $insurerName): string
    {
        $subject = "La pénalité de {$monthLabel} ({$insurerName}) n'est plus close";

        if ($this->reason === 'uncovered') {
            return "{$subject} : le mois n'est plus entièrement réglé.";
        }

        $current = $this->currentAmount === null || $this->currentAmount === 0
            ? 'aucune pénalité'
            : Fcfa::format($this->currentAmount).' F';

        return "{$subject} : son montant a changé ({$current} au lieu de ".Fcfa::format($this->previousAmount).' F).';
    }
}
```

`app/Actions/Declarations/SettlePenalty.php` :

```php
<?php

namespace App\Actions\Declarations;

use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\User;
use App\Services\Declarations\PenaltyCalculator;

/**
 * Clore une pénalité (payée, annulée) ou la remettre en dû.
 *
 * N'écrit pas de révision : ses deux appelants le font après coup, comme
 * DeclarationController::store() le fait après les versements.
 */
class SettlePenalty
{
    public function __construct(protected PenaltyCalculator $penalties)
    {
        //
    }

    /**
     * Pourquoi le geste est refusé, ou null s'il est possible (spec §5.1).
     */
    public function refusal(Declaration $declaration): ?string
    {
        $declaration->loadMissing(['insurer', 'payments']);

        if (! $declaration->insurer->hasPenaltyClause()) {
            return "Cet assureur n'a pas de clause de pénalité.";
        }

        if (! $declaration->isFullyCovered()) {
            return 'Le mois doit être entièrement réglé avant de clore sa pénalité.';
        }

        if (($this->penalties->for($declaration) ?? 0) === 0) {
            return "Aucune pénalité n'a couru sur ce mois.";
        }

        return null;
    }

    public function settle(Declaration $declaration, PenaltySettlement $outcome, User $by): bool
    {
        if ($this->refusal($declaration) !== null) {
            return false;
        }

        // Un double clic ne doit pas réécrire la date du geste.
        if ($declaration->penalty_settlement === $outcome) {
            return false;
        }

        $declaration->settlePenalty($outcome, (int) $this->penalties->for($declaration), $by);

        return true;
    }

    public function reopen(Declaration $declaration): bool
    {
        if (! $declaration->isPenaltySettled()) {
            return false;
        }

        $declaration->clearPenaltySettlement();

        return true;
    }
}
```

`app/Actions/Declarations/ReconcilePenaltySettlement.php` :

```php
<?php

namespace App\Actions\Declarations;

use App\Data\PenaltyReopened;
use App\Models\Declaration;
use App\Services\Declarations\PenaltyCalculator;

/**
 * Lever d'elle-même une clôture que le mois ne justifie plus (spec §5.3).
 *
 * Appelée à la fin de RecordPaymentInstalments, par où passe tout
 * enregistrement : le hook `saving` n'a ni les versements ni l'assureur, et ne
 * peut donc pas recalculer la pénalité.
 */
class ReconcilePenaltySettlement
{
    public function __construct(protected PenaltyCalculator $penalties)
    {
        //
    }

    public function handle(Declaration $declaration): ?PenaltyReopened
    {
        if (! $declaration->isPenaltySettled()) {
            return null;
        }

        // Relus, jamais pris en mémoire : les versements viennent d'être réécrits.
        $declaration->load(['insurer', 'payments']);
        $previous = (int) $declaration->penalty_settled_amount;

        if (! $declaration->isFullyCovered()) {
            $declaration->clearPenaltySettlement();

            return new PenaltyReopened('uncovered', $previous, null);
        }

        $current = $this->penalties->for($declaration);

        if (($current ?? 0) !== $previous) {
            $declaration->clearPenaltySettlement();

            return new PenaltyReopened('amountChanged', $previous, $current);
        }

        return null;
    }
}
```

`RecordPaymentInstalments` : injecter `ReconcilePenaltySettlement $reconcile` au constructeur (vérifier que la classe n'en a pas déjà un ; sinon en créer un avec promotion), et :

```php
    public function handle(Declaration $declaration, array $instalments): ?PenaltyReopened
    {
        return DB::transaction(function () use ($declaration, $instalments) {
            // … corps existant inchangé …

            $declaration->syncFromInstalments();

            return $this->reconcile->handle($declaration);
        });
    }
```

Mettre à jour le docblock `@return` et vérifier les appelants (`DeclarationController`, `DeclarationFactory::instalments()`) : ils ignorent le retour, rien à changer chez eux à ce stade.

- [ ] **Step 4 : PASS** + `tests/Feature/Pharmacy/DeclarationTest.php` vert.
- [ ] **Step 5 : commit** - `feat: clore une pénalité, et la rouvrir quand le mois ne la justifie plus`

---

### Task 4 : routes du geste (écran assureur, côté serveur)

**Files :**
- Create : `app/Http/Controllers/Pharmacy/PenaltySettlementController.php`
- Modify : `routes/web.php`
- Test : `tests/Feature/Pharmacy/PenaltySettlementHttpTest.php`

**Interfaces - Produces :** routes `pharmacy.penalty-settlement.store` (`POST /pharmacy/declarations/{declaration}/penalty-settlement`, champ `outcome`) et `pharmacy.penalty-settlement.destroy` (`DELETE`, même URL).

- [ ] **Step 1 : tests**

```php
<?php

use App\Enums\PenaltySettlement;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
});

test('the officine marks a penalty paid, with a revision and a toast', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);

    $this->actingAs($user)
        ->from('/pharmacy/insurers/'.$declaration->insurer_id)
        ->post(route('pharmacy.penalty-settlement.store', $declaration), ['outcome' => 'paid'])
        ->assertRedirect('/pharmacy/insurers/'.$declaration->insurer_id)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pénalité de Mars 26 marquée payée.']);

    expect($declaration->fresh()->penalty_settlement)->toBe(PenaltySettlement::Paid)
        ->and($declaration->revisions()->count())->toBe(1);
});

test('a refused settlement says why', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user, [['amount' => 400_000, 'paid_on' => '2026-06-15']]);

    $this->actingAs($user)
        ->post(route('pharmacy.penalty-settlement.store', $declaration), ['outcome' => 'waived'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Le mois doit être entièrement réglé avant de clore sa pénalité.']);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('another officine\'s declaration is not found', function () {
    $declaration = referenceMonth(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('pharmacy.penalty-settlement.store', $declaration), ['outcome' => 'paid'])
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->delete(route('pharmacy.penalty-settlement.destroy', $declaration))
        ->assertNotFound();
});

test('an unknown outcome is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('pharmacy.penalty-settlement.store', referenceMonth($user)), ['outcome' => 'forgiven'])
        ->assertSessionHasErrors('outcome');
});

test('putting a penalty back as due', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);

    $this->actingAs($user)
        ->delete(route('pharmacy.penalty-settlement.destroy', $declaration))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pénalité de Mars 26 remise en dû.']);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});
```

- [ ] **Step 2 : FAIL.**
- [ ] **Step 3 : implémenter**

```php
<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Declarations\RecordDeclarationRevision;
use App\Actions\Declarations\SettlePenalty;
use App\Enums\PenaltySettlement;
use App\Http\Controllers\Controller;
use App\Models\Declaration;
use App\Support\MonthLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Clore une pénalité depuis l'écran assureur, ou la remettre en dû.
 *
 * La déclaration d'une autre officine rend 404 et non 403 : répondre
 * « interdit » confirmerait qu'elle existe.
 */
class PenaltySettlementController extends Controller
{
    public function __construct(
        protected SettlePenalty $settle,
        protected RecordDeclarationRevision $revisions,
    ) {
        //
    }

    public function store(Request $request, Declaration $declaration): RedirectResponse
    {
        $this->ownedOrNotFound($request, $declaration);

        $outcome = PenaltySettlement::from($request->validate([
            'outcome' => ['required', Rule::enum(PenaltySettlement::class)],
        ])['outcome']);

        $refusal = $this->settle->refusal($declaration);

        if ($refusal !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusal]);

            return back();
        }

        DB::transaction(function () use ($declaration, $outcome, $request) {
            if ($this->settle->settle($declaration, $outcome, $request->user())) {
                $this->revisions->handle($declaration->load('payments'), $request->user());
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Pénalité de %s marquée %s.',
            MonthLabel::short($declaration->period_month, $declaration->period_year),
            mb_strtolower($outcome->label()),
        )]);

        return back();
    }

    public function destroy(Request $request, Declaration $declaration): RedirectResponse
    {
        $this->ownedOrNotFound($request, $declaration);

        DB::transaction(function () use ($declaration, $request) {
            if ($this->settle->reopen($declaration)) {
                $this->revisions->handle($declaration->load('payments'), $request->user());
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Pénalité de %s remise en dû.',
            MonthLabel::short($declaration->period_month, $declaration->period_year),
        )]);

        return back();
    }

    protected function ownedOrNotFound(Request $request, Declaration $declaration): void
    {
        abort_unless($declaration->pharmacy_id === $request->user()->currentPharmacy?->id, 404);
    }
}
```

Routes, groupe `pharmacy.` :

```php
        Route::post('declarations/{declaration}/penalty-settlement', [PenaltySettlementController::class, 'store'])->name('penalty-settlement.store');
        Route::delete('declarations/{declaration}/penalty-settlement', [PenaltySettlementController::class, 'destroy'])->name('penalty-settlement.destroy');
```

- [ ] **Step 4 : PASS** ; **Step 5 : commit** - `feat: clore une pénalité depuis l'écran assureur`

---

### Task 5 : écran assureur

**Files :** Modify `app/Services/Pharmacy/InsurerRelationshipReport.php`, `resources/js/pages/pharmacy/Insurer.vue` ; Test `tests/Feature/Pharmacy/InsurerRelationshipTest.php`

**Interfaces :** chaque ligne de `months` gagne `penaltyDue: int|null`, `settlement: {outcome: 'paid'|'waived', label: string, amount: int, on: string}|null`, `canSettle: bool`, `settlementUrl: string`. `relationship.penalty` devient la **due** (`dueTotal`).

- [ ] **Step 1 : tests** - dans `InsurerRelationshipTest.php`, avec `referenceMonth()` :

```php
test('a settled month is due no more, and says how it was closed', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);
    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $declaration->insurer_id).'?period=calendar-year')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('relationship.penalty', 0)
            ->where('months.0.penalty', 32_000)
            ->where('months.0.penaltyDue', 0)
            ->where('months.0.settlement.label', 'Payée')
            ->where('months.0.settlement.amount', 32_000)
            ->where('months.0.canSettle', false));
});

test('a covered month with a penalty offers the gesture, an open one does not', function () {
    $user = User::factory()->create();
    $covered = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($covered->insurer_id);

    $this->actingAs($user)
        ->get(route('pharmacy.insurers.show', $covered->insurer_id).'?period=calendar-year')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('months.0.canSettle', true)
            ->where('months.0.settlementUrl', route('pharmacy.penalty-settlement.store', $covered, absolute: false)));
});
```

Vérifier au préalable la clé réelle du filtre de période de l'écran et l'attache officine–assureur utilisée par les tests voisins de ce fichier ; s'y aligner.

- [ ] **Step 2 : FAIL.**
- [ ] **Step 3 : implémenter**
  - `declarations()` : ajouter `'penalty_settlement', 'penalty_settled_amount', 'penalty_settled_on'` au `select`.
  - `summary()` : `penalty: $this->penalties->dueTotal($declarations)`.
  - `months()` : injecter `SettlePenalty $settle` au constructeur, et ajouter :

```php
            'penaltyDue' => $this->penalties->due($one),
            'settlement' => $one->penalty_settlement === null ? null : [
                'outcome' => $one->penalty_settlement->value,
                'label' => $one->penalty_settlement->label(),
                'amount' => (int) $one->penalty_settled_amount,
                'on' => $one->penalty_settled_on?->format('d/m'),
            ],
            'canSettle' => ! $one->isPenaltySettled() && $this->settle->refusal($one) === null,
            'settlementUrl' => route('pharmacy.penalty-settlement.store', $one, absolute: false),
```

  - `Insurer.vue` : étendre `MonthRow` ; dans la cellule pénalité :

```vue
                <div class="penalty-cell">
                    <template v-if="row.settlement">
                        <span class="settlement-chip">
                            {{ row.settlement.label }}
                            <template v-if="row.settlement.outcome === 'paid'">
                                {{ formatAmount(row.settlement.amount) }}
                            </template>
                            · le {{ row.settlement.on }}
                        </span>
                        <button type="button" class="settlement-action" @click="reopen(row)">
                            Remettre en dû
                        </button>
                    </template>

                    <template v-else>
                        <span>{{ formatAmount(row.penaltyDue) }}</span>
                        <template v-if="row.canSettle">
                            <button type="button" class="settlement-action" @click="settle(row, 'paid')">
                                Marquer payée
                            </button>
                            <button type="button" class="settlement-action" @click="settle(row, 'waived')">
                                Annuler la pénalité
                            </button>
                        </template>
                    </template>
                </div>
```

```ts
const settle = (row: MonthRow, outcome: 'paid' | 'waived') =>
    router.post(row.settlementUrl, { outcome }, { preserveScroll: true });

const reopen = (row: MonthRow) =>
    router.delete(row.settlementUrl, { preserveScroll: true });
```

Styles `.penalty-cell`, `.settlement-chip`, `.settlement-action` en `<style scoped>`, jetons de couleur seulement (pastille : fond `var(--cream-header)`, texte `var(--ink)` ; bouton : texte `var(--officine)`, souligné au survol). Élargir la dernière colonne de `TEMPLATE` si les boutons débordent.

- [ ] **Step 4 : PASS** + `npm run types:check && npm run lint:check && npm run format:check`.
- [ ] **Step 5 : commit** - `feat: montrer et clore les pénalités sur l'écran assureur`

---

### Task 6 : formulaire du mois

**Files :** Modify `app/Http/Requests/Pharmacy/SaveDeclarationRequest.php`, `app/Http/Controllers/Pharmacy/DeclarationController.php`, `resources/js/pages/pharmacy/Declare.vue` ; Test `tests/Feature/Pharmacy/DeclarationPenaltySettlementTest.php`

**Interfaces :**
- Requête : `penalty_settlement` → `['nullable', Rule::in(['due', 'paid', 'waived'])]` ; absent = pas de changement.
- Prop `declaration.penalty` : `{accrued: int|null, settlement: 'paid'|'waived'|null, settledAmount: int|null, covered: bool}`.

**Règle d'application (Review Focus 1)** : dans `store()`, lire l'état de clôture **avant** la transaction. Après `RecordPaymentInstalments` (qui peut lever), appliquer le choix **seulement s'il diffère de l'état d'avant** : `due` → `reopen()` ; `paid`/`waived` → `settle()`, et si `refusal()` non null → toast `info` « Pénalité non close : {motif} ». Si `RecordPaymentInstalments` a rendu un `PenaltyReopened` et que le choix n'a rien reclos → toast `warning` avec `$reopened->message(...)`.

- [ ] **Step 1 : tests**

```php
<?php

use App\Enums\PenaltySettlement;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 5));
});

/**
 * Le formulaire tel qu'il repart pour le mois de référence.
 *
 * @param  list<array{amount: int, paid_on: string}>  $payments
 * @return array<string, mixed>
 */
function referencePayload(int $insurerId, array $payments, ?string $settlement = null): array
{
    return array_filter([
        'insurer_id' => $insurerId,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-03-31',
        'payments' => $payments,
        'penalty_settlement' => $settlement,
    ], fn ($value) => $value !== null);
}

test('the last payment and « paid » in one save close the penalty', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user, [['amount' => 400_000, 'paid_on' => '2026-06-15']]);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'paid'));

    expect($declaration->fresh()->penalty_settlement)->toBe(PenaltySettlement::Paid)
        ->and($declaration->fresh()->penalty_settled_amount)->toBe(32_000);
});

test('« paid » on a month still open is ignored, with the reason', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user, [['amount' => 400_000, 'paid_on' => '2026-06-15']]);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, [['amount' => 400_000, 'paid_on' => '2026-06-15']], 'paid'))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Pénalité non close : le mois doit être entièrement réglé avant de clore sa pénalité.']);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('an unchanged choice does not re-settle a penalty the correction just reopened', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);
    $declaration->settlePenalty(PenaltySettlement::Paid, 32_000, $user);

    // Cas B : le formulaire repart pré-rempli sur « payée ».
    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, [
            ['amount' => 400_000, 'paid_on' => '2026-05-25'],
            ['amount' => 600_000, 'paid_on' => '2026-07-20'],
        ], 'paid'))
        ->assertInertiaFlash('toast', ['type' => 'warning', 'message' => "La pénalité de Mars 26 (NSIA) n'est plus close : son montant a changé (24\u{202F}000 F au lieu de 32\u{202F}000 F)."]);

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('« due » on a settled month puts it back as due', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);
    $declaration->settlePenalty(PenaltySettlement::Waived, 32_000, $user);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), referencePayload($declaration->insurer_id, referenceInstalments(), 'due'));

    expect($declaration->fresh()->isPenaltySettled())->toBeFalse();
});

test('the form knows the penalty of the month', function () {
    $user = User::factory()->create();
    $declaration = referenceMonth($user);
    $user->currentPharmacy->insurers()->attach($declaration->insurer_id);

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['insurer' => $declaration->insurer_id, 'year' => 2026, 'month' => 3]))
        ->assertInertia(fn ($page) => $page
            ->where('declaration.penalty.accrued', 32_000)
            ->where('declaration.penalty.covered', true)
            ->where('declaration.penalty.settlement', null));
});
```

Adapter les champs obligatoires de `referencePayload` à ce que `SaveDeclarationRequest` exige réellement (lire un `post(route('pharmacy.declare.store'), …)` de `DeclarationTest.php`) : c'est le décor, pas la règle, qui s'ajuste.

- [ ] **Step 2 : FAIL.**
- [ ] **Step 3 : implémenter** - `SaveDeclarationRequest::rules()` + méthode `penaltyChoice(): ?string` ; dans `DeclarationController::store()`, injecter `SettlePenalty $settle` :

```php
        $before = Declaration::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('insurer_id', $request->integer('insurer_id'))
            ->where('period_year', $request->integer('period_year'))
            ->where('period_month', $request->integer('period_month'))
            ->value('penalty_settlement') ?? 'due';

        $notice = null;

        $declaration = DB::transaction(function () use (…, $before, &$notice) {
            $declaration = Declaration::query()->updateOrCreate(…);          // inchangé

            $reopened = $recordInstalments->handle($declaration, $request->instalments());
            $choice = $request->penaltyChoice();
            $label = MonthLabel::short($declaration->period_month, $declaration->period_year);

            // Le formulaire repart pré-rempli : un choix identique à l'état
            // d'avant n'est pas un geste, et ne doit pas reclore ce que la
            // correction vient de lever (cas B).
            if ($choice !== null && $choice !== $before) {
                if ($choice === 'due') {
                    $settle->reopen($declaration);
                } elseif (($refusal = $settle->refusal($declaration->fresh(['insurer', 'payments']))) !== null) {
                    $notice = ['type' => 'info', 'message' => 'Pénalité non close : '.lcfirst($refusal)];
                } else {
                    $settle->settle($declaration->fresh(['insurer', 'payments']), PenaltySettlement::from($choice), $request->user());
                    $reopened = null;
                }
            }

            if ($reopened !== null) {
                $notice = ['type' => 'warning', 'message' => $reopened->message($label, $declaration->insurer->name)];
            }

            $recordRevision->handle($declaration->fresh()->load('payments'), $request->user());

            return $declaration;
        });

        if ($notice !== null) {
            Inertia::flash('toast', $notice);
        }
```

(`$declaration->fresh()` avant la révision : `settle()` / `reopen()` ont écrit sur une autre instance.) `show()` : ajouter à la prop `declaration`, avec `PenaltyCalculator` injecté dans `show()` :

```php
                'penalty' => [
                    'accrued' => $penalties->for($declaration->loadMissing('insurer')),
                    'settlement' => $declaration->penalty_settlement?->value,
                    'settledAmount' => $declaration->penalty_settled_amount,
                    'covered' => $declaration->isFullyCovered(),
                ],
```

`Declare.vue` : type `penalty` dans la prop ; sous `PaymentInstalments`, une section affichée si `declaration?.penalty?.accrued` > 0 :

```vue
                    <fieldset v-if="(declaration?.penalty?.accrued ?? 0) > 0" class="penalty-panel">
                        <legend>Pénalité de ce mois · {{ formatAmount(declaration!.penalty.accrued) }}</legend>
                        <p v-if="!declaration!.penalty.covered" class="penalty-hint">
                            Possible une fois le mois entièrement réglé.
                        </p>
                        <label v-for="choice in PENALTY_CHOICES" :key="choice.value" class="penalty-choice">
                            <input v-model="penaltyChoice" type="radio" name="penalty_settlement" :value="choice.value" />
                            {{ choice.label }}
                        </label>
                    </fieldset>
```

```ts
const PENALTY_CHOICES = [
    { value: 'due', label: 'Due' },
    { value: 'paid', label: 'Payée par l’assureur' },
    { value: 'waived', label: 'Annulée' },
] as const;

const penaltyChoice = ref(props.declaration?.penalty?.settlement ?? 'due');
```

Styles scoped, jetons seulement, calqués sur `.note-content`.

- [ ] **Step 4 : PASS** + `DeclarationTest.php` + checks front.
- [ ] **Step 5 : commit** - `feat: clore la pénalité depuis le formulaire du mois`

---

### Task 7 : exports officine, agrégats et exports réseau

**Files :** Modify `app/Services/Pharmacy/PharmacyExportRows.php`, `app/Services/Pharmacy/PharmacyPdfExport.php`, `resources/views/exports/pharmacy.blade.php`, `app/Data/InsurerPenaltyFigures.php`, `app/Services/Network/InsurerPenaltyAggregates.php`, `app/Services/Network/NetworkExportRows.php`, `resources/views/exports/network.blade.php` ; Tests `tests/Feature/Pharmacy/PharmacyExportTest.php`, `tests/Feature/Network/InsurerPenaltyAggregatesTest.php`, `tests/Feature/Admin/NetworkExportTest.php`

**Interfaces :**
- `PharmacyExportRows::COLUMNS` : après `penalite_fcfa`, `penalite_statut`, `penalite_close_fcfa`, `penalite_close_le`, `penalite_due_fcfa`.
- `InsurerPenaltyFigures(?int $penalty /* due */, ?int $longestDelayDays, ?int $recovered = null, ?int $waived = null)`.
- `NetworkExportRows::COLUMNS` : après `penalite_potentielle_fcfa`, `penalite_recouvree_fcfa`, `penalite_abandonnee_fcfa`.

- [ ] **Step 1 : tests**
  - Officine (CSV) : sur le mois de référence clos « payée », `penalite_fcfa` = `32000`, `penalite_statut` = `payee`, `penalite_close_fcfa` = `32000`, `penalite_close_le` = `2026-08-05`, `penalite_due_fcfa` = `0` ; mois non clos → `due`, vides, due = courue.
  - Officine (PDF) : `PharmacyPdfExport` data : `totals.penalty` = due (0 pour le décor clos), mois de la page assureur porte `settlementLabel` = `Payée`.
  - Réseau (agrégats) : 5 officines, dont 2 mois clos payés (20 000 chacun) et 1 annulé (20 000), dans le décor « jamais réglé » du fichier → `penalty` = total − 60 000, `recovered` = 40 000, `waived` = 20 000 ; assureur sans clause → trois nulls ; la due ne descend jamais sous 0 (clos sur un mois dont le montant égale la courue) ; **nombre de requêtes inchangé** (le test existant reste vert, sans modification).
  - Réseau (CSV) : `penalite_potentielle_fcfa`, `penalite_recouvree_fcfa`, `penalite_abandonnee_fcfa` justes ; assureur retenu → les trois vides.

  Pour les décors réseau clos, utiliser `DeclarationFactory::penaltySettled()` (Task 1) sur des mois couverts ; le montant clos doit égaler la courue du décor, sinon le décor décrit un état que ReconcilePenaltySettlement n'aurait pas laissé.

- [ ] **Step 2 : FAIL.**
- [ ] **Step 3 : implémenter**
  - `PharmacyExportRows::row()` : après `$this->penalties->for($declaration)`, ajouter `$declaration->penalty_settlement === null ? ($this->penalties->for($declaration) === null ? null : 'due') : ($declaration->penalty_settlement === PenaltySettlement::Paid ? 'payee' : 'annulee')`, `$declaration->penalty_settled_amount`, `$declaration->penalty_settled_on?->toDateString()`, `$this->penalties->due($declaration)`.
  - `PharmacyPdfExport` : `totals()`, `perInsurer()`, `insurerPages()` → `dueTotal()` / `due()` ; mois de page : `'settlementLabel' => $one->penalty_settlement?->label()`. Vue `exports/pharmacy.blade.php` : libellé « Pénalité due » là où la pénalité est affichée, et la pastille sous le montant du mois si `settlementLabel`.
  - `InsurerPenaltyAggregates::penaltiesByInsurer()` : `select` + `declarations.penalty_settlement` ; accumuler trois totaux : due (non clos), recouvrée (`paid`), abandonnée (`waived`), en rendant `array<int, array{0: int, 1: int, 2: int}>` ; `forInsurers()` construit `InsurerPenaltyFigures(due, delay, recovered, waived)`, les trois nulls sans clause.
  - `NetworkExportRows::full()` : `$figures->penalty, $figures->recovered, $figures->waived` en fin de ligne ; `withheld()` remplit déjà tout de null.
  - `exports/network.blade.php` : « Pénalité potentielle » → « Pénalité due », et une ligne sous la valeur « dont recouvrée X · abandonnée Y » quand l'un des deux est > 0.

- [ ] **Step 4 : PASS** (fichiers ci-dessus + `NetworkXlsxExportTest.php`).
- [ ] **Step 5 : commit** - `feat: compter la pénalité due, recouvrée et abandonnée dans les exports`

---

### Task 8 : journal mensuel

**Files :** Modify `app/Data/PenaltyLedgerMonth.php`, `app/Services/Declarations/PenaltyTally.php`, `app/Services/Pharmacy/PharmacyPenaltyLedger.php`, `app/Services/Network/NetworkPenaltyLedger.php`, `app/Services/Pharmacy/PharmacyPenaltyLedgerRows.php`, `app/Services/Network/NetworkPenaltyLedgerRows.php`, les deux vues PDF du journal, `resources/js/types/aphaspb.ts`, `resources/js/lib/penaltySeries.ts` (+ test), `resources/js/components/aphaspb/PenaltyTrendCard.vue`, `resources/js/components/aphaspb/PenaltyLedgerTable.vue` ; Tests `tests/Unit/Services/PenaltyTallyTest.php`, `tests/Feature/Pharmacy/PharmacyPenaltyLedgerTest.php`, `tests/Feature/Network/NetworkPenaltyJournalTest.php`, `resources/js/lib/penaltySeries.test.ts`

**Interfaces :**
- `PenaltyTally::add(…, array $payments, ?PenaltySettlement $settlement = null)` (nouveau dernier paramètre).
- `PenaltyLedgerMonth` gagne `?int $accruedPaid`, `?int $accruedWaived`, `?int $accruedDue`, `?int $declaredDue` (même règle de null : futur ou retenu → null) ; `toArray()` les expose.
- `PenaltyView` gagne `'due'` ; `penaltyChartRows` lit `accruedDue` pour cette vue.
- Colonnes d'export du journal : après `penalite_mois_declare`, `dont_payee`, `dont_annulee`, `reste_due` (horloge « couru »).

- [ ] **Step 1 : tests**
  - Tally : facture 31/03 close « payée » (tranches mai–août) → `accruedPaid` = 20 000 dans chacun des quatre mois, `accruedDue` = 0, `declaredDue` de mars = 0 ; facture non close → `accruedDue` = `accrued` ; « annulée » → `accruedWaived`.
  - Officine : lecture de `penalty_settlement` depuis la base (décor `penaltySettled()`).
  - Réseau : mois retenu → les quatre nouveaux champs à null ; nombre de requêtes du tally toujours 3.
  - Vitest : `penaltyChartRows(…, 'due')` lit `accruedDue` ; `isPenaltyView('due')` vrai.
  - Export : `dont_payee` / `reste_due` justes ; ligne retenue → vides.
- [ ] **Step 2 : FAIL.**
- [ ] **Step 3 : implémenter**
  - Tally : trois tableaux par assureur et pour le total (`accruedPaid`, `accruedWaived`, `declaredSettled`) ; dans `add()`, si `$settlement` : chaque tranche en période va aussi dans `accruedPaid`/`accruedWaived` de son mois, et la somme de la facture dans `declaredSettled` de son mois déclaré. `series()` calcule `accruedDue = accrued − paid − waived`, `declaredDue = declared − declaredSettled`, et met les quatre à null quand le mois est futur ou retenu.
  - Lecteurs : ajouter `declarations.penalty_settlement` aux `select` et passer `PenaltySettlement::tryFrom((string) $row->penalty_settlement)`.
  - Front : `PenaltyView` + `isPenaltyView`, troisième entrée de `VIEWS` (« Reste due »), `pick()` dans `penaltySeries.ts`, trois colonnes dans `PenaltyLedgerTable.vue` (masquées sous 720 px si la table déborde - vérifier au navigateur).
  - Exports du journal et vues PDF : trois colonnes, mêmes règles de rendu (`retenu`, « - »).
- [ ] **Step 4 : PASS** (tous les fichiers du journal + Vitest + checks front).
- [ ] **Step 5 : commit** - `feat: dire dans le journal ce qui a été payé, annulé, et ce qui reste dû`

---

### Task 9 : règles, spec, vérification complète

- [ ] **Step 1** - `record-rule` :
  1. `app/Actions/Declarations/**` - « Une clôture de pénalité tombe d'elle-même » : ReconcilePenaltySettlement à la fin de RecordPaymentInstalments ; cas A / cas B ; colonnes hors Fillable ; aucune action de clôture n'écrit de révision, les appelants le font.
  2. `app/Http/Controllers/Pharmacy/DeclarationController.php` - « Le choix de pénalité du formulaire n'agit que s'il change » : comparaison à l'état d'avant l'enregistrement, sinon le cas B est aussitôt reclos.
  3. `app/Services/**` - « Courue, close, due » : `penalite_fcfa` = courue, `penalite_potentielle_fcfa` = due ; `due()` rend 0 pour une close car le montant clos égale la courue par construction.
- [ ] **Step 2** - spec : §10 « Écarts » avec tout écart décidé à l'exécution.
- [ ] **Step 3** - `composer ci:check` vert ; mutation de chaque garde ajoutée (condition du geste, 404, levée A, levée B, comparaison à l'état d'avant, due réseau) ; contrôle navigateur de l'écran assureur et du formulaire.
- [ ] **Step 4** - commit `docs: consigner les règles de la clôture des pénalités`.
