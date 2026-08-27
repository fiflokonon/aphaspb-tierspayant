# Onboarding de l'officine et déclaration mensuelle (incrément 3B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendre l'application utilisable par une officine : compléter son profil et cocher ses assureurs à la première venue, puis déclarer chaque mois ce qu'elle a facturé et reçu, un assureur à la fois.

**Architecture:** Le parcours de déclaration est piloté par le serveur, sans indice d'étape dans l'URL : `GET /pharmacy/declare` montre toujours le premier assureur non déclaré du mois, et l'enregistrement redirige sur la même route, qui montre le suivant. C'est ce qui rend « Reprendre plus tard » gratuit — l'état vit dans les déclarations enregistrées, pas dans une session de formulaire.

**Tech Stack:** Laravel 13.29, Inertia 3.3, Vue 3.5, Tailwind 4, Pest 5.

**Spec:** `docs/superpowers/specs/2026-08-26-aphaspb-design-implementation-design.md` (§4.5, §5.4, §5.5, §7.4, §7.5)

## Global Constraints

- Nommage du code, des routes et des colonnes **en anglais** ; le français reste dans les libellés d'interface.
- Montants en **FCFA entiers**. Jamais de flottant, jamais de décimale.
- La **note privée n'est lisible que par l'officine**. Aucune route admin ne doit pouvoir la renvoyer.
- Le statut se **déduit** ; le pharmacien peut le corriger, et la correction survit à un nouvel enregistrement.
- Rattrapage borné à 12 mois en arrière, période future refusée — via `App\Rules\DeclarablePeriod`.
- `<script setup>` et Composition API partout. Un composant Vue a une seule racine.
- `<Link>` d'Inertia, jamais de `<a>` interne. URLs via Wayfinder, jamais en dur.
- **Tous les écrans sont responsive** (spec §7.5) : la déclaration garde un assureur par écran à toutes les largeurs et se centre dans une colonne bornée au-delà du téléphone. Cibles tactiles de 44 px partout.
- Régénérer les routes avec **`npm run build`**, jamais `php artisan wayfinder:generate` seul.
- Vérifier avec **`composer ci:check`** en entier avant chaque commit.
- Ne jamais nommer une route de façon à produire un identifiant TypeScript réservé (`exports`, `default`, `import`…) : Wayfinder génère une constante par route.

## Relevé du canvas — artboard 3a

Fond de carte `#fdf8ef`, plus chaud que le crème de la console.

| Élément | Mesures relevées |
|---|---|
| Barre de progression | 7 segments `h-1 rounded-full`, faits en `#1f6f4a`, courant en `#e8c25c`, à venir en `rgba(23,33,28,.12)`, `gap-1` |
| Compteur | `3/7`, mono 10.5px, `rgba(23,33,28,.45)` |
| Sur-titre | mono 10.5px, `#b07c1a`, `letter-spacing .08em` |
| Question | **Instrument Serif** 26px/1.18, `#17211c`, avec une incise en italique |
| Étiquette de champ | mono 10.5px, `rgba(23,33,28,.5)`, `letter-spacing .05em` |
| Champ de montant | `h-14 rounded-xl` blanc, bordure 1.5px `rgba(23,33,28,.13)`, valeur 700 22px, suffixe `FCFA` mono 12px `rgba(23,33,28,.45)` |
| Champ actif | bordure `rgba(216,163,37,.55)` + halo `0 0 0 3px rgba(216,163,37,.13)` |
| Raccourci « Tout reçu » | 600 11px, `#1f6f4a` |
| Carte de statut déduit | `rounded-xl`, fond `rgba(216,163,37,.13)`, bordure `rgba(216,163,37,.35)`, pastille 24px `#d9a325`, titre 700 13px, jauge `h-2` fond `rgba(23,33,28,.1)` |
| Pas-à-pas de délai | carte blanche bordure `rgba(23,33,28,.11)` ; boutons 32px `rounded-lg` fond `rgba(31,111,74,.1)` glyphe `#1f6f4a` ; valeur 700 17px `min-w-10` centrée, unité 500 11px |
| Bouton principal | `h-13 rounded-xl` fond **`#17211c`** — encre, pas vert — texte blanc 700 14.5px |
| Pied de page | centré, 400 11px/1.45, `rgba(23,33,28,.45)` |

