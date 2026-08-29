# Unification du patron console — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire passer les trois pages restées sur le starter kit Laravel
(`settings/Profile`, `pharmacies/Index`, `pharmacies/Edit`) au patron console
APhaSPB, reloger le sélecteur d'officine dans la barre latérale, puis supprimer
le starter kit.

**Architecture:** Le shell console est décrit côté serveur par
`App\Support\ConsoleNavigation::forUser()` et rendu passivement par
`ConsoleLayout` → `ConsoleSidebar`. Les trois pages migrées cessent de déclarer
un layout par `defineOptions` et tombent dans le cas par défaut d'un
aiguillage `app.ts` réduit à trois cas. `AdminLayout` et `PharmacyLayout`,
identiques octet pour octet, fusionnent en `ConsoleShellLayout`.

**Tech Stack:** Laravel 13 · Inertia 3 · Vue 3 `<script setup>` · Tailwind 4 ·
Pest · Wayfinder · reka-ui (composants `components/ui`)

**Spec:** `docs/superpowers/specs/2026-08-29-console-pattern-unification-design.md`

## Global Constraints

- **Aucun changement de dépendance.** `package.json` et `composer.json` ne
  bougent pas. Il n'y a pas de runner JS dans ce dépôt : la vérification du
  front est `npm run types:check`, `npm run build`, `npx prettier --write`, et
  les tests de fonctionnalité Inertia qui portent sur les props.
- **Vérifier avec `composer ci:check`**, pas avec `pint --dirty` seul. La CI
  enchaîne `npm run lint:check`, `npm run format:check`, `phpstan analyse`,
  `pint --parallel --test` et `php artisan test`.
- **Ne jamais lancer `php artisan wayfinder:generate` seul** — il ignore
  `formVariants: true` de `vite.config.ts` et casse les appels `.form()`.
  Régénérer avec `npm run build`.
- **Deux échecs préexistants** dans `tests/Feature/Auth/TokenVersionTest.php`
  (`the check is skipped inside the recheck window`, `a guest is not checked at
  all`). Vérifiés sur arbre propre, hors périmètre. Ne pas les traiter, ne pas
  les compter comme régression.
- **Copie en français** sur tout ce qui est touché, libellés de rôles compris.
- **PHP 8.4** : accolades toujours, types de retour explicites, promotion de
  propriétés dans les constructeurs, PHPDoc avec formes de tableau.
- Les composants `components/ui/*` et les sept modales **restent**. Seule leur
  copie est traduite.

---

### Task 1: Libellés de rôles en français

**Files:**
- Modify: `app/Enums/PharmacyRole.php:11-18`
- Test: `tests/Unit/PharmacyRoleTest.php` (create)

**Interfaces:**
- Consumes: rien.
- Produces: `PharmacyRole::label(): string` renvoie `'Titulaire'`,
  `'Gestionnaire'`, `'Membre'`. `PharmacyRole::assignable(): array<array{value:
  string, label: string}>` inchangé de forme, libellés traduits. Consommé par
  `HasPharmacies::toUserPharmacy()` (`roleLabel`), par
  `PharmacyController::edit()` (`members.*.role_label`,
  `invitations.*.role_label`) et par la prop `availableRoles`.

- [ ] **Step 1: Écrire le test qui échoue**

Créer `tests/Unit/PharmacyRoleTest.php` :

```php
<?php

use App\Enums\PharmacyRole;

test('each role carries its French label', function () {
    expect(PharmacyRole::Owner->label())->toBe('Titulaire')
        ->and(PharmacyRole::Admin->label())->toBe('Gestionnaire')
        ->and(PharmacyRole::Member->label())->toBe('Membre');
});

test('the assignable roles exclude the titulaire and carry the same labels', function () {
    expect(PharmacyRole::assignable())->toBe([
        ['value' => 'admin', 'label' => 'Gestionnaire'],
        ['value' => 'member', 'label' => 'Membre'],
    ]);
});
```

- [ ] **Step 2: Lancer le test et vérifier qu'il échoue**

Run: `php artisan test --compact tests/Unit/PharmacyRoleTest.php`
Expected: FAIL — `Failed asserting that 'Owner' is identical to 'Titulaire'.`

- [ ] **Step 3: Traduire les libellés**

Dans `app/Enums/PharmacyRole.php`, remplacer le corps de `label()` :

```php
    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Titulaire',
            self::Admin => 'Gestionnaire',
            self::Member => 'Membre',
        };
    }
```

- [ ] **Step 4: Lancer le test et vérifier qu'il passe**

Run: `php artisan test --compact tests/Unit/PharmacyRoleTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Vérifier que rien d'autre ne casse**

Run: `php artisan test --compact tests/Feature/Pharmacies`
Expected: PASS. `tests/Feature/Pharmacies/PharmacyTest.php:68` compare à
`PharmacyRole::Owner->label()` et suit donc la traduction sans retouche. Si un
test compare à la chaîne `'Owner'` en dur, le corriger vers le libellé
français — c'est un changement de contrat assumé.

- [ ] **Step 6: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/PharmacyRole.php tests/Unit/PharmacyRoleTest.php
git commit -m "feat: libellés de rôles d'officine en français"
```

---

### Task 2: Le changement d'officine mène au tableau de bord de la nouvelle

**Files:**
- Modify: `app/Http/Controllers/Pharmacies/PharmacyController.php:110-117`
- Test: `tests/Feature/Pharmacies/PharmacyTest.php:352-365`

**Interfaces:**
- Consumes: rien de la Task 1.
- Produces: `POST pharmacies.switch` redirige vers
  `route('dashboard', ['current_pharmacy' => $pharmacy->slug])` au lieu de
  `back()`. La Task 4 s'appuie dessus : un `<Link method="post">` nu suffit
  désormais, sans réécriture d'URL côté client.

- [ ] **Step 1: Modifier le test existant pour qu'il échoue**

Dans `tests/Feature/Pharmacies/PharmacyTest.php`, remplacer le corps du test
`users can switch pharmacies` :

```php
test('users can switch pharmacies', function () {
    $user = User::factory()->create();
    $pharmacy = Pharmacy::factory()->create();

    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Member->value]);

    $this->actingAs($user)
        ->post(route('pharmacies.switch', $pharmacy))
        // Not back(): only the dashboard carries {current_pharmacy} in its
        // path, so going back would re-render the officine just left behind.
        ->assertRedirect(route('dashboard', ['current_pharmacy' => $pharmacy->slug]));

    expect($user->fresh()->current_pharmacy_id)->toEqual($pharmacy->id);
});
```

