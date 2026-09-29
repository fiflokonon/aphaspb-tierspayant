# Suivi des déclarations — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** donner à l'admin réseau, par mois terminé, la liste des officines dont la déclaration est partielle ou absente (sans assureur ni montant), avec un lien WhatsApp pré-rempli pour les relancer hors système.

**Architecture :** un numéro WhatsApp facultatif sur la fiche officine, normalisé par `App\Support\WhatsappNumber`. Un lecteur réseau `DeclarationCompleteness` ne lit que des **comptes** (assureurs attendus, assureurs déclarés par officine et par mois) en deux requêtes, et rend un état complet / partiel / rien. Un écran admin dédié, `admin/DeclarationFollowUp`, est le seul endroit du lien nom–déclaration.

**Tech Stack :** Laravel 13, PHP 8.4, Pest, Inertia 3, Vue 3 `<script setup>`, Tailwind 4, Lucide.

**Spec :** `docs/superpowers/specs/2026-09-28-suivi-declarations-design.md`

## Global Constraints

- L'écran de suivi n'expose **jamais** : nom ou identifiant d'assureur, nombre d'assureurs, montant, statut, date, délai, note privée, déclaration individuelle. Seulement `id`, `name`, `city`, `state`, `whatsappUrl` par officine, plus le résumé.
- Le lecteur ne charge aucune ligne `declarations` hydratée : seulement des `COUNT(DISTINCT insurer_id)` groupés.
- États : `complete` si déclarés ≥ attendus, `partial` si 0 < déclarés < attendus, `none` si déclarés = 0 ; libellés « Complète », « Partielle », « Sans déclaration ».
- Éligibles : non supprimées, au moins un assureur coché, `created_at` avant le premier jour du mois suivant le mois suivi. Une déclaration rejetée compte ; une déclaration pour un assureur décoché ne compte pas.
- Mois suivis : les 12 mois terminés (mois précédent → 12 mois avant l'actuel). Défaut : le mois précédent. Paramètre `month` au format `AAAA-MM`.
- Filtre d'état : `to-chase` (défaut = partial + none), `all`, `complete`, `partial`, `none`.
- Numéro : `pharmacies.whatsapp_phone` `string(20)` nullable ; normalisé en `+` et 8 à 15 chiffres ; `+229` par défaut ; message d'erreur « Numéro WhatsApp invalide. ».
- Lien WhatsApp seulement pour une officine à relancer (partielle ou sans déclaration) et qui a un numéro ; une officine complète n'en a pas.
- Message : « Bonjour {officine}, votre déclaration de {mois année en minuscules} sur la plateforme APhaSPB est {incomplète|à faire}. Vous pouvez la compléter ici : {URL absolue}. Merci ! » ; lien `https://wa.me/{chiffres}?text={rawurlencode}`.
- Route `GET /admin/declarations-followup`, nom `admin.declarations-followup`, garde `can:manage-network`.
- Commits en français à l'impératif, terminés par `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>` ; `vendor/bin/pint --dirty --format agent` et PHPStan sur les fichiers touchés à chaque tâche ; `composer ci:check` à la fin.
- Tests : décors d'officines créés avec un `created_at` antérieur au mois suivi (la fabrique pose `now()`, qui exclurait l'officine).

## Review Focus