La teinte de la carte de statut suit le statut : or pour partiel (relevé), vert officine pour payé, encre atténuée pour non payé, terre cuite pour rejeté.

## Relevé du canvas — artboard 1f, étape 2

En-tête `ÉTAPE 2 SUR 2` mono 10.5px `#b07c1a`, titre 700 18px/1.25, sous-titre 400 12px/1.5 `rgba(23,33,28,.55)`, champ de recherche `h-[42px] rounded-[10px]` fond `#f3f1eb`. Lignes cochables `py-[13px] px-5`, coche 22px `rounded-md` — cochée fond `#1f6f4a` glyphe blanc et ligne `rgba(31,111,74,.06)`, décochée bordure 1.5px `rgba(23,33,28,.2)`. Entrée « Autre… » séparée par un filet `border-dashed`. Pied `#fdfbf7` avec bouton vert `h-[50px] rounded-[11px]` portant le décompte.

## Lacune du code existant à combler

`JoomlaCallbackController` crée un `User` mais **aucune `Pharmacy`** : un titulaire arrivant de Joomla n'a ni officine, ni `current_pharmacy_id`. L'onboarding ne complète donc pas un profil existant, il **crée l'officine**. La tâche 1 traite ce point.

---

### Task 1: Onboarding, étape 1 — le profil de l'officine

**Files:**
- Create: `app/Http/Controllers/Onboarding/PharmacyProfileController.php`
- Create: `app/Http/Requests/Onboarding/SavePharmacyProfileRequest.php`
- Create: `app/Http/Middleware/EnsureOnboarded.php`
- Create: `resources/js/pages/onboarding/Profile.vue`
- Create: `resources/js/components/aphaspb/FormField.vue`
- Modify: `app/Http/Controllers/Auth/JoomlaCallbackController.php`
- Modify: `routes/web.php`, `bootstrap/app.php`
- Test: `tests/Feature/Onboarding/PharmacyProfileTest.php`

**Interfaces:**
- Consumes: `Pharmacy`, `PharmacyRole`, `User::currentPharmacy`, `Pharmacy::hasCompleteProfile()`
- Produces:
  - Route `GET /onboarding` nommée `onboarding.profile`, `POST /onboarding` nommée `onboarding.profile.store`
  - Middleware alias `onboarded` → `EnsureOnboarded`
  - `User::needsOnboarding(): bool` — vrai sans officine, sans profil complet, ou sans aucun assureur coché
  - `FormField` props : `label: string`, `hint?: string`, `error?: string` — emplacement par défaut pour le contrôle

- [x] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Onboarding/PharmacyProfileTest.php`:

```php
<?php

use App\Enums\PharmacyRole;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

/**
 * A user as the Joomla callback leaves them: no pharmacy at all.
 */
function freshJoomlaUser(): User
{
    $user = User::factory()->create();

    $user->pharmacies()->detach();
    $user->update(['current_pharmacy_id' => null]);

    return $user->fresh();
}

test('a user arriving from Joomla has no pharmacy yet', function () {
    expect(freshJoomlaUser()->currentPharmacy)->toBeNull();
});