- [ ] **Step 2: Lancer le test et vérifier qu'il échoue**

Run: `php artisan test --compact --filter="users can switch pharmacies"`
Expected: FAIL — la redirection observée est l'URL de repli de `back()`, pas
celle du tableau de bord.

- [ ] **Step 3: Changer la redirection**

Dans `app/Http/Controllers/Pharmacies/PharmacyController.php`, remplacer la
méthode `switch()` :

```php
    /**
     * Switch the user's current pharmacy.
     *
     * Lands on the new officine's dashboard rather than back(): only the
     * dashboard carries {current_pharmacy} in its path, so returning to the
     * previous URL would re-render the officine the user just left.
     */
    public function switch(Request $request, Pharmacy $pharmacy): RedirectResponse
    {
        abort_unless($request->user()->belongsToPharmacy($pharmacy), 403);

        $request->user()->switchPharmacy($pharmacy);

        return to_route('dashboard', ['current_pharmacy' => $pharmacy->slug]);
    }
```

- [ ] **Step 4: Lancer les tests et vérifier qu'ils passent**

Run: `php artisan test --compact tests/Feature/Pharmacies/PharmacyTest.php`
Expected: PASS. `users cannot switch to pharmacy they dont belong to` doit
rester en 403.

Note à consigner dans le message de commit : une officine dont l'onboarding
n'est pas terminé renverra l'utilisateur vers `onboarding.profile`, le
middleware `onboarded` gardant la route `dashboard`. C'est le comportement
voulu — on ne peut pas travailler sur une officine inachevée.

- [ ] **Step 5: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Pharmacies/PharmacyController.php tests/Feature/Pharmacies/PharmacyTest.php
git commit -m "feat: le changement d'officine mène au tableau de bord de la nouvelle"
```

---

### Task 3: Le descripteur console porte les officines et n'abandonne plus personne

**Files:**
- Modify: `app/Support/ConsoleNavigation.php:19-70`
- Test: `tests/Feature/Console/ConsoleShellTest.php`

**Interfaces:**
- Consumes: `PharmacyRole::label()` de la Task 1 (indirectement, via aucun
  champ ici) ; `route('pharmacies.switch')` dont la redirection a changé en
  Task 2.
- Produces: le descripteur `console` gagne
  `account.pharmacies: list<array{name: string, slug: string, switchHref:
  string, current: bool}>`, vide en dessous de deux officines. `forUser()` ne
  renvoie plus `null` pour un utilisateur authentifié sans espace : il renvoie
  `['space' => null, 'nav' => [], 'notices' => [], 'account' => …]`. La Task 4
  consomme `account.pharmacies` ; la Task 9 s'appuie sur le fait que tout
  utilisateur authentifié a un `account`.

- [ ] **Step 1: Écrire les tests qui échouent**

Ajouter dans `tests/Feature/Console/ConsoleShellTest.php`, avant le test
`a pharmacy cannot reach the admin space and an admin cannot declare` :

```php
test('the account lists the officines to switch between, current one flagged', function () {
    $user = User::factory()->create();
    $second = Pharmacy::factory()->create(['name' => 'Pharmacie Zenith']);

    $second->members()->attach($user, ['role' => PharmacyRole::Member->value]);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertInertia(function (AssertableInertia $page) use ($user, $second) {
            $pharmacies = collect($page->toArray()['props']['console']['account']['pharmacies']);

            expect($pharmacies)->toHaveCount(2)
                ->and($pharmacies->firstWhere('slug', $second->slug)['switchHref'])
                ->toBe(route('pharmacies.switch', ['pharmacy' => $second->slug], absolute: false))
                ->and($pharmacies->where('current', true)->pluck('slug')->all())
                ->toBe([$user->currentPharmacy->slug]);
        });
});

test('a single officine offers nothing to switch between', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('console.account.pharmacies', 0),
        );
});

test('a user in no mapped group still gets a shell with a way out', function () {
    // Neither manage-network nor declare-payments: until now this user got no
    // console descriptor at all, and after the starter kit goes away that
    // would leave them with no logout either.
    $this->actingAs(User::factory()->create(['joomla_groups' => []]))
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('console.nav', 0)
            ->has('console.notices', 0)
            ->where('console.account.logoutHref', '/auth/logout'),
        );
});
```

Ajouter en tête du fichier les `use` manquants :

```php
use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
```

- [ ] **Step 2: Lancer les tests et vérifier qu'ils échouent**

Run: `php artisan test --compact tests/Feature/Console/ConsoleShellTest.php`
Expected: FAIL — `Property [console.account.pharmacies] does not exist.` pour
les deux premiers, `Property [console.nav] does not exist.` pour le troisième
(le descripteur vaut `null`).

- [ ] **Step 3: Étendre `ConsoleNavigation`**

Dans `app/Support/ConsoleNavigation.php`, remplacer le bloc `@phpstan-type
Account`, `forUser()` et `account()` :

```php
 * @phpstan-type Account array{name: string, logoutHref: string, pharmacies: list<SwitchablePharmacy>}
 * @phpstan-type SwitchablePharmacy array{name: string, slug: string, switchHref: string, current: bool}