1. **Officine inscrite le 15 du mois suivi** : elle apparaît pour ce mois (inscrite avant la fin), pas pour le mois précédent. Test : Task 3 (`an officine registered during the month is tracked for it, not for the month before`).
2. **Numéro saisi avec indicatif et espaces** (`+229 97 00 00 00`) : normalisé, pas doublement préfixé. Test : Task 1.
3. **Nom d'officine avec apostrophe ou accent** dans le message (« Pharmacie L'Espérance ») : correctement encodé dans l'URL. Test : Task 4.
4. **Paramètre `month` forgé** (`2020-01`, `abc`, mois en cours) : retombe sur le mois précédent, jamais d'erreur. Test : Task 4.
5. **Plusieurs déclarations pour le même assureur et le même mois** (impossible par contrainte d'unicité, mais le compte doit rester `DISTINCT`) : pas de faux « complet ». Test : Task 3 (déclaration pour un assureur décoché + deux assureurs).

---

### Task 1 : le numéro WhatsApp — normalisation, colonne, saisie

**Files :**
- Create : `app/Support/WhatsappNumber.php`, migration `add_whatsapp_phone_to_pharmacies_table`
- Modify : `app/Models/Pharmacy.php` (PHPDoc, `#[Fillable]`), `app/Http/Requests/Onboarding/SavePharmacyProfileRequest.php`, `app/Http/Requests/Pharmacies/SavePharmacyRequest.php`, `app/Http/Controllers/Onboarding/PharmacyProfileController.php` (`edit()` expose le numéro), `app/Http/Controllers/Pharmacies/PharmacyController.php` (`edit()` expose, `update()` enregistre), `resources/js/pages/onboarding/Profile.vue`, `resources/js/pages/pharmacies/Edit.vue`
- Test : `tests/Unit/Support/WhatsappNumberTest.php`, `tests/Feature/Onboarding/PharmacyProfileTest.php`, `tests/Feature/Pharmacies/PharmacyProfileTest.php` (ou le fichier qui teste `pharmacies.update`)

**Interfaces — Produces :**
- `WhatsappNumber::normalize(string $input): ?string` — E.164 ou null.
- `WhatsappNumber::link(string $e164, string $message): string`.
- `Pharmacy::$whatsapp_phone` (`?string`).

- [ ] **Step 1 : tests unitaires**

```php
<?php

use App\Support\WhatsappNumber;

test('a number is normalised to E.164, Benin by default', function (string $input, ?string $expected) {
    expect(WhatsappNumber::normalize($input))->toBe($expected);
})->with([
    'local, spaced' => ['97 00 00 00', '+22997000000'],
    'new 10-digit plan' => ['01 97 00 00 00', '+2290197000000'],
    'international 00' => ['0022997000000', '+22997000000'],
    'already E.164, spaced' => ['+229 97 00 00 00', '+22997000000'],
    'foreign' => ['+33 6 12 34 56 78', '+33612345678'],
    'dots and dashes' => ['97.00-00.00', '+22997000000'],
    'letters' => ['abc', null],
    'too short' => ['123', null],
    'too long' => ['+1234567890123456', null],
]);

test('the link opens a chat with the message encoded', function () {
    expect(WhatsappNumber::link('+22997000000', "Bonjour L'Espérance & co"))
        ->toBe('https://wa.me/22997000000?text=Bonjour%20L%27Esp%C3%A9rance%20%26%20co');
});
```

- [ ] **Step 2 : FAIL** — `php artisan test --compact tests/Unit/Support/WhatsappNumberTest.php`.

- [ ] **Step 3 : implémenter**

```php
<?php

namespace App\Support;

/**
 * Un numéro WhatsApp, ramené au format international E.164.
 *
 * Saisi à la main par une officine, il arrive avec des espaces, des points,
 * un « 00 » ou sans indicatif : on le range sous une seule forme pour que le
 * lien wa.me fonctionne à tous les coups. Sans indicatif, le Bénin (+229).
 */
class WhatsappNumber
{
    protected const DEFAULT_COUNTRY = '229';

    public static function normalize(string $input): ?string
    {
        $compact = preg_replace('/[\s.\-()]/u', '', $input) ?? '';

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        } elseif (! str_starts_with($compact, '+')) {
            $compact = '+'.self::DEFAULT_COUNTRY.$compact;
        }

        return preg_match('/^\+\d{8,15}$/', $compact) === 1 ? $compact : null;
    }

    public static function link(string $e164, string $message): string
    {
        return 'https://wa.me/'.ltrim($e164, '+').'?text='.rawurlencode($message);
    }
}
```

(Le cas « too short » : `123` → `+229123`, 6 chiffres, refusé. Le cas « too long » : 16 chiffres, refusé.)

- [ ] **Step 4 : PASS** unitaire.

- [ ] **Step 5 : tests de saisie** — dans le fichier de test du profil d'inscription et dans celui de `pharmacies.update` (lire leurs helpers d'abord) :

```php
test('the onboarding profile stores a normalised WhatsApp number', function () {
    $user = User::factory()->notOnboarded()->create();

    $this->actingAs($user)->post(route('onboarding.profile.store'), [
        'name' => 'Pharmacie du Port',
        'city' => 'Cotonou',
        'whatsapp_phone' => '97 00 00 00',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->currentPharmacy->whatsapp_phone)->toBe('+22997000000');
});

test('an invalid WhatsApp number is refused', function () {
    $user = User::factory()->notOnboarded()->create();

    $this->actingAs($user)->post(route('onboarding.profile.store'), [
        'name' => 'Pharmacie du Port',
        'city' => 'Cotonou',
        'whatsapp_phone' => 'abc',
    ])->assertSessionHasErrors(['whatsapp_phone' => 'Numéro WhatsApp invalide.']);
});

test('the WhatsApp number stays optional', function () {
    $user = User::factory()->notOnboarded()->create();

    $this->actingAs($user)->post(route('onboarding.profile.store'), [
        'name' => 'Pharmacie du Port',
        'city' => 'Cotonou',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->currentPharmacy->whatsapp_phone)->toBeNull();
});
```