test('the profile step is shown to a user without a pharmacy', function () {
    $this->actingAs(freshJoomlaUser())
        ->get(route('onboarding.profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('onboarding/Profile')
            ->has('cities'),
        );
});

test('submitting the profile creates the officine and makes the user its owner', function () {
    $user = freshJoomlaUser();

    $this->actingAs($user)
        ->post(route('onboarding.profile.store'), [
            'name' => 'Pharmacie Le Bon Secours',
            'onpb_license' => 'ONPB-4212',
            'city' => 'Cotonou',
            'owner_name' => 'Awa Hounkpatin',
        ])
        ->assertRedirect(route('onboarding.insurers'));

    $pharmacy = $user->fresh()->currentPharmacy;

    expect($pharmacy)->not->toBeNull()
        ->and($pharmacy->name)->toBe('Pharmacie Le Bon Secours')
        ->and($pharmacy->city)->toBe('Cotonou')
        ->and($pharmacy->owner_name)->toBe('Awa Hounkpatin')
        ->and($pharmacy->hasCompleteProfile())->toBeTrue()
        ->and($user->fresh()->pharmacyRole($pharmacy))->toBe(PharmacyRole::Owner);
});

test('the ONPB licence may be left blank', function () {
    $user = freshJoomlaUser();

    $this->actingAs($user)
        ->post(route('onboarding.profile.store'), [
            'name' => 'Pharmacie Sans Licence',
            'city' => 'Parakou',
            'owner_name' => 'Kofi Adjovi',
        ])
        ->assertRedirect();

    expect($user->fresh()->currentPharmacy->onpb_license)->toBeNull();
});

test('a duplicate ONPB licence is refused', function () {
    Pharmacy::factory()->create(['onpb_license' => 'ONPB-0001']);

    $this->actingAs(freshJoomlaUser())
        ->post(route('onboarding.profile.store'), [
            'name' => 'Pharmacie Copie',
            'onpb_license' => 'ONPB-0001',
            'city' => 'Cotonou',
            'owner_name' => 'Test',
        ])
        ->assertSessionHasErrors('onpb_license');
});

test('the name, the city and the owner are all required', function () {
    $this->actingAs(freshJoomlaUser())
        ->post(route('onboarding.profile.store'), [])
        ->assertSessionHasErrors(['name', 'city', 'owner_name']);
});

test('a second submission updates the officine instead of creating another', function () {
    $user = freshJoomlaUser();
    $payload = ['name' => 'Pharmacie A', 'city' => 'Cotonou', 'owner_name' => 'Titulaire'];

    $this->actingAs($user)->post(route('onboarding.profile.store'), $payload);
    $this->actingAs($user)->post(route('onboarding.profile.store'), [...$payload, 'city' => 'Bohicon']);

    expect(Pharmacy::query()->count())->toBe(1)
        ->and($user->fresh()->currentPharmacy->city)->toBe('Bohicon');
});

test('an onboarded officine is redirected away from the onboarding', function () {
    $user = User::factory()->create();
    $user->currentPharmacy->insurers()->attach(Insurer::factory()->create());

    $this->actingAs($user)
        ->get(route('onboarding.profile'))
        ->assertRedirect(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]));
});

test('a pharmacy route sends an un-onboarded officine to the onboarding', function () {
    $this->actingAs(freshJoomlaUser())
        ->get(route('pharmacy.declare'))
        ->assertRedirect(route('onboarding.profile'));
});
```

- [x] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Onboarding/PharmacyProfileTest.php`
Expected: FAIL — `Route [onboarding.profile] not defined.`

- [x] **Step 3: Ajouter le prédicat d'onboarding au modèle User**

Dans `app/Models/User.php` :

```php
    /**
     * Determine whether the user still has to finish setting their officine up.
     *
     * Three things must hold before declaring is possible: an officine exists,
     * it knows its city and owner, and it has ticked at least one insurer.
     */
    public function needsOnboarding(): bool
    {
        $pharmacy = $this->currentPharmacy;

        return $pharmacy === null
            || ! $pharmacy->hasCompleteProfile()
            || $pharmacy->insurers()->doesntExist();
    }
```

- [x] **Step 4: Écrire la requête de validation**

`SavePharmacyProfileRequest` : `name` requis, chaîne, max 200 ; `onpb_license` nullable, chaîne, max 50, unique sur `pharmacies` en ignorant l'officine courante ; `city` requis, max 100 ; `owner_name` requis, max 200.

- [x] **Step 5: Écrire le contrôleur**

`PharmacyProfileController` avec `edit()` et `store()`.

`edit()` redirige vers le tableau de bord si `! $user->needsOnboarding()`, sinon rend `onboarding/Profile` avec la liste des villes déjà connues, pour proposer une saisie assistée.

`store()` crée ou met à jour l'officine dans une transaction, attache l'utilisateur en `Owner` s'il ne l'est pas déjà, appelle `switchPharmacy()`, puis redirige vers `onboarding.insurers`.

Réutiliser `App\Actions\Pharmacies\CreatePharmacy` plutôt que de dupliquer la création et l'attachement : l'action existe et est déjà testée.