```

```php
    /**
     * The shell descriptor for whichever profile the user belongs to.
     *
     * @return array{space: string|null, nav: list<NavItem>, notices: list<Notice>, account: Account}|null
     */
    public function forUser(?User $user, string $currentPath): ?array
    {
        if ($user === null) {
            return null;
        }

        $shell = match (true) {
            Gate::forUser($user)->allows('manage-network') => $this->admin($currentPath),
            Gate::forUser($user)->allows('declare-payments') => $this->pharmacy($user, $currentPath),
            // No space, but still a session to leave: a bare shell, so the rail
            // renders its account footer and nothing else.
            default => ['space' => null, 'nav' => [], 'notices' => []],
        };

        // Attached here rather than in each shell: the way out of a session
        // does not depend on which space the user landed in, and onboarding —
        // which renders no navigation at all — needs it just as much.
        return [...$shell, 'account' => $this->account($user)];
    }

    /**
     * The signed-in identity, the officines it can move between, and its way out.
     *
     * Server-built like the navigation, for the same reason: the shell renders
     * what it is handed and never resolves a route name itself.
     *
     * @return Account
     */
    protected function account(User $user): array
    {
        return [
            'name' => $user->name,
            'logoutHref' => route('auth.logout', absolute: false),
            'pharmacies' => $this->switchablePharmacies($user),
        ];
    }

    /**
     * The officines this user may move between, the current one included.
     *
     * Empty below two: a titulaire with a single officine has nothing to
     * choose, and an empty list is what tells the rail to omit the block.
     *
     * @return list<SwitchablePharmacy>
     */
    protected function switchablePharmacies(User $user): array
    {
        $pharmacies = $user->pharmacies()
            ->orderBy('pharmacies.name')
            ->get(['pharmacies.id', 'pharmacies.name', 'pharmacies.slug']);

        if ($pharmacies->count() < 2) {
            return [];
        }

        return array_values($pharmacies->map(fn (Pharmacy $pharmacy) => [
            'name' => $pharmacy->name,
            'slug' => $pharmacy->slug,
            'switchHref' => route('pharmacies.switch', ['pharmacy' => $pharmacy->slug], absolute: false),
            'current' => $user->isCurrentPharmacy($pharmacy),
        ])->all());
    }
```

- [ ] **Step 4: Lancer les tests et vérifier qu'ils passent**

Run: `php artisan test --compact tests/Feature/Console/ConsoleShellTest.php`
Expected: PASS (12 tests)

- [ ] **Step 5: Vérifier PHPStan**

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: 0 erreur. Si `switchablePharmacies()` est signalé pour son type de
retour, c'est que `array_values(...->all())` a été omis.

- [ ] **Step 6: Mettre le type TypeScript à jour**

Dans `resources/js/types/console.ts` :

```ts
export type ConsoleSwitchablePharmacy = {
    name: string;
    slug: string;
    switchHref: string;
    current: boolean;
};

export type ConsoleAccount = {
    name: string;
    logoutHref: string;
    pharmacies: ConsoleSwitchablePharmacy[];
};
```

Run: `npm run types:check`
Expected: aucune sortie.

- [ ] **Step 7: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
npx prettier --write resources/js/types/console.ts
git add app/Support/ConsoleNavigation.php tests/Feature/Console/ConsoleShellTest.php resources/js/types/console.ts
git commit -m "feat: le shell console porte les officines et n'abandonne plus les comptes sans espace"
```

---

### Task 4: Le sélecteur d'officine rejoint le pied de la barre

**Files:**
- Modify: `resources/js/layouts/console/ConsoleAccountFooter.vue`
- Test: aucun runner JS — vérification par `types:check`, `build` et la suite
  Inertia existante.

**Interfaces:**
- Consumes: `ConsoleAccount.pharmacies` de la Task 3 ;
  `LogoutLink.vue` (`href: string`, slot par défaut « Se déconnecter »).
- Produces: le pied de barre complet. La Task 9 supprime
  `PharmacySwitcher.vue` en s'appuyant sur ce remplacement.

- [ ] **Step 1: Réécrire le pied de barre**

Remplacer intégralement `resources/js/layouts/console/ConsoleAccountFooter.vue` :

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LogoutLink from '@/components/aphaspb/LogoutLink.vue';
import type { ConsoleAccount } from '@/types/console';

defineProps<{ account: ConsoleAccount }>();
</script>

<template>
    <div class="mt-auto flex flex-col gap-3 pt-4">
        <!--
            Listed in place rather than behind a floating dropdown: the rail is
            212px of flat colour, and a menu that escapes it reads as belonging
            to another application. Absent below two officines — nothing to
            choose.
        -->
        <div
            v-if="account.pharmacies.length > 1"
            class="border-t border-white/[0.12] pt-3"
        >
            <div
                class="font-mono text-[9.5px] font-semibold tracking-[0.06em] text-white/40"
            >
                OFFICINE
            </div>
            <div class="mt-[6px] flex flex-col gap-[2px]">
                <Link
                    v-for="pharmacy in account.pharmacies"
                    :key="pharmacy.slug"
                    :href="pharmacy.switchHref"
                    method="post"
                    as="button"
                    type="button"
                    class="flex min-h-[44px] w-full items-center rounded-lg px-[9px] text-left text-[11.5px] transition-colors"
                    :class="
                        pharmacy.current
                            ? 'bg-white/[0.12] font-semibold text-white'
                            : 'font-medium text-white/[0.62] hover:bg-white/[0.06]'
                    "
                >
                    <span class="truncate">{{ pharmacy.name }}</span>
                </Link>
            </div>
        </div>

        <div class="border-t border-white/[0.12] pt-3">
            <div class="truncate text-[11.5px] font-semibold text-white/80">
                {{ account.name }}
            </div>

            <LogoutLink
                :href="account.logoutHref"
                class="mt-[2px] flex min-h-[44px] w-full items-center text-left text-[11.5px] font-medium text-white/[0.55] transition-colors hover:text-white"
            />
        </div>
    </div>