et, pour la modification (propriétaire de l'officine, via la fabrique de `User` qui crée une officine courante) :

```php
test('the owner updates the WhatsApp number of the officine', function () {
    $user = User::factory()->create();
    $pharmacy = $user->currentPharmacy;

    $this->actingAs($user)
        ->patch(route('pharmacies.update', $pharmacy->slug), ['name' => $pharmacy->name, 'whatsapp_phone' => '0022997000000'])
        ->assertSessionHasNoErrors();

    expect($pharmacy->fresh()->whatsapp_phone)->toBe('+22997000000');
});
```

Adapter le paramètre de route (`slug` ou modèle) et les champs requis de `onboarding.profile.store` à ce que font les tests voisins.

- [ ] **Step 6 : FAIL**, puis **implémenter** :
  - `php artisan make:migration add_whatsapp_phone_to_pharmacies_table --no-interaction` : `$table->string('whatsapp_phone', 20)->nullable()->after('owner_name');` et `down()` qui la retire. PHPDoc de la migration : pourquoi nullable (officines déjà inscrites) et pourquoi E.164.
  - `Pharmacy` : `whatsapp_phone` dans `#[Fillable]`, `@property string|null $whatsapp_phone`.
  - Les deux requêtes : dans `prepareForValidation()`, si `whatsapp_phone` est une chaîne non vide, la remplacer par `WhatsappNumber::normalize(...) ?? $valeurBrute` ; si vide, `null`. Règle `'whatsapp_phone' => ['nullable', 'string', 'regex:/^\+\d{8,15}$/']`, message `'whatsapp_phone.regex' => 'Numéro WhatsApp invalide.'`.
  - `PharmacyProfileController::store()` : aucun changement (il passe déjà `validated()`) ; `edit()` : ajouter `whatsapp_phone` au `only([...])`.
  - `PharmacyController::update()` : `$pharmacy->update(['name' => …, 'whatsapp_phone' => $request->validated('whatsapp_phone')])` ; `edit()` : `'whatsappPhone' => $pharmacy->whatsapp_phone` dans `pharmacy`.
  - Vue : un champ `FormField` / `TextInput` « WhatsApp de contact », facultatif, `type="tel"`, `name="whatsapp_phone"`, aide « Le réseau APhaSPB l'utilise pour vous rappeler une déclaration manquante. », erreur affichée comme les champs voisins, dans les deux formulaires.
- [ ] **Step 7 : PASS** + `npm run types:check && npm run lint:check && npm run format:check`.
- [ ] **Step 8 : commit** — `feat: recueillir le numéro WhatsApp de l'officine`

---

### Task 2 : l'invite du tableau de bord

**Files :** Modify `app/Http/Controllers/Pharmacy/PaymentJourneyController.php`, `resources/js/pages/pharmacy/Dashboard.vue` ; Test `tests/Feature/Pharmacy/PaymentJourneyTest.php`

**Interfaces — Produces :** prop `whatsappInvite: {url: string} | null`.

- [ ] **Step 1 : tests**

```php
test('an officine without WhatsApp number is invited to add one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('whatsappInvite.url', route('pharmacies.edit', $user->currentPharmacy->slug, absolute: false)));
});

test('the invite disappears once the number is set, or for a member who cannot edit the officine', function () {
    $user = User::factory()->create();
    $user->currentPharmacy->update(['whatsapp_phone' => '+22997000000']);

    $this->actingAs($user)->get(dashboardUrlFor($user))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('whatsappInvite', null));
});
```

Ajouter au second test le cas d'un membre non propriétaire (lire `tests/Feature/Pharmacies/PharmacyMemberTest.php` pour créer un membre sans droit `update`), attendu : `whatsappInvite` null.

- [ ] **Step 2 : FAIL** ; **Step 3 : implémenter** — dans `__invoke()` :

```php
            // Le numéro sert au réseau à rappeler une déclaration manquante ;
            // l'invite ne s'adresse qu'à qui peut modifier la fiche.
            'whatsappInvite' => $pharmacy->whatsapp_phone === null && $request->user()->can('update', $pharmacy)
                ? ['url' => route('pharmacies.edit', $pharmacy->slug, absolute: false)]
                : null,
```

`Dashboard.vue` : prop `whatsappInvite?: { url: string } | null`, un bandeau discret sous l'en-tête (surface `--cream-header`, texte `text-ink/80`, lien `<Link>` « Ajouter un numéro ») : « Ajoutez un numéro WhatsApp pour que le réseau puisse vous joindre. » Scoped, jetons seulement.
- [ ] **Step 4 : PASS** + checks front ; **Step 5 : commit** — `feat: inviter l'officine à donner un numéro WhatsApp`

---

### Task 3 : `DeclarationCompleteness`

**Files :**
- Create : `app/Enums/CompletenessState.php`, `app/Data/PharmacyCompleteness.php`, `app/Services/Network/DeclarationCompleteness.php`
- Test : `tests/Feature/Network/DeclarationCompletenessTest.php`

**Interfaces — Produces :**
- `enum CompletenessState: string { Complete='complete'; Partial='partial'; None='none'; label(): string }` — « Complète » / « Partielle » / « Sans déclaration ».
- `readonly class PharmacyCompleteness(int $id, string $name, ?string $city, ?string $whatsappPhone, CompletenessState $state)`.
- `DeclarationCompleteness::forMonth(Period $month, ?string $city = null): list<PharmacyCompleteness>` — triées par nom.

- [ ] **Step 1 : tests**

```php
<?php

use App\Data\Period;
use App\Enums\CompletenessState;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\DeclarationCompleteness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 28));
    $this->completeness = app(DeclarationCompleteness::class);
    $this->insurers = Insurer::factory()->count(2)->create();
});

/**
 * Une officine inscrite en janvier, avec les deux assureurs du décor.
 *
 * @param  array<string, mixed>  $attributes
 */
function trackedPharmacy(string $name, array $attributes = []): Pharmacy
{
    $pharmacy = Pharmacy::factory()->create(['name' => $name, 'created_at' => '2026-01-10', ...$attributes]);
    $pharmacy->insurers()->attach(test()->insurers->pluck('id'));

    return $pharmacy;
}

function declareAugust(Pharmacy $pharmacy, Insurer $insurer, array $attributes = []): void
{
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        ...$attributes,
    ]);
}

function statesFor(array $rows): array
{
    return collect($rows)->mapWithKeys(fn ($row) => [$row->name => $row->state])->all();
}

test('complete, partial and none on one month', function () {
    [$first, $second] = $this->insurers;
    $complete = trackedPharmacy('A Complète');
    $partial = trackedPharmacy('B Partielle');
    trackedPharmacy('C Rien');
    declareAugust($complete, $first);
    declareAugust($complete, $second);
    declareAugust($partial, $first);

    expect(statesFor($this->completeness->forMonth(new Period(2026, 8))))->toBe([
        'A Complète' => CompletenessState::Complete,
        'B Partielle' => CompletenessState::Partial,
        'C Rien' => CompletenessState::None,
    ]);
});

test('an officine without insurer, deleted, or registered after the month is not tracked', function () {
    Pharmacy::factory()->create(['name' => 'Sans assureur', 'created_at' => '2026-01-10']);
    trackedPharmacy('Supprimée')->delete();
    trackedPharmacy('Trop récente', ['created_at' => '2026-09-02']);
    trackedPharmacy('Suivie');

    expect(array_keys(statesFor($this->completeness->forMonth(new Period(2026, 8)))))->toBe(['Suivie']);
});

test('an officine registered during the month is tracked for it, not for the month before', function () {
    trackedPharmacy('Arrivée le 15 août', ['created_at' => '2026-08-15']);

    expect($this->completeness->forMonth(new Period(2026, 8)))->toHaveCount(1)
        ->and($this->completeness->forMonth(new Period(2026, 7)))->toHaveCount(0);
});

test('a rejected declaration counts, one for an insurer no longer ticked does not', function () {
    [$first, $second] = $this->insurers;
    $pharmacy = trackedPharmacy('Mixte');
    declareAugust($pharmacy, $first, ['status' => DeclarationStatus::Rejected, 'is_status_manual' => true]);
    declareAugust($pharmacy, Insurer::factory()->create());

    expect(statesFor($this->completeness->forMonth(new Period(2026, 8))))->toBe(['Mixte' => CompletenessState::Partial]);
});

test('the city filter narrows the roll', function () {
    trackedPharmacy('Cotonou 1', ['city' => 'Cotonou']);
    trackedPharmacy('Parakou 1', ['city' => 'Parakou']);

    expect(array_keys(statesFor($this->completeness->forMonth(new Period(2026, 8), 'Parakou'))))->toBe(['Parakou 1']);
});

test('the query count stays flat however many officines there are', function () {
    trackedPharmacy('Seule');
    DB::enableQueryLog();
    $this->completeness->forMonth(new Period(2026, 8));
    $withOne = count(DB::getQueryLog());

    foreach (range(1, 20) as $n) {
        declareAugust(trackedPharmacy("Officine {$n}"), $this->insurers[0]);
    }
    DB::flushQueryLog();
    $this->completeness->forMonth(new Period(2026, 8));

    expect(count(DB::getQueryLog()))->toBe($withOne)->and($withOne)->toBe(2);
});
```

- [ ] **Step 2 : FAIL.**
- [ ] **Step 3 : implémenter**

`app/Enums/CompletenessState.php` :

```php
<?php

namespace App\Enums;

/**
 * Où en est la déclaration d'une officine pour un mois, sans dire pour qui.
 */
enum CompletenessState: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Complète',
            self::Partial => 'Partielle',
            self::None => 'Sans déclaration',
        };
    }
}
```

`app/Data/PharmacyCompleteness.php` :

```php
<?php

namespace App\Data;

use App\Enums\CompletenessState;

/**
 * Une officine et l'état de sa déclaration pour un mois : rien de plus.
 *
 * Aucune propriété ne dit à quel assureur ni combien : c'est la borne de
 * l'exception au CDC (spec suivi des déclarations, §3).
 */
readonly class PharmacyCompleteness
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $city,
        public ?string $whatsappPhone,
        public CompletenessState $state,
    ) {
        //
    }
}
```

`app/Services/Network/DeclarationCompleteness.php` :

```php
<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Data\PharmacyCompleteness;
use App\Enums\CompletenessState;
use App\Models\Pharmacy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quelles officines ont déclaré un mois, en entier, en partie ou pas du tout.
 *
 * **Seul point du réseau où un nom d'officine côtoie une donnée de
 * déclaration** — exception au CDC décidée le 28/09/2026 (spec suivi des
 * déclarations, §3). Ce lecteur ne lit que des comptes : jamais une ligne de
 * déclaration hydratée, jamais un montant, jamais un assureur nommé. Il ne
 * doit pas grandir au-delà.
 *
 * Même règle que DeclarationCalendar côté officine : complet quand chaque
 * assureur coché a sa déclaration du mois. Deux requêtes quel que soit le
 * nombre d'officines.
 */
class DeclarationCompleteness
{
    /**
     * @return list<PharmacyCompleteness>
     */
    public function forMonth(Period $month, ?string $city = null): array
    {
        $dayAfter = CarbonImmutable::create($month->year, $month->month, 1)->addMonth();

        $pharmacies = Pharmacy::query()
            ->select(['id', 'name', 'city', 'whatsapp_phone'])
            ->withCount('insurers')
            ->whereHas('insurers')
            ->where('created_at', '<', $dayAfter)
            ->when($city, fn ($query, string $filtered) => $query->where('city', $filtered))
            ->orderBy('name')
            ->get();

        // Comptés sur les assureurs encore cochés : une déclaration pour un
        // assureur retiré depuis ne rend pas un mois complet.
        $declared = DB::table('declarations')
            ->join('insurer_pharmacy', function ($join) {
                $join->on('insurer_pharmacy.pharmacy_id', '=', 'declarations.pharmacy_id')
                    ->on('insurer_pharmacy.insurer_id', '=', 'declarations.insurer_id');
            })
            ->where('declarations.period_year', $month->year)
            ->where('declarations.period_month', $month->month)
            ->groupBy('declarations.pharmacy_id')
            ->selectRaw('declarations.pharmacy_id, COUNT(DISTINCT declarations.insurer_id) as declared')
            ->pluck('declared', 'pharmacy_id');

        return array_values($pharmacies->map(function (Pharmacy $pharmacy) use ($declared): PharmacyCompleteness {
            $count = (int) ($declared[$pharmacy->id] ?? 0);

            return new PharmacyCompleteness(
                id: $pharmacy->id,
                name: $pharmacy->name,
                city: $pharmacy->city,
                whatsappPhone: $pharmacy->whatsapp_phone,
                state: match (true) {
                    $count === 0 => CompletenessState::None,
                    $count >= (int) $pharmacy->insurers_count => CompletenessState::Complete,
                    default => CompletenessState::Partial,
                },
            );
        })->all());
    }
}
```

Vérifier que la table pivot s'appelle `insurer_pharmacy` et que la relation `Pharmacy::insurers()` est bien un `BelongsToMany` sur elle ; `whereHas` + `withCount` peuvent faire deux sous-requêtes dans la même requête SQL — c'est toujours une requête. Si le soft delete de `Declaration` existe (vérifier), ajouter `whereNull('declarations.deleted_at')`.

- [ ] **Step 4 : PASS** ; **Step 5 : commit** — `feat: mesurer la complétude des déclarations d'un mois`

---

### Task 4 : l'écran « Suivi des déclarations »

**Files :**
- Create : `app/Http/Controllers/Admin/DeclarationFollowUpController.php`, `resources/js/pages/admin/DeclarationFollowUp.vue`
- Modify : `routes/web.php`, `app/Support/ConsoleNavigation.php`, `resources/js/lib/navIcons.ts`, `tests/Feature/Console/ConsoleShellTest.php`, docblock de `app/Http/Controllers/Admin/RegisteredPharmaciesController.php`
- Test : `tests/Feature/Admin/DeclarationFollowUpTest.php`

**Interfaces — Consumes :** `DeclarationCompleteness::forMonth()`, `WhatsappNumber::link()`, `CompletenessState::label()`.

- [ ] **Step 1 : tests**

```php
<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 28));
    $this->admin = User::factory()->networkAdmin()->create();
    $this->insurer = Insurer::factory()->create(['name' => 'Assureur Secret']);
});

function followedPharmacy(string $name, ?string $phone = null, string $city = 'Cotonou'): Pharmacy
{
    $pharmacy = Pharmacy::factory()->create(['name' => $name, 'city' => $city, 'whatsapp_phone' => $phone, 'created_at' => '2026-01-10']);
    $pharmacy->insurers()->attach(test()->insurer);

    return $pharmacy;
}

test('an officine cannot open the follow-up', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.declarations-followup'))->assertForbidden();
});

test('the screen lists the officines to chase for last month, with a summary', function () {
    $done = followedPharmacy('Pharmacie Faite');
    Declaration::factory()->create(['pharmacy_id' => $done->id, 'insurer_id' => $this->insurer->id, 'period_year' => 2026, 'period_month' => 8]);
    followedPharmacy("Pharmacie L'Espérance", '+22997000000');

    $this->actingAs($this->admin)->get(route('admin.declarations-followup'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/DeclarationFollowUp')
            ->where('month', '2026-08')
            ->where('state', 'to-chase')
            ->where('summary', ['complete' => 1, 'partial' => 0, 'none' => 1, 'total' => 2])
            ->has('pharmacies.data', 1)
            ->where('pharmacies.data.0.name', "Pharmacie L'Espérance")
            ->where('pharmacies.data.0.state', 'none')
            ->where('pharmacies.data.0.stateLabel', 'Sans déclaration'));
});

test('the WhatsApp link carries the message for the right month', function () {
    followedPharmacy("Pharmacie L'Espérance", '+22997000000');

    $url = $this->actingAs($this->admin)->get(route('admin.declarations-followup'))
        ->viewData('page')['props']['pharmacies']['data'][0]['whatsappUrl'];

    $message = "Bonjour Pharmacie L'Espérance, votre déclaration de août 2026 sur la plateforme APhaSPB est à faire. "
        .'Vous pouvez la compléter ici : '.route('pharmacy.declare', ['year' => 2026, 'month' => 8]).'. Merci !';

    expect($url)->toBe('https://wa.me/22997000000?text='.rawurlencode($message));
});

test('without number, no link', function () {
    followedPharmacy('Sans Numéro');

    $this->actingAs($this->admin)->get(route('admin.declarations-followup'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pharmacies.data.0.whatsappUrl', null));
});

test('a forged or current month falls back to last month', function (string $month) {
    followedPharmacy('Une');

    $this->actingAs($this->admin)->get(route('admin.declarations-followup', ['month' => $month]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('month', '2026-08'));
})->with(['abc', '2020-01', '2026-09', '2026-13']);

test('the month list offers the twelve finished months only', function () {
    $this->actingAs($this->admin)->get(route('admin.declarations-followup'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('months', 12)
            ->where('months.0.value', '2026-08')
            ->where('months.11.value', '2025-09'));
});

test('state and city filters', function () {
    followedPharmacy('Cotonou Rien');
    followedPharmacy('Parakou Rien', city: 'Parakou');

    $this->actingAs($this->admin)->get(route('admin.declarations-followup', ['city' => 'Parakou', 'state' => 'all']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('pharmacies.data', 1)->where('pharmacies.data.0.name', 'Parakou Rien'));
});

test('the screen never carries an insurer, an amount or a private note', function () {
    $pharmacy = followedPharmacy('Pharmacie Discrète', '+22997000000');
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id, 'insurer_id' => $this->insurer->id,
        'period_year' => 2026, 'period_month' => 8,
        'amount_invoiced' => 7_654_321, 'private_note' => 'note très privée',
    ]);

    $json = inertiaPropsJson($this->actingAs($this->admin)->get(route('admin.declarations-followup', ['state' => 'all'])));

    expect($json)->not->toContain('Assureur Secret')
        ->and($json)->not->toContain('7654321')
        ->and($json)->not->toContain('note très privée')
        ->and($json)->not->toContain('insurer');
});
```

Le filtre d'état `to-chase` (par défaut) exclut « complète » : le test du résumé le vérifie (une officine complète dans le résumé, absente de la liste). Si `inertiaPropsJson` contient ailleurs le mot `insurer` par la navigation de la coquille (par exemple une clé de console), resserrer la dernière assertion sur `props.pharmacies` et `props.summary` plutôt que sur tout le JSON — lire d'abord ce que contient la prop `console`.

- [ ] **Step 2 : FAIL.**
- [ ] **Step 3 : implémenter**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Data\Period;
use App\Data\PharmacyCompleteness;
use App\Enums\CompletenessState;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Network\DeclarationCompleteness;
use App\Support\MonthLabel;
use App\Support\PageSize;
use App\Support\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Qui a déclaré quel mois, pour que le réseau relance — hors système.
 *
 * Exception délimitée au CDC (spec suivi des déclarations, §3) : un nom
 * d'officine à côté de l'état complet / partiel / rien de son mois. Rien
 * d'autre — ni assureur, ni nombre d'assureurs, ni montant. La relance passe
 * par WhatsApp : la plateforme ne l'envoie pas et n'en garde pas trace.
 */
class DeclarationFollowUpController extends Controller
{
    protected const PER_PAGE = 50;

    /** @var list<string> */
    protected const STATES = ['to-chase', 'all', 'complete', 'partial', 'none'];

    public function __construct(protected DeclarationCompleteness $completeness)
    {
        //
    }

    public function __invoke(Request $request): Response
    {
        $months = $this->months();
        $month = $this->month($request, $months);
        $city = $request->string('city')->value() ?: null;
        $state = in_array($request->string('state')->value(), self::STATES, true) ? $request->string('state')->value() : 'to-chase';

        $rows = $this->completeness->forMonth($month, $city);
        $shown = array_values(array_filter($rows, fn (PharmacyCompleteness $row): bool => match ($state) {
            'all' => true,
            'to-chase' => $row->state !== CompletenessState::Complete,
            default => $row->state->value === $state,
        }));

        $perPage = PageSize::resolve($request, self::PER_PAGE);
        $page = max(1, $request->integer('page', 1));

        $paginator = (new LengthAwarePaginator(
            array_map(fn (PharmacyCompleteness $row): array => $this->row($row, $month), array_slice($shown, ($page - 1) * $perPage, $perPage)),
            count($shown),
            $perPage,
            $page,
            ['path' => $request->url()],
        ))->withQueryString();

        return Inertia::render('admin/DeclarationFollowUp', [
            'month' => $this->key($month),
            'months' => array_map(fn (Period $one): array => [
                'value' => $this->key($one),
                'label' => $this->label($one),
            ], $months),
            'city' => $city,
            'cities' => Pharmacy::filterableCities(),
            'state' => $state,
            'summary' => [
                'complete' => count(array_filter($rows, fn ($row) => $row->state === CompletenessState::Complete)),
                'partial' => count(array_filter($rows, fn ($row) => $row->state === CompletenessState::Partial)),
                'none' => count(array_filter($rows, fn ($row) => $row->state === CompletenessState::None)),
                'total' => count($rows),
            ],
            'pharmacies' => $paginator,
        ]);
    }

    /**
     * Les douze mois terminés, du plus récent au plus ancien.
     *
     * @return list<Period>
     */
    protected function months(): array
    {
        $start = now()->startOfMonth();

        return array_map(fn (int $back): Period => new Period(
            $start->subMonths($back)->year,
            $start->subMonths($back)->month,
        ), range(1, 12));
    }

    /**
     * @param  list<Period>  $months
     */
    protected function month(Request $request, array $months): Period
    {
        foreach ($months as $one) {
            if ($this->key($one) === $request->string('month')->value()) {
                return $one;
            }
        }

        return $months[0];
    }

    /**
     * @return array{id: int, name: string, city: string|null, state: string, stateLabel: string, whatsappUrl: string|null}
     */
    protected function row(PharmacyCompleteness $row, Period $month): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'city' => $row->city,
            'state' => $row->state->value,
            'stateLabel' => $row->state->label(),
            'whatsappUrl' => $row->whatsappPhone === null || $row->state === CompletenessState::Complete
                ? null
                : WhatsappNumber::link($row->whatsappPhone, $this->message($row, $month)),
        ];
    }

    protected function message(PharmacyCompleteness $row, Period $month): string
    {
        return sprintf(
            'Bonjour %s, votre déclaration de %s sur la plateforme APhaSPB est %s. Vous pouvez la compléter ici : %s. Merci !',
            $row->name,
            $this->label($month),
            $row->state === CompletenessState::Partial ? 'incomplète' : 'à faire',
            route('pharmacy.declare', ['year' => $month->year, 'month' => $month->month]),
        );
    }

    protected function key(Period $month): string
    {
        return sprintf('%04d-%02d', $month->year, $month->month);
    }

    /** « août 2026 » */
    protected function label(Period $month): string
    {
        return mb_strtolower(MonthLabel::long($month->month, $month->year));
    }
}
```

(Vérifier `now()->startOfMonth()` rend un `CarbonImmutable` dans ce projet ; sinon utiliser `CarbonImmutable::now()->startOfMonth()` pour que `subMonths()` ne mute pas `$start`. Vérifier aussi que `PageSize::resolve()` et `Pharmacy::filterableCities()` existent sous ces noms — ils sont utilisés par `RegisteredPharmaciesController` et `NetworkTrendsController`.)

Route, groupe `admin.` de `routes/web.php`, après `pharmacies` :

```php
        // Seul écran réseau où un nom d'officine côtoie une donnée de
        // déclaration (complet / partiel / rien) : spec suivi des déclarations.
        Route::get('declarations-followup', DeclarationFollowUpController::class)->name('declarations-followup');