- [x] **Step 6: Écrire le middleware**

Create `app/Http/Middleware/EnsureOnboarded.php` — redirige vers `onboarding.profile` quand `$request->user()?->needsOnboarding()`. L'enregistrer sous l'alias `onboarded` dans `bootstrap/app.php`.

- [x] **Step 7: Déclarer les routes et brancher le callback**

Ajouter le groupe onboarding sous `auth` + `can:declare-payments`, sans le middleware `onboarded` — sinon la redirection bouclerait :

```php
Route::middleware(['auth', 'can:declare-payments'])
    ->prefix('onboarding')
    ->name('onboarding.')
    ->group(function () {
        Route::get('/', [PharmacyProfileController::class, 'edit'])->name('profile');
        Route::post('/', [PharmacyProfileController::class, 'store'])->name('profile.store');
    });
```

Ajouter `onboarded` au groupe `pharmacy.` existant et au groupe `{current_pharmacy}`.

Dans `JoomlaCallbackController::landingFor()`, renvoyer `route('onboarding.profile')` quand `$user->needsOnboarding()`.

- [x] **Step 8: Écrire le champ de formulaire et la page**

`FormField.vue` reprend le relevé de `1f` : étiquette mono 10.5px `rgba(23,33,28,.45)` `letter-spacing .05em`, contrôle `h-[46px] rounded-[10px]` blanc bordure 1.5px `rgba(23,33,28,.13)`, texte 500 13px. En erreur, la bordure passe en `--destructive` et le message s'affiche en dessous en 400 11px.

`onboarding/Profile.vue` reprend la carte de gauche de `1f` : bandeau vert officine avec le logo 38px, l'accroche en **Instrument Serif** 24px/1.2 et la promesse de confidentialité en 400 12px/1.55 à 82 % d'opacité ; puis les champs — nom, puis ONPB et ville côte à côte au-delà du téléphone, puis titulaire — et le bouton encre `h-[50px]`.

L'écran n'utilise pas `ConsoleLayout` : l'officine n'a pas encore de navigation à afficher. Layout dédié, centré, colonne bornée à `max-w-[420px]`.

- [x] **Step 9: Vérifier et committer**

```bash
vendor/bin/pest tests/Feature/Onboarding/PharmacyProfileTest.php
npm run build
composer ci:check
git add -A && git commit -m "feat: onboarding de l'officine, étape du profil"
```

---

### Task 2: Onboarding, étape 2 — le choix des assureurs

**Files:**
- Create: `app/Http/Controllers/Onboarding/PharmacyInsurersController.php`
- Create: `app/Http/Requests/Onboarding/SavePharmacyInsurersRequest.php`
- Create: `resources/js/pages/onboarding/Insurers.vue`
- Create: `resources/js/components/aphaspb/InsurerChecklist.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Onboarding/PharmacyInsurersTest.php`

**Interfaces:**
- Consumes: `Insurer::scopeActive()`, `Pharmacy::insurers()`, la tâche 1
- Produces:
  - Routes `GET /onboarding/insurers` nommée `onboarding.insurers`, `POST` nommée `onboarding.insurers.store`
  - `InsurerChecklist` props : `insurers: {id, name}[]`, `modelValue: number[]`, plus un champ libre « Autre… »

- [x] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Onboarding/PharmacyInsurersTest.php`, couvrant :

- la liste ne propose que les assureurs actifs ;
- cocher trois assureurs les rattache à l'officine et redirige vers le tableau de bord ;
- une soumission vide est refusée — déclarer sans assureur n'a pas de sens ;
- une seconde soumission **remplace** la sélection au lieu de l'empiler ;
- le champ libre « Autre… » crée un assureur inactif et le rattache — inactif parce qu'un nom saisi par une officine doit être validé par l'APhaSPB avant d'entrer dans les statistiques du réseau ;
- un nom libre déjà connu ne crée pas de doublon, il rattache l'assureur existant ;
- l'étape redirige vers l'étape 1 si le profil est encore incomplet.

- [x] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Onboarding/PharmacyInsurersTest.php`
Expected: FAIL — `Route [onboarding.insurers] not defined.`