</template>
```

- [ ] **Step 2: Vérifier les types et le rendu compilé**

```bash
npx prettier --write resources/js/layouts/console/ConsoleAccountFooter.vue
npm run types:check
npm run build
```
Expected: aucune erreur, build réussi.

- [ ] **Step 3: Vérifier que la suite reste verte**

Run: `php artisan test --compact tests/Feature/Console tests/Feature/Pharmacy`
Expected: PASS

- [ ] **Step 4: Committer**

```bash
git add resources/js/layouts/console/ConsoleAccountFooter.vue
git commit -m "feat: le sélecteur d'officine passe dans le pied de la barre console"
```

---

### Task 5: Traduction des six modales

**Files:**
- Modify: `resources/js/components/CreatePharmacyModal.vue`
- Modify: `resources/js/components/DeletePharmacyModal.vue`
- Modify: `resources/js/components/InviteMemberModal.vue`
- Modify: `resources/js/components/LeavePharmacyModal.vue`
- Modify: `resources/js/components/RemoveMemberModal.vue`
- Modify: `resources/js/components/CancelInvitationModal.vue`

**Interfaces:**
- Consumes: rien.
- Produces: les mêmes composants, mêmes props, mêmes `data-test`, copie
  française. Les Tasks 7 et 8 les montent tels quels.

Ne toucher **que** les chaînes visibles. Ne pas changer la structure, les
props, les attributs `data-test`, ni les composants `ui/` utilisés : reka-ui
apporte le piégeage du focus et les rôles ARIA.

- [ ] **Step 1: Appliquer la table de traduction**

| Fichier | Anglais | Français |
|---|---|---|
| `CreatePharmacyModal` | `Create a new pharmacy` | `Nouvelle officine` |
| | `Create a new pharmacy to collaborate with others.` | `Créez une officine pour y travailler à plusieurs.` |
| | `Pharmacy name` (label) | `Nom de l'officine` |
| | `My pharmacy` (placeholder) | `Pharmacie Le Bon Secours` |
| | `Cancel` | `Annuler` |
| | `Create pharmacy` | `Créer l'officine` |
| `DeletePharmacyModal` | `Are you sure?` | `Supprimer cette officine ?` |
| | `This action cannot be undone. This will permanently delete the pharmacy "{name}".` | `Cette action est irréversible. L'officine « {name} » et ses déclarations seront définitivement supprimées.` |
| | `Enter pharmacy name` (placeholder) | `Saisissez le nom de l'officine` |
| | `Cancel` | `Annuler` |
| | `Delete pharmacy` | `Supprimer l'officine` |
| `InviteMemberModal` | `Invite a pharmacy member` | `Inviter un membre` |
| | `Send an invitation to join this pharmacy.` | `Envoyez une invitation à rejoindre cette officine.` |
| | `Email address` (label) | `Adresse e-mail` |
| | `colleague@example.com` (placeholder) | `confrere@exemple.bj` |
| | `Role` (label) | `Rôle` |
| | `Select a role` (placeholder) | `Choisir un rôle` |
| | `Cancel` | `Annuler` |
| | `Send invitation` | `Envoyer l'invitation` |
| `LeavePharmacyModal` | `Leave pharmacy` (titre) | `Quitter l'officine` |
| | `Are you sure you want to leave {name}?` | `Voulez-vous vraiment quitter {name} ?` |
| | `Cancel` | `Annuler` |
| | `Leave pharmacy` (bouton) | `Quitter l'officine` |
| `RemoveMemberModal` | `Remove pharmacy member` | `Retirer ce membre` |
| | `Are you sure you want to remove {name} from this pharmacy?` | `Voulez-vous vraiment retirer {name} de cette officine ?` |
| | `Cancel` | `Annuler` |
| | `Remove member` | `Retirer le membre` |
| `CancelInvitationModal` | `Cancel invitation` (titre) | `Annuler l'invitation` |
| | `Are you sure you want to cancel the invitation for {email}?` | `Voulez-vous vraiment annuler l'invitation envoyée à {email} ?` |
| | `Keep invitation` | `Conserver l'invitation` |
| | `Cancel invitation` (bouton) | `Annuler l'invitation` |

Les interpolations `{{ props.pharmacy.name }}`, `{{ props.member?.name }}`,
`{{ props.invitation?.email }}` et `{{ role.label }}` restent inchangées, à
leur place dans la phrase française. Attention à l'espace insécable avant les
`?` et les `:` — utiliser `&#8239;?` là où la ponctuation double suit un mot.

- [ ] **Step 2: Vérifier**

```bash
npx prettier --write resources/js/components
npm run lint:check
npm run types:check
npm run build
```
Expected: aucune erreur.

- [ ] **Step 3: Vérifier que les tests de fonctionnalité restent verts**

Run: `php artisan test --compact tests/Feature/Pharmacies`
Expected: PASS. Ces tests portent sur les props et les autorisations, pas sur
le balisage — aucun ne doit bouger.

- [ ] **Step 4: Committer**

```bash
git add resources/js/components
git commit -m "feat: traduire les modales d'officine en français"
```

---

### Task 6: `settings/Profile.vue` au patron console