```

Navigation admin, après « Pharmacies inscrites » : `['Suivi des déclarations', 'admin.declarations-followup', [], 'clipboard-check']` ; `navIcons.ts` : `ClipboardCheck` depuis `@lucide/vue`, clé `'clipboard-check'`. `ConsoleShellTest` : `has('console.nav', 6)` → `7` pour l'admin.

Docblock de `RegisteredPharmaciesController` : remplacer la dernière phrase (« It deliberately exposes no per-officine « has declared » flag… ») par une phrase qui dit que cet écran-ci reste sans aucune donnée de déclaration, et que l'état de déclaration par officine vit, par exception délimitée, dans `DeclarationFollowUpController`.

`resources/js/pages/admin/DeclarationFollowUp.vue` (lire d'abord `admin/Pharmacies.vue`, dont il reprend la coquille, la pagination et les filtres) :
- `ConsoleHeader title="Suivi des déclarations"` avec trois `FilterSelect` (mois, ville, état — libellés « À relancer », « Toutes », « Complètes », « Partielles », « Sans déclaration ») ; `router.get('/admin/declarations-followup', {…}, { preserveState: true, preserveScroll: true, replace: true })` au changement.
- Un bandeau de résumé : « {complete} complètes · {partial} partielles · {none} sans déclaration, sur {total} officines ».
- `DataTable` + `DataTableRow` : Nom, Ville, État (puce, tons existants — complète = bon, partielle = alerte, sans = mauvais ; réutiliser `StatusChip` si ses tons conviennent), et une dernière cellule : lien `<a :href="row.whatsappUrl" target="_blank" rel="noopener">` « Relancer sur WhatsApp » (icône Lucide `MessageCircle`), ou « pas de numéro » en `text-ink/70`.
- Un paragraphe sous la table : « Ni assureur ni montant ne figurent ici : l'état d'un mois est tout ce que le réseau voit d'une officine. »
- `Pagination` existant.
- `<style scoped>`, jetons seulement ; icônes Lucide, jamais de glyphe Unicode.

- [ ] **Step 4 : PASS** + `tests/Feature/Console` + `npm run build && npm run types:check && npm run lint:check && npm run format:check`.
- [ ] **Step 5 : commit** — `feat: suivre qui a déclaré quel mois pour relancer`

---

### Task 5 : règles, vérification complète

- [ ] **Step 1** — `record-rule` (Boost) :
  1. glob `app/Services/Network/**` — « DeclarationCompleteness est la seule exception nom–déclaration » : exception au CDC du 28/09/2026, à faire valider par écrit ; bornes (état complet / partiel / rien seulement ; comptes, jamais de ligne ni de montant ni d'assureur) ; deux requêtes ; mêmes règles que `DeclarationCalendar`.
  2. glob `app/Support/**` — « WhatsappNumber : E.164, +229 par défaut » : normalisation dans `prepareForValidation()` des deux requêtes d'officine ; `rawurlencode` pour le message.
- [ ] **Step 2** — `composer ci:check` vert ; mutation : forcer `whatsappUrl` à inclure l'assureur ou retirer le `join('insurer_pharmacy')`, confirmer un test rouge, restaurer.
- [ ] **Step 3** — commit `docs: consigner les règles du suivi des déclarations`.