- [x] **Step 3: Écrire la validation**

`SavePharmacyInsurersRequest` : `insurers` requis sans `other`, tableau, min 1 ; `insurers.*` entier existant dans `insurers` ; `other` nullable, chaîne, max 150. Une règle `withValidator` exige au moins l'un des deux.

- [x] **Step 4: Écrire le contrôleur**

`edit()` redirige vers l'étape 1 si le profil est incomplet ; sinon rend `onboarding/Insurers` avec les assureurs actifs et la sélection actuelle.

`store()` résout d'abord le nom libre — `Insurer::firstOrCreate(['name' => trim($other)], ['is_active' => false])` — puis `sync()` la sélection complète, et redirige vers le tableau de bord.

- [x] **Step 5: Écrire la liste cochable et la page**

`InsurerChecklist.vue` : lignes de 44 px minimum, coche 22px, fond `rgba(31,111,74,.06)` quand cochée, filet `border-dashed` avant l'entrée « Autre… » qui déplie un champ texte. Recherche filtrant la liste en local — la liste des assureurs béninois tient largement en mémoire, une requête serveur par frappe serait du gaspillage.

`onboarding/Insurers.vue` reprend la carte de droite de `1f`, même layout centré que l'étape 1, bouton vert portant le décompte : « Continuer · 3 assureurs ».

- [x] **Step 6: Vérifier et committer**

```bash
vendor/bin/pest tests/Feature/Onboarding
npm run build
composer ci:check
git add -A && git commit -m "feat: onboarding de l'officine, choix des assureurs"
```

---

### Task 3: Composants de saisie de la déclaration

**Files:**
- Create: `resources/js/components/aphaspb/AmountField.vue`
- Create: `resources/js/components/aphaspb/DerivedStatusNotice.vue`
- Create: `resources/js/components/aphaspb/DelayStepper.vue`
- Create: `resources/js/components/aphaspb/WizardProgress.vue`
- Create: `resources/js/lib/fcfa.ts`
- Test: `tests/Unit/Fcfa.test.ts` — **non**, il n'y a pas de lanceur de tests JS dans ce projet ; la logique de formatage est donc vérifiée indirectement par les tests de l'écran, et gardée assez simple pour être lue.

**Interfaces:**
- Consumes: les jetons du thème
- Produces:
  - `formatFcfa(value: number): string` — groupage par espace insécable fine, sans décimale
  - `parseFcfa(input: string): number` — retire tout ce qui n'est pas chiffre, borne à 0
  - `AmountField` props : `label`, `modelValue: number`, `shortcut?: {label, value}`, `error?: string`
  - `DerivedStatusNotice` props : `status`, `label`, `settledShare: number`, `outstanding: number`, `manual: boolean`
  - `DelayStepper` props : `modelValue: number | null`, `min?: number`, `max?: number`
  - `WizardProgress` props : `total: number`, `current: number`

- [x] **Step 1: Écrire le formatage**

Create `resources/js/lib/fcfa.ts`. Le XOF n'a pas de décimale : `formatFcfa` groupe les milliers par espace insécable fine (` `), comme le canvas, et `parseFcfa` ne garde que les chiffres — ce qui rend la saisie tolérante aux espaces, aux points et aux virgules collés par un copier-coller.

- [x] **Step 2: Écrire `AmountField`**

Champ `h-14 rounded-xl` blanc, `inputmode="numeric"` pour garder le clavier numérique ouvert du premier au dernier champ, valeur 700 22px, suffixe `FCFA`. Au focus, bordure et halo or. Le raccourci optionnel (« Tout reçu ») s'affiche à droite de l'étiquette et pose la valeur en un geste.

Le champ affiche la valeur formatée mais émet un entier : `@input` passe par `parseFcfa`, `:value` par `formatFcfa`.

- [x] **Step 3: Écrire `DerivedStatusNotice`**

Carte teintée selon le statut — or partiel, vert payé, encre atténuée non payé, terre cuite rejeté. Pastille 24px, titre « Statut déduit : … », jauge `h-2` du pourcentage réglé, ligne « 69 % réglé · reste 380 000 FCFA en attente », puis l'explication. Quand `manual` est vrai, le titre devient « Statut corrigé à la main » et l'explication propose de revenir au statut calculé.