**Files:**
- Modify: `resources/js/pages/settings/Profile.vue` (réécriture complète)
- Test: `tests/Feature/Settings/ProfileUpdateTest.php` (lecture seule —
  vérifier qu'il reste vert, il sert de garde-fou de contrat)

**Interfaces:**
- Consumes: `ConsoleHeader` (`eyebrow: string`, `title: string`, slots
  `#title`, `#filters`, `#action`) ; `FormField` (`label`, `hint?`, `error?`) ;
  `TextInput` (`invalid?`, `placeholder?`, `list?`, `v-model` ou
  `:model-value`, le `name` passant par fallthrough) ;
  `ProfileController.update.form()` de Wayfinder.
- Produces: une page sans `defineOptions({ layout })`. La Task 9 s'appuie
  dessus pour supprimer `settings/Layout.vue`.

Le contrôleur ne bouge pas : `ProfileController::edit()` continue de rendre
`settings/Profile` avec `mustVerifyEmail` et `status`, et `update()` continue
de rediriger vers `profile.edit`.

- [ ] **Step 1: Réécrire la page**

Remplacer intégralement `resources/js/pages/settings/Profile.vue` :

```vue
<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profil & réglages" />

    <ConsoleHeader eyebrow="MON COMPTE" title="Profil & réglages" />

    <div class="mt-5 max-w-[560px] rounded-[11px] border border-border bg-card p-4">
        <div class="text-[12.5px] font-bold text-ink">Identité</div>
        <p class="mt-1 text-[11px]/[1.4] text-ink/[0.45]">
            Ces informations vous identifient auprès de l'APhaSPB.
        </p>

        <Form
            v-bind="ProfileController.update.form()"
            class="mt-4 flex flex-col gap-[11px]"
            #default="{ errors, processing }"
        >
            <FormField label="NOM" :error="errors.name">
                <TextInput
                    name="name"
                    :model-value="user.name"
                    :invalid="!!errors.name"
                    placeholder="Nom et prénom"
                />
            </FormField>

            <FormField label="ADRESSE E-MAIL" :error="errors.email">
                <TextInput
                    name="email"
                    type="email"
                    :model-value="user.email"
                    :invalid="!!errors.email"
                    placeholder="vous@exemple.bj"
                />
            </FormField>

            <p
                v-if="page.props.mustVerifyEmail && !user.email_verified_at"
                class="text-[11px]/[1.5] text-ink/[0.45]"
            >
                Votre adresse n'est pas vérifiée. La vérification est assurée
                par votre compte Joomla, pas ici.
            </p>

            <button
                type="submit"
                :disabled="processing"
                class="mt-[6px] flex h-[46px] items-center justify-center self-start rounded-[10px] bg-primary px-5 text-[12.5px] font-bold text-primary-foreground transition-opacity disabled:opacity-60"
                data-test="update-profile-button"
            >
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </Form>
    </div>
</template>
```

Points de vigilance :
- `defineOptions({ layout: { breadcrumbs: … } })` est **supprimé**. La page
  hérite du layout par défaut, qui reste `[AppLayout, SettingsLayout]` jusqu'à
  la Task 9 — l'écran sera donc temporairement encadré deux fois. C'est
  attendu et résorbé en Task 9.
- `data-test="update-profile-button"` est conservé : `ProfileUpdateTest` ne
  s'en sert pas aujourd'hui, mais le retirer casserait tout test de navigateur
  ajouté ensuite.
- `<h1 class="sr-only">` disparaît : `ConsoleHeader` porte déjà le titre
  visible de la page.

- [ ] **Step 2: Vérifier**

```bash
npx prettier --write resources/js/pages/settings/Profile.vue
npm run types:check
npm run build
```
Expected: aucune erreur. Si `type="email"` est refusé par `TextInput`, c'est
qu'il tombe en fallthrough sur un `<input type="text">` déjà typé — dans ce
cas, retirer `type="email"` et laisser la validation serveur trancher, ou
ajouter une prop `type?: string` à `TextInput` avec `text` par défaut. Choisir
la prop : c'est le composant du projet, et un champ e-mail sur mobile mérite
son clavier.

- [ ] **Step 3: Vérifier le contrat serveur**

Run: `php artisan test --compact tests/Feature/Settings/ProfileUpdateTest.php`
Expected: PASS sans retouche.

- [ ] **Step 4: Committer**

```bash
git add resources/js/pages/settings/Profile.vue resources/js/components/aphaspb/TextInput.vue
git commit -m "feat: la page profil passe au patron console"
```

---

### Task 7: `pharmacies/Index.vue` au patron console

**Files:**
- Modify: `resources/js/pages/pharmacies/Index.vue` (réécriture complète)
- Test: `tests/Feature/Pharmacies/PharmacyTest.php` (lecture seule)

**Interfaces:**
- Consumes: `DataTable` (`title: string`, `columns: string[]`,
  `template: string`, `footer?: string`, slots `#filters` et défaut) ;
  `DataTableRow` (`template: string`, `tone?: DataTableRowTone`) ;
  `ConsoleHeader` ; `CreatePharmacyModal` (slot déclencheur) ;
  `LeavePharmacyModal` (`v-model:open`, `pharmacy`) ; `Pharmacy` de
  `@/types` (`id`, `name`, `slug`, `role?`, `roleLabel?`, `isCurrent?`) ;
  `edit(slug)` de `@/routes/pharmacies`.
- Produces: une page sans `defineOptions({ layout })`.

- [ ] **Step 1: Réécrire la page**

Remplacer intégralement `resources/js/pages/pharmacies/Index.vue` :

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import CreatePharmacyModal from '@/components/CreatePharmacyModal.vue';
import LeavePharmacyModal from '@/components/LeavePharmacyModal.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { edit } from '@/routes/pharmacies';
import type { Pharmacy } from '@/types';

defineProps<{ pharmacies: Pharmacy[] }>();

const TEMPLATE = '2fr 1fr 1.2fr';
const COLUMNS = ['OFFICINE', 'RÔLE', 'ACTIONS'];

const leavePharmacyDialogOpen = ref(false);
const pharmacyLeaving = ref<Pharmacy | null>(null);

const canLeavePharmacy = (pharmacy: Pharmacy) => pharmacy.role !== 'owner';

const openLeavePharmacyDialog = (pharmacy: Pharmacy) => {
    pharmacyLeaving.value = pharmacy;
    leavePharmacyDialogOpen.value = true;
};
</script>

<template>
    <Head title="Mes officines" />

    <ConsoleHeader eyebrow="MON COMPTE" title="Mes officines">
        <template #action>
            <CreatePharmacyModal>
                <button
                    type="button"
                    data-test="pharmacies-new-pharmacy-button"
                    class="flex h-[42px] items-center justify-center rounded-[10px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground transition-colors hover:bg-officine-dark"
                >
                    + Nouvelle officine
                </button>
            </CreatePharmacyModal>
        </template>
    </ConsoleHeader>

    <DataTable
        title="Officines dont vous êtes membre"
        :columns="COLUMNS"
        :template="TEMPLATE"
    >
        <DataTableRow
            v-for="pharmacy in pharmacies"
            :key="pharmacy.id"
            :template="TEMPLATE"
            data-test="pharmacy-row"
        >
            <div class="truncate">{{ pharmacy.name }}</div>
            <div class="font-medium text-ink/60">{{ pharmacy.roleLabel }}</div>
            <div class="flex flex-wrap items-center gap-3 text-[11.5px]">
                <Link
                    :href="edit(pharmacy.slug)"
                    :data-test="
                        pharmacy.role === 'member'
                            ? 'pharmacy-view-button'
                            : 'pharmacy-edit-button'
                    "
                    class="font-semibold text-officine underline underline-offset-2 hover:text-officine-dark"
                >
                    {{ pharmacy.role === 'member' ? 'Ouvrir' : 'Modifier' }}
                </Link>
                <button
                    v-if="canLeavePharmacy(pharmacy)"
                    type="button"
                    data-test="pharmacy-leave-button"
                    class="font-semibold text-terracotta-dark underline underline-offset-2 hover:text-terracotta"
                    @click="openLeavePharmacyDialog(pharmacy)"
                >
                    Quitter
                </button>
            </div>
        </DataTableRow>

        <p
            v-if="pharmacies.length === 0"
            class="border-t border-ink/[0.06] px-4 py-8 text-center text-[12px] text-ink/[0.45]"
        >
            Vous n'appartenez à aucune officine pour l'instant.
        </p>
    </DataTable>

    <LeavePharmacyModal
        v-model:open="leavePharmacyDialogOpen"
        :pharmacy="pharmacyLeaving"
    />
</template>
```

Points de vigilance :
- Les quatre `data-test` (`pharmacy-row`, `pharmacy-view-button`,
  `pharmacy-edit-button`, `pharmacy-leave-button`,
  `pharmacies-new-pharmacy-button`) sont **conservés à l'identique**.
- Les boutons-icônes sous `Tooltip` deviennent des libellés textuels : la
  maquette impose des cibles de 44 px et un intitulé rend l'infobulle inutile.
  `TooltipProvider`, `Tooltip`, `TooltipTrigger`, `TooltipContent` et les
  icônes `Eye`, `Pencil`, `LogOut`, `Plus` de `@lucide/vue` ne sont plus
  importés.
- `Heading` n'est plus importé — c'est ce qui permettra sa suppression en
  Task 9.

- [ ] **Step 2: Vérifier**

```bash
npx prettier --write resources/js/pages/pharmacies/Index.vue
npm run types:check
npm run build
```
Expected: aucune erreur.

- [ ] **Step 3: Vérifier le contrat serveur**

Run: `php artisan test --compact tests/Feature/Pharmacies`
Expected: PASS sans retouche.

- [ ] **Step 4: Committer**

```bash
git add resources/js/pages/pharmacies/Index.vue
git commit -m "feat: la liste des officines passe au patron console"
```

---

### Task 8: `pharmacies/Edit.vue` au patron console

**Files:**
- Modify: `resources/js/pages/pharmacies/Edit.vue` (réécriture du `<template>`
  et des imports ; la logique du `<script setup>` est déplacée telle quelle)
- Test: `tests/Feature/Pharmacies/PharmacyTest.php`,
  `PharmacyMemberTest.php`, `PharmacyInvitationTest.php` (lecture seule)

**Interfaces:**
- Consumes: `DataTable`, `DataTableRow`, `ConsoleHeader`, `FormField`,
  `TextInput` ; les quatre modales `InviteMemberModal`, `RemoveMemberModal`,
  `CancelInvitationModal`, `DeletePharmacyModal` traduites en Task 5 ;
  `DropdownMenu*` et `Avatar*` de `components/ui` ; `useInitials()` ;
  `update(slug)` et `updateMember([slug, id])` de `@/routes/pharmacies` ; les
  props `pharmacy`, `members`, `invitations`, `permissions`, `availableRoles`
  telles que `PharmacyController::edit()` les fournit.
- Produces: une page sans `defineOptions({ layout })` ni `breadcrumbs`.

**Ce qui ne change pas** : les cinq `ref` d'ouverture de modale, `pageTitle`,
`updateMemberRole()`, `confirmRemoveMember()`, `confirmCancelInvitation()`,
`getInitials()`, et tous les gardes `v-if="permissions.*"`. Déplacer ce code
verbatim ; ne rien réécrire.

- [ ] **Step 1: Remplacer les imports et l'en-tête**

Retirer `Heading` des imports. Le fichier importe aujourd'hui
`{ Form, Head, router }` depuis `@inertiajs/vue3` — y ajouter `Link`, requis
par le lien de retour. Ajouter ensuite :

```ts
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { index } from '@/routes/pharmacies';
```

Supprimer le bloc `defineOptions({ layout: (props) => ({ breadcrumbs: … }) })`
en entier, ainsi que l'import de `edit` s'il ne servait qu'aux fils d'Ariane.

Ajouter en tête du `<template>`, à la place de `<h1 class="sr-only">` :

```vue
    <Head :title="pharmacy.name" />

    <ConsoleHeader eyebrow="MON COMPTE" :title="pharmacy.name" />

    <Link
        :href="index()"
        class="mt-2 inline-flex min-h-[44px] items-center text-[11.5px] font-semibold text-ink/[0.55] transition-colors hover:text-ink"
    >
        ← Mes officines
    </Link>
```

`pageTitle` (`Edit … ` / `View …`) disparaît : le bandeau porte le nom de
l'officine, et le droit de modifier se lit déjà à la présence des champs.
Retirer le `computed` correspondant et son import `computed` s'il devient
inutilisé.

- [ ] **Step 2: Section « Officine » — le formulaire de nom**

Remplacer le premier bloc `<div v-if="permissions.canUpdatePharmacy">` par :

```vue
        <div
            v-if="permissions.canUpdatePharmacy"
            class="mt-4 max-w-[560px] rounded-[11px] border border-border bg-card p-4"
        >
            <div class="text-[12.5px] font-bold text-ink">Officine</div>
            <p class="mt-1 text-[11px]/[1.4] text-ink/[0.45]">
                Le nom affiché à vos membres et dans vos déclarations.
            </p>

            <Form
                v-bind="update.form(pharmacy.slug)"
                class="mt-4 flex flex-col gap-[11px]"
                #default="{ errors, processing }"
            >
                <FormField label="NOM DE L'OFFICINE" :error="errors.name">
                    <TextInput
                        name="name"
                        :model-value="pharmacy.name"
                        :invalid="!!errors.name"
                        placeholder="Pharmacie Le Bon Secours"
                    />
                </FormField>

                <button
                    type="submit"
                    :disabled="processing"
                    class="mt-[6px] flex h-[46px] items-center justify-center self-start rounded-[10px] bg-primary px-5 text-[12.5px] font-bold text-primary-foreground transition-opacity disabled:opacity-60"
                >
                    {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
                </button>
            </Form>
        </div>
```

Le `<Heading variant="small" :title="pharmacy.name" />` de la branche
lecture seule (ligne 141 de l'ancien fichier) disparaît : le bandeau le porte.

- [ ] **Step 3: Section « Membres » en `DataTable`**

Déclarer en tête du `<script setup>`, après les `defineProps` :

```ts
const MEMBERS_TEMPLATE = '2fr 1.2fr 1.2fr';
const MEMBERS_COLUMNS = ['MEMBRE', 'RÔLE', 'ACTIONS'];
```

**Attention — `InviteMemberModal` n'expose pas de slot déclencheur.** Seul
`CreatePharmacyModal` en a un (`<DialogTrigger as-child><slot /></DialogTrigger>`).
Les cinq autres modales sont pilotées uniquement par `open` +
`update:open`. Le bouton d'ouverture est donc un frère, pas un enfant.

Remplacer la section membres par :

```vue
        <DataTable
            title="Membres"
            :columns="MEMBERS_COLUMNS"
            :template="MEMBERS_TEMPLATE"
        >
            <template #filters>
                <button
                    v-if="permissions.canCreateInvitation"
                    type="button"
                    class="flex h-[34px] items-center rounded-lg border border-ink/[0.13] px-3 text-[11.5px] font-semibold text-ink transition-colors hover:bg-ink/[0.04]"
                    @click="inviteDialogOpen = true"
                >
                    + Inviter un membre
                </button>
            </template>

            <DataTableRow
                v-for="member in members"
                :key="member.id"
                :template="MEMBERS_TEMPLATE"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <Avatar class="size-7 shrink-0">
                        <AvatarImage v-if="member.avatar" :src="member.avatar" />
                        <AvatarFallback class="text-[10px]">
                            {{ getInitials(member.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0">
                        <div class="truncate">{{ member.name }}</div>
                        <div class="truncate text-[11px] font-medium text-ink/[0.45]">
                            {{ member.email }}
                        </div>
                    </div>
                </div>

                <div class="font-medium text-ink/60">{{ member.role_label }}</div>

                <div class="flex flex-wrap items-center gap-3 text-[11.5px]">
                    <DropdownMenu
                        v-if="
                            member.role !== 'owner' &&
                            permissions.canUpdateMember
                        "
                    >
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                data-test="member-role-trigger"
                                class="flex h-[30px] items-center gap-1 rounded-lg border border-ink/[0.13] px-[9px] text-[11.5px] font-semibold text-ink transition-colors hover:bg-ink/[0.04]"
                            >
                                Changer de rôle
                                <span class="opacity-50" aria-hidden="true"
                                    >▾</span
                                >
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent>
                            <DropdownMenuItem
                                v-for="role in availableRoles"
                                :key="role.value"
                                data-test="member-role-option"
                                @click="updateMemberRole(member, role.value)"
                            >
                                {{ role.label }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <button
                        v-if="
                            member.role !== 'owner' &&
                            permissions.canRemoveMember
                        "
                        type="button"
                        data-test="member-remove-button"
                        class="font-semibold text-terracotta-dark underline underline-offset-2 hover:text-terracotta"
                        @click="confirmRemoveMember(member)"
                    >
                        Retirer
                    </button>
                </div>
            </DataTableRow>
        </DataTable>
```

Trois différences avec l'ancien balisage, toutes délibérées :
- Le déclencheur affichait `{{ member.role_label }}` ; la colonne `RÔLE` le
  porte désormais, donc le bouton dit ce qu'il fait — « Changer de rôle ».
- Le `<Badge v-else>{{ member.role_label }}</Badge>` disparaît pour la même
  raison : il répétait la colonne.
- `TooltipProvider`/`Tooltip` autour du bouton « Retirer » disparaissent, le
  libellé remplaçant l'infobulle.

Les `data-test="member-role-trigger"`, `member-role-option` et
`member-remove-button` sont conservés à l'identique. `ChevronDown` de
`@lucide/vue` n'est plus importé.

- [ ] **Step 4: Section « Invitations en attente » en `DataTable`**

```ts
const INVITATIONS_TEMPLATE = '2fr 1.2fr 1fr 1fr';
const INVITATIONS_COLUMNS = ['E-MAIL', 'RÔLE', 'ENVOYÉE LE', 'ACTIONS'];
```

```vue
        <DataTable
            v-if="invitations.length > 0"
            title="Invitations en attente"
            :columns="INVITATIONS_COLUMNS"
            :template="INVITATIONS_TEMPLATE"
            footer="Une invitation non acceptée expire automatiquement."
        >
            <DataTableRow
                v-for="invitation in invitations"
                :key="invitation.code"
                :template="INVITATIONS_TEMPLATE"
            >
                <div class="truncate">{{ invitation.email }}</div>
                <div class="font-medium text-ink/60">
                    {{ invitation.role_label }}
                </div>
                <div class="font-mono text-[11px] text-ink/60">
                    {{
                        new Date(invitation.created_at).toLocaleDateString(
                            'fr-FR',
                        )
                    }}
                </div>
                <div class="text-[11.5px]">
                    <button
                        v-if="permissions.canCancelInvitation"
                        type="button"
                        class="font-semibold text-terracotta-dark underline underline-offset-2 hover:text-terracotta"
                        @click="confirmCancelInvitation(invitation)"
                    >
                        Annuler
                    </button>
                </div>
            </DataTableRow>
        </DataTable>
```

- [ ] **Step 5: Section « Supprimer l'officine »**

```vue
        <div
            v-if="permissions.canDeletePharmacy"
            class="mt-[22px] max-w-[560px] rounded-[11px] border border-terracotta/[0.35] bg-card p-4"
        >
            <div class="text-[12.5px] font-bold text-terracotta-dark">
                Supprimer l'officine
            </div>
            <p class="mt-1 text-[11px]/[1.4] text-ink/[0.45]">
                L'officine et ses déclarations disparaissent définitivement.
                Cette action est irréversible.
            </p>

            <button
                type="button"
                class="mt-3 flex h-[42px] items-center rounded-[10px] bg-terracotta-dark px-4 text-[12.5px] font-bold text-white transition-opacity hover:opacity-90"
                @click="deleteDialogOpen = true"
            >
                Supprimer l'officine
            </button>
        </div>
```

Les quatre modales pilotées par `open` restent montées en fin de `<template>`,
exactement où l'ancien fichier les plaçait et avec les mêmes liaisons :

```vue
    <InviteMemberModal
        v-model:open="inviteDialogOpen"
        :pharmacy="pharmacy"
        :available-roles="availableRoles"
    />
    <RemoveMemberModal
        v-model:open="removeMemberDialogOpen"
        :pharmacy="pharmacy"
        :member="memberToRemove"
    />
    <CancelInvitationModal
        v-model:open="cancelInvitationDialogOpen"
        :pharmacy="pharmacy"
        :invitation="invitationToCancel"
    />
    <DeletePharmacyModal
        v-model:open="deleteDialogOpen"
        :pharmacy="pharmacy"
    />
```

Reprendre les props exactes depuis l'ancien fichier plutôt que celles-ci si
elles diffèrent : ces modales ne sont pas modifiées par ce plan.

- [ ] **Step 6: Vérifier**

```bash
npx prettier --write resources/js/pages/pharmacies/Edit.vue
npm run lint:check
npm run types:check
npm run build
```
Expected: aucune erreur. `lint:check` signalera tout import devenu inutilisé
(`Heading`, `computed`, icônes `@lucide/vue`, `Tooltip*`, `Label`, `Input`,
`Button`, `Badge`) — les retirer.

- [ ] **Step 7: Vérifier le contrat serveur**

```bash
php artisan test --compact tests/Feature/Pharmacies
```
Expected: PASS sans retouche. Ces trois fichiers portent sur les props, les
autorisations et les effets en base, jamais sur le balisage.

- [ ] **Step 8: Committer**

```bash
git add resources/js/pages/pharmacies/Edit.vue
git commit -m "feat: la page d'édition d'officine passe au patron console"
```

---

### Task 9: Fusion des layouts et suppression du starter kit

**Files:**
- Create: `resources/js/layouts/ConsoleShellLayout.vue`
- Modify: `resources/js/app.ts:1-28`
- Delete: 25 fichiers (liste ci-dessous)
- Test: toute la suite

**Interfaces:**
- Consumes: tout ce qui précède. Les trois pages ne déclarent plus de layout,
  et `ConsoleAccountFooter` remplace `PharmacySwitcher` et `UserMenuContent`.
- Produces: un seul layout console pour toutes les pages hors `Welcome` et
  `onboarding/*`.

- [ ] **Step 1: Créer le layout fusionné**

`AdminLayout.vue` et `PharmacyLayout.vue` sont identiques octet pour octet —
le vérifier d'abord :

```bash
diff resources/js/layouts/AdminLayout.vue resources/js/layouts/PharmacyLayout.vue && echo IDENTIQUES
```

Créer `resources/js/layouts/ConsoleShellLayout.vue` :

```vue
<script setup lang="ts">
import { useConsoleShell } from '@/composables/useConsoleShell';
import ConsoleLayout from './console/ConsoleLayout.vue';

const { space, nav, notices, account } = useConsoleShell();

// Layout props, set by the page through setLayoutProps().
defineProps<{ focus?: boolean }>();
</script>

<template>
    <ConsoleLayout
        :space="space"
        :nav="nav"
        :notices="notices"
        :account="account"
        :focus="focus"
    >
        <slot />
    </ConsoleLayout>
</template>
```

Un seul layout suffit parce que la distinction admin/officine vit entièrement
dans le descripteur construit par `ConsoleNavigation::forUser()`.

- [ ] **Step 2: Réduire l'aiguillage de `app.ts`**

Remplacer les imports et le bloc `layout` de `resources/js/app.ts` :

```ts
import { createInertiaApp } from '@inertiajs/vue3';
import ConsoleShellLayout from '@/layouts/ConsoleShellLayout.vue';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('onboarding/'):
                return OnboardingLayout;
            default:
                return ConsoleShellLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});
```

Les imports `AdminLayout`, `AppLayout`, `PharmacyLayout` et `SettingsLayout`
disparaissent.

- [ ] **Step 3: Supprimer les 25 fichiers**

```bash
git rm \
  resources/js/layouts/AppLayout.vue \
  resources/js/layouts/AdminLayout.vue \
  resources/js/layouts/PharmacyLayout.vue \
  resources/js/layouts/app/AppSidebarLayout.vue \
  resources/js/layouts/app/AppHeaderLayout.vue \
  resources/js/layouts/settings/Layout.vue \
  resources/js/components/AppShell.vue \
  resources/js/components/AppContent.vue \
  resources/js/components/AppSidebar.vue \
  resources/js/components/AppSidebarHeader.vue \
  resources/js/components/AppHeader.vue \
  resources/js/components/AppLogo.vue \
  resources/js/components/AppLogoIcon.vue \
  resources/js/components/Breadcrumbs.vue \
  resources/js/components/NavMain.vue \
  resources/js/components/NavFooter.vue \
  resources/js/components/NavUser.vue \
  resources/js/components/UserInfo.vue \
  resources/js/components/UserMenuContent.vue \
  resources/js/components/PharmacySwitcher.vue \
  resources/js/components/Heading.vue \
  resources/js/components/PlaceholderPattern.vue \
  resources/js/components/TextLink.vue \
  resources/js/components/AlertError.vue \
  resources/js/components/PharmacyInvitationAlert.vue
```

Puis retirer les répertoires devenus vides :

```bash
rmdir resources/js/layouts/app resources/js/layouts/settings 2>/dev/null || true
```

- [ ] **Step 4: Vérifier qu'aucune référence ne subsiste**

```bash
grep -rn "AppLayout\|AppSidebar\|AppHeader\|UserMenuContent\|PharmacySwitcher\|components/Heading\|Breadcrumbs\|NavUser\|NavMain\|NavFooter\|UserInfo\|AppShell\|AppContent\|AppLogo\|settings/Layout\|PlaceholderPattern\|TextLink\|AlertError\|PharmacyInvitationAlert" resources/js
```
Expected: aucune sortie. Toute occurrence est un import oublié à corriger avant
de continuer.

Vérifier aussi les types `BreadcrumbItem` / `NavItem` devenus orphelins :

```bash
grep -rn "BreadcrumbItem\|NavItem" resources/js
```
S'ils ne sont plus référencés que par `resources/js/types/navigation.ts`,
retirer les déclarations mortes de ce fichier. `NavItem` reste utilisé si un
composant survivant l'importe — vérifier avant de supprimer.

- [ ] **Step 5: Reconstruire et vérifier**

```bash
npx prettier --write resources/js
npm run lint:check
npm run types:check
npm run build
```
Expected: aucune erreur. Un `ViteException: Unable to locate file in Vite
manifest` dans les tests signifie que `npm run build` n'a pas été relancé.

- [ ] **Step 6: Lancer la vérification complète**

```bash
composer ci:check
```
Expected: `lint:check`, `format:check`, `types:check`, `pint --parallel --test`
et `phpstan` verts ; `php artisan test` vert **à l'exception des deux échecs
préexistants** de `tests/Feature/Auth/TokenVersionTest.php`. Si un autre test
échoue, c'est une régression : la corriger avant de committer.

- [ ] **Step 7: Committer**

```bash
git add -A
git commit -m "refactor: un seul patron de layout, suppression du starter kit"
```

---

## Vérification finale

Une fois les neuf tâches passées :

- [ ] `composer ci:check` — seuls les deux échecs `TokenVersionTest`
      préexistants subsistent.
- [ ] `grep -rn "class=\"[^\"]*muted-foreground" resources/js/pages` ne renvoie
      rien : ce jeton appartenait au starter kit.
- [ ] Chaque entrée « Profil & réglages » des deux navs console mène à un
      écran qui garde la barre latérale.
- [ ] Le pied de barre affiche la déconnexion partout, et le sélecteur
      d'officine seulement à partir de deux officines.