- [x] **Step 4: Écrire `DelayStepper` et `WizardProgress`**

`DelayStepper` : boutons 32px minimum mais cible tactile de 44 px assurée par le padding, `−` et `+` par pas de 1, maintien non requis. Valeur 700 17px, unité « j ». Bornes 0 à 365. Un `<input>` masqué porte la valeur pour la soumission de formulaire.

`WizardProgress` : `total` segments, les `current - 1` premiers en vert, le `current`ᵉ en or, le reste en `rgba(23,33,28,.12)` ; compteur `current/total` en mono à droite.

- [x] **Step 5: Vérifier et committer**

```bash
npm run build && npm run types:check && npm run lint:check
npm run format
git add -A && git commit -m "feat: composants de saisie de la déclaration"
```

---

### Task 4: Écran 3a — la déclaration mensuelle

**Files:**
- Create: `app/Http/Controllers/Pharmacy/DeclarationController.php`
- Create: `app/Http/Requests/Pharmacy/SaveDeclarationRequest.php`
- Create: `app/Services/Declarations/MonthlyDeclarationRun.php`
- Create: `resources/js/pages/pharmacy/Declare.vue`
- Create: `resources/js/pages/pharmacy/DeclareDone.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/ComingSoonController.php`
- Test: `tests/Feature/Pharmacy/DeclarationTest.php`

**Interfaces:**
- Consumes: `Declaration`, `DeclarationStatus`, `DeclarablePeriod`, `Pharmacy::insurers()`, les composants de la tâche 3
- Produces:
  - Route `GET /pharmacy/declare` nommée `pharmacy.declare` — remplace la page d'attente
  - Route `POST /pharmacy/declare` nommée `pharmacy.declare.store`
  - `MonthlyDeclarationRun` : `nextInsurer()`, `progress(): array{current: int, total: int}`, `declarationFor(Insurer)`, `isComplete(): bool`

- [x] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Pharmacy/DeclarationTest.php`, couvrant :

**Le parcours**
- l'écran montre le premier assureur coché non encore déclaré pour le mois courant ;
- la progression compte les assureurs cochés, pas les assureurs existants ;
- enregistrer redirige sur la même route, qui montre l'assureur suivant ;
- quand tous les assureurs du mois sont déclarés, l'écran de fin s'affiche ;
- une officine qui revient plus tard reprend là où elle s'était arrêtée, sans état de session ;
- `?insurer=` permet de revenir corriger un assureur déjà déclaré.

**L'enregistrement**
- deux montants suffisent, le statut est déduit et enregistré ;
- `amount_received` supérieur à `amount_invoiced` est refusé ;
- un montant négatif ou non numérique est refusé ;
- `delay_days` est exigé quand le statut déduit est `paid` ou `partial`, et refusé sinon ;
- choisir explicitement « facture rejetée » met `is_status_manual` et survit à un nouvel enregistrement ;
- une note privée de plus de 150 caractères est refusée ;
- déclarer deux fois le même couple assureur × mois met à jour la déclaration existante, sans doublon ;
- une période de plus de 12 mois en arrière, ou future, est refusée.

**Les frontières**
- une officine ne peut pas déclarer pour un assureur qu'elle n'a pas coché ;
- une officine ne peut pas déclarer pour une autre officine ;
- un compte admin ne peut pas atteindre l'écran ;
- la note privée n'apparaît dans aucune réponse admin — reprise du test de confidentialité, avec une note réelle enregistrée par ce parcours.

- [x] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Pharmacy/DeclarationTest.php`
Expected: FAIL — la route rend encore la page d'attente, pas `pharmacy/Declare`.

- [x] **Step 3: Écrire le service de parcours**

`MonthlyDeclarationRun` porte l'officine et la période, et répond aux questions du parcours en une requête : la liste des assureurs cochés, celles déjà déclarées, la suivante à traiter. Concentrer ces questions ici évite au contrôleur de recompter à chaque appel et rend la reprise testable sans passer par HTTP.

- [x] **Step 4: Écrire la validation**

`SaveDeclarationRequest` :
- `insurer_id` requis, entier, et **présent parmi les assureurs cochés de l'officine courante** — pas seulement existant ;
- `period` validé par `DeclarablePeriod` ;
- `amount_invoiced` requis, entier, min 0 ;
- `amount_received` requis, entier, min 0, `lte:amount_invoiced` ;
- `status` nullable, valeur de `DeclarationStatus` — présent seulement en cas de correction manuelle ;
- `delay_days` requis quand le statut résultant est `paid` ou `partial`, sinon interdit ; entier entre 0 et 365 ;
- `private_note` nullable, max 150.

- [x] **Step 5: Écrire le contrôleur**

`show()` construit le parcours, rend `pharmacy/DeclareDone` si complet, sinon `pharmacy/Declare` avec l'assureur courant, la déclaration existante s'il y en a une, la progression et la période.

`store()` fait un `updateOrCreate` sur le quadruplet `(pharmacy, insurer, year, month)`, pose `is_status_manual` seulement si un statut explicite a été soumis, puis redirige sur `pharmacy.declare`.

- [x] **Step 6: Écrire les pages**

`Declare.vue` assemble le relevé de l'artboard, dans une colonne `max-w-[430px] mx-auto` — un assureur par écran à toutes les largeurs, conformément à §7.5 : au-delà du téléphone la colonne se centre, elle ne s'étire pas. Le statut déduit se recalcule côté client à chaque frappe, pour que le pharmacien voie la conséquence immédiatement, et le serveur le recalcule à l'enregistrement — le client ne fait jamais foi.

`DeclareDone.vue` : écran de fin sobre, récapitulant le nombre de déclarations du mois et offrant de revenir corriger ou d'aller au tableau de bord.

Retirer l'entrée `pharmacy.declare` de la table de copie de `ComingSoonController`.

- [x] **Step 7: Vérifier l'incrément entier**

```bash
npm run build
composer ci:check
```

Expected: `ci:check` passe en entier.

- [x] **Step 8: Commit**

```bash
git add -A && git commit -m "feat: écran de déclaration mensuelle avec montants"
```

---

## Auto-revue du plan

**Couverture de la spec :** §4.5 onboarding scindé, l'étape 1 restant chez Joomla (tâches 1 et 2) ; §5.4 déduction du statut et correction manuelle vérifiées par les tests (tâche 4) ; §5.5 bornes de période via `DeclarablePeriod` (tâche 4) ; §7.4 relevé de l'artboard `3a` (tâches 3 et 4) ; §7.5 responsive — colonne bornée et centrée, cibles de 44 px (tâches 1 à 4).

**Non couvert, et c'est voulu :** l'historique de l'officine et « Mes assureurs » restent des pages d'attente — l'historique appartient à `3b`, donc au plan 3C, et la gestion des assureurs de l'officine est l'étape 2 de l'onboarding rendue modifiable, un écran de plus. Le rappel mensuel par email du 25 (CDC §3.6) n'est toujours pas planifié.

**Cohérence des types :** `User::needsOnboarding()` est produit en tâche 1 et consommé par le middleware, le callback et la tâche 2. `MonthlyDeclarationRun` est produit et consommé en tâche 4 seulement. `formatFcfa` / `parseFcfa` sont produits en tâche 3 et consommés en tâche 4. `DeclarationStatus` vient de l'incrément 2B et sert des deux côtés — les valeurs sont épinglées par `tests/Unit/DeclarationStatusTest.php`.

**Risque principal :** la validation croisée de `delay_days` — requis selon un statut qui est lui-même déduit des deux montants — est le point où une règle de validation peut divorcer de `Declaration::deriveStatus()`. La requête doit appeler la même méthode de déduction que le modèle, pas réimplémenter la règle. Si cela s'avère malcommode depuis un `FormRequest`, extraire la déduction dans une fonction pure appelée par les deux plutôt que la dupliquer.

**Absence de lanceur de tests JS :** le projet n'a ni Vitest ni Jest. `formatFcfa` et `parseFcfa` ne sont donc pas testés unitairement. Les garder trivialement simples, et couvrir leur effet par les tests d'écran, est le compromis retenu — ajouter un lanceur de tests JS est une décision de dépendance qui dépasse ce plan.
