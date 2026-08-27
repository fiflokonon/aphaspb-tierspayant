# Renommage Team → Pharmacy (incrément 2A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Renommer la couche « équipes » du starter kit vers le vocabulaire métier — une officine — puis lui donner ses champs propres, sans changer le comportement de l'autorisation.

**Architecture:** Le starter kit fournit déjà appartenance, rôles, invitations et policies testés. On les conserve intégralement et on ne renomme que le vocabulaire, ce qui évite de réécrire une couche d'autorisation. Deux tâches : un renommage strictement mécanique dont le critère de réussite est que la suite passe à l'identique, puis un changement de comportement isolé (l'espace personnel disparaît, les champs d'officine arrivent).

**Tech Stack:** Laravel 13.29, PHP 8.4, Inertia 3.3, Pest 5.

**Spec:** `docs/superpowers/specs/2026-08-26-aphaspb-design-implementation-design.md` (§5.1, incrément 2 de §13)

## Global Constraints

- Nommage du code, des routes et des colonnes **en anglais** ; le français reste dans les libellés d'interface.
- Aucun identifiant de groupe Joomla hors de `config/joomla.php`.
- La table `users` n'a pas de colonne de mot de passe et n'en gagne pas.
- Après toute modification PHP : `vendor/bin/pint --dirty --format agent`.
- `npm install` / `npm uninstall` sont cassés dans ce dépôt (cf. `.ai/rules/general.md`). Aucune tâche de ce plan n'en a besoin.
- Régénérer les routes avec **`npm run build`**, jamais avec `php artisan wayfinder:generate` seul : la commande artisan ignore `formVariants: true` de `vite.config.ts` et casse les cinq appels `.form()` (cf. `.ai/rules/js.md`). Critère : `npm run types:check` à **zéro** erreur.
- L'application n'est pas déployée : on modifie les migrations d'origine plutôt que d'en empiler des correctives.

## Écart assumé par rapport à la spec

La spec §5.1 écrit « le préfixe de route `{current_team}` devient `{pharmacy}` ». Le starter kit utilise en réalité **deux** paramètres distincts : `current_team` pour le préfixe du tableau de bord et `team` pour les routes de réglages. Les fusionner créerait une collision. Ils deviennent donc `current_pharmacy` et `pharmacy`.

---

### Task 1: Renommage mécanique

Un renommage partiel ne compile pas : cette tâche est atomique et se valide en une fois. Aucun changement de comportement — `is_personal` et l'espace personnel survivent ici et disparaissent en tâche 2.

**Files:** 76 fichiers sous `app/`, `database/`, `routes/`, `tests/`, `resources/js/`. Renommages de fichiers et de dossiers :

| Avant | Après |
|---|---|
| `app/Models/Team.php` | `app/Models/Pharmacy.php` |
| `app/Models/TeamInvitation.php` | `app/Models/PharmacyInvitation.php` |
| `app/Concerns/HasTeams.php` | `app/Concerns/HasPharmacies.php` |
| `app/Concerns/GeneratesUniqueTeamSlugs.php` | `app/Concerns/GeneratesUniquePharmacySlugs.php` |
| `app/Data/TeamPermissions.php` | `app/Data/PharmacyPermissions.php` |
| `app/Data/UserTeam.php` | `app/Data/UserPharmacy.php` |
| `app/Enums/TeamPermission.php` | `app/Enums/PharmacyPermission.php` |
| `app/Enums/TeamRole.php` | `app/Enums/PharmacyRole.php` |
| `app/Policies/TeamPolicy.php` | `app/Policies/PharmacyPolicy.php` |
| `app/Rules/TeamName.php` | `app/Rules/PharmacyName.php` |
| `app/Rules/UniqueTeamInvitation.php` | `app/Rules/UniquePharmacyInvitation.php` |
| `app/Rules/ValidTeamInvitation.php` | `app/Rules/ValidPharmacyInvitation.php` |
| `app/Actions/Teams/CreateTeam.php` | `app/Actions/Pharmacies/CreatePharmacy.php` |
| `app/Http/Controllers/Teams/` | `app/Http/Controllers/Pharmacies/` |
| `app/Http/Requests/Teams/` | `app/Http/Requests/Pharmacies/` |
| `app/Notifications/Teams/TeamInvitation.php` | `app/Notifications/Pharmacies/PharmacyInvitation.php` |
| `app/Http/Middleware/EnsureTeamMembership.php` | `app/Http/Middleware/EnsurePharmacyMembership.php` |
| `app/Http/Middleware/SetTeamUrlDefaults.php` | `app/Http/Middleware/SetPharmacyUrlDefaults.php` |
| `database/factories/TeamFactory.php` | `database/factories/PharmacyFactory.php` |
| `database/factories/TeamInvitationFactory.php` | `database/factories/PharmacyInvitationFactory.php` |
| `database/migrations/2026_01_27_000001_create_teams_table.php` | `..._create_pharmacies_table.php` |
| `database/migrations/2026_01_27_000002_add_current_team_id_to_users_table.php` | `..._add_current_pharmacy_id_to_users_table.php` |
| `tests/Feature/Teams/` | `tests/Feature/Pharmacies/` |
| `tests/.../TeamTest.php` | `PharmacyTest.php` |
| `tests/.../TeamMemberTest.php` | `PharmacyMemberTest.php` |
| `tests/.../TeamInvitationTest.php` | `PharmacyInvitationTest.php` |
| `tests/.../PruneExpiredTeamInvitationsTest.php` | `PruneExpiredPharmacyInvitationsTest.php` |
| `resources/js/pages/teams/` | `resources/js/pages/pharmacies/` |
| `resources/js/components/TeamSwitcher.vue` | `PharmacySwitcher.vue` |
| `resources/js/components/CreateTeamModal.vue` | `CreatePharmacyModal.vue` |
| `resources/js/components/DeleteTeamModal.vue` | `DeletePharmacyModal.vue` |
| `resources/js/components/LeaveTeamModal.vue` | `LeavePharmacyModal.vue` |
| `resources/js/components/TeamInvitationAlert.vue` | `PharmacyInvitationAlert.vue` |
| `resources/js/types/teams.ts` | `resources/js/types/pharmacies.ts` |

`app/Models/Membership.php` garde son nom : il ne porte pas le mot « team ». Seule sa table change.

**Interfaces:**
- Consumes: rien
- Produces: le vocabulaire ci-dessous, consommé par la tâche 2 et par tout l'incrément 2B
  - Modèles `Pharmacy`, `PharmacyInvitation`, `Membership`
  - Tables `pharmacies`, `pharmacy_members`, `pharmacy_invitations`, colonne `users.current_pharmacy_id`
  - Trait `HasPharmacies` : `pharmacies()`, `ownedPharmacies()`, `pharmacyMemberships()`, `currentPharmacy()`, `switchPharmacy(Pharmacy $pharmacy): bool`, `belongsToPharmacy()`, `isCurrentPharmacy()`, `ownsPharmacy()`, `pharmacyRole()`, `toUserPharmacies()`, `toUserPharmacy()`, `toPharmacyPermissions()`, `fallbackPharmacy()`, `hasPharmacyPermission()`
  - Enums `PharmacyRole`, `PharmacyPermission` ; DTO `UserPharmacy`, `PharmacyPermissions`
  - Paramètres de route `current_pharmacy` et `pharmacy` ; noms de route `pharmacies.*`
  - Props Inertia partagées `currentPharmacy` et `pharmacies`

- [x] **Step 1: Enregistrer l'état de référence de la suite**

Run: `php artisan test --compact`
Expected: PASS, **105 tests, 287 assertions**. C'est le chiffre que la tâche doit reproduire à l'identique. S'il diffère, arrêter : l'arbre n'est pas propre.

- [x] **Step 2: Renommer les fichiers et dossiers**

L'ordre importe : les dossiers d'abord, sinon les chemins de fichiers changent sous les pieds.

```bash
git mv app/Actions/Teams app/Actions/Pharmacies
git mv app/Http/Controllers/Teams app/Http/Controllers/Pharmacies
git mv app/Http/Requests/Teams app/Http/Requests/Pharmacies
git mv app/Notifications/Teams app/Notifications/Pharmacies
git mv tests/Feature/Teams tests/Feature/Pharmacies
git mv resources/js/pages/teams resources/js/pages/pharmacies

git mv app/Actions/Pharmacies/CreateTeam.php app/Actions/Pharmacies/CreatePharmacy.php
git mv app/Http/Controllers/Pharmacies/TeamController.php app/Http/Controllers/Pharmacies/PharmacyController.php
git mv app/Http/Controllers/Pharmacies/TeamInvitationController.php app/Http/Controllers/Pharmacies/PharmacyInvitationController.php
git mv app/Http/Controllers/Pharmacies/TeamMemberController.php app/Http/Controllers/Pharmacies/PharmacyMemberController.php
git mv app/Http/Requests/Pharmacies/CreateTeamInvitationRequest.php app/Http/Requests/Pharmacies/CreatePharmacyInvitationRequest.php
git mv app/Http/Requests/Pharmacies/DeleteTeamRequest.php app/Http/Requests/Pharmacies/DeletePharmacyRequest.php
git mv app/Http/Requests/Pharmacies/RespondToTeamInvitationRequest.php app/Http/Requests/Pharmacies/RespondToPharmacyInvitationRequest.php
git mv app/Http/Requests/Pharmacies/SaveTeamRequest.php app/Http/Requests/Pharmacies/SavePharmacyRequest.php
git mv app/Http/Requests/Pharmacies/UpdateTeamMemberRequest.php app/Http/Requests/Pharmacies/UpdatePharmacyMemberRequest.php
git mv app/Notifications/Pharmacies/TeamInvitation.php app/Notifications/Pharmacies/PharmacyInvitation.php

git mv app/Models/Team.php app/Models/Pharmacy.php
git mv app/Models/TeamInvitation.php app/Models/PharmacyInvitation.php
git mv app/Concerns/HasTeams.php app/Concerns/HasPharmacies.php
git mv app/Concerns/GeneratesUniqueTeamSlugs.php app/Concerns/GeneratesUniquePharmacySlugs.php
git mv app/Data/TeamPermissions.php app/Data/PharmacyPermissions.php
git mv app/Data/UserTeam.php app/Data/UserPharmacy.php
git mv app/Enums/TeamPermission.php app/Enums/PharmacyPermission.php
git mv app/Enums/TeamRole.php app/Enums/PharmacyRole.php
git mv app/Policies/TeamPolicy.php app/Policies/PharmacyPolicy.php
git mv app/Rules/TeamName.php app/Rules/PharmacyName.php
git mv app/Rules/UniqueTeamInvitation.php app/Rules/UniquePharmacyInvitation.php
git mv app/Rules/ValidTeamInvitation.php app/Rules/ValidPharmacyInvitation.php
git mv app/Http/Middleware/EnsureTeamMembership.php app/Http/Middleware/EnsurePharmacyMembership.php
git mv app/Http/Middleware/SetTeamUrlDefaults.php app/Http/Middleware/SetPharmacyUrlDefaults.php

git mv database/factories/TeamFactory.php database/factories/PharmacyFactory.php
git mv database/factories/TeamInvitationFactory.php database/factories/PharmacyInvitationFactory.php
git mv database/migrations/2026_01_27_000001_create_teams_table.php database/migrations/2026_01_27_000001_create_pharmacies_table.php
git mv database/migrations/2026_01_27_000002_add_current_team_id_to_users_table.php database/migrations/2026_01_27_000002_add_current_pharmacy_id_to_users_table.php

git mv resources/js/components/TeamSwitcher.vue resources/js/components/PharmacySwitcher.vue
git mv resources/js/components/CreateTeamModal.vue resources/js/components/CreatePharmacyModal.vue
git mv resources/js/components/DeleteTeamModal.vue resources/js/components/DeletePharmacyModal.vue
git mv resources/js/components/LeaveTeamModal.vue resources/js/components/LeavePharmacyModal.vue
git mv resources/js/components/TeamInvitationAlert.vue resources/js/components/PharmacyInvitationAlert.vue
git mv resources/js/types/teams.ts resources/js/types/pharmacies.ts
```

- [x] **Step 3: Substituer les identifiants dans le contenu**

L'ordre est **critique** : du plus spécifique au plus général, sinon `TeamPermissions` devient `PharmacyPermission` + `s`. Les dossiers générés par Wayfinder (`resources/js/actions`, `resources/js/routes`) sont exclus : ils seront régénérés.

```bash
python3 - <<'PY'
import pathlib, re

ROOTS = ['app', 'database', 'routes', 'tests', 'resources/js', 'bootstrap', 'config']
SKIP = ('resources/js/actions', 'resources/js/routes', 'resources/js/wayfinder')
EXT = {'.php', '.vue', '.ts'}

# Du plus spécifique au plus général. Ne jamais réordonner sans réfléchir.
PAIRS = [
    ('GeneratesUniqueTeamSlugs', 'GeneratesUniquePharmacySlugs'),
    ('generateUniqueTeamSlug', 'generateUniquePharmacySlug'),
    ('EnsureTeamMembership', 'EnsurePharmacyMembership'),
    ('SetTeamUrlDefaults', 'SetPharmacyUrlDefaults'),
    ('TeamInvitationAlert', 'PharmacyInvitationAlert'),
    ('TeamInvitationFactory', 'PharmacyInvitationFactory'),
    ('CreateTeamInvitationRequest', 'CreatePharmacyInvitationRequest'),
    ('RespondToTeamInvitationRequest', 'RespondToPharmacyInvitationRequest'),
    ('UniqueTeamInvitation', 'UniquePharmacyInvitation'),
    ('ValidTeamInvitation', 'ValidPharmacyInvitation'),
    ('TeamInvitationController', 'PharmacyInvitationController'),
    ('TeamInvitation', 'PharmacyInvitation'),
    ('TeamPermissions', 'PharmacyPermissions'),
    ('TeamPermission', 'PharmacyPermission'),
    ('UpdateTeamMemberRequest', 'UpdatePharmacyMemberRequest'),
    ('TeamMemberController', 'PharmacyMemberController'),
    ('SaveTeamRequest', 'SavePharmacyRequest'),
    ('DeleteTeamRequest', 'DeletePharmacyRequest'),
    ('TeamController', 'PharmacyController'),
    ('TeamPolicy', 'PharmacyPolicy'),
    ('TeamFactory', 'PharmacyFactory'),
    ('TeamRole', 'PharmacyRole'),
    ('TeamName', 'PharmacyName'),
    ('TeamSwitcher', 'PharmacySwitcher'),
    ('CreateTeamModal', 'CreatePharmacyModal'),
    ('DeleteTeamModal', 'DeletePharmacyModal'),
    ('LeaveTeamModal', 'LeavePharmacyModal'),
    ('HasTeams', 'HasPharmacies'),
    ('UserTeams', 'UserPharmacies'),
    ('UserTeam', 'UserPharmacy'),
    ('CreateTeam', 'CreatePharmacy'),
    ('current_team_id', 'current_pharmacy_id'),
    ('current_team', 'current_pharmacy'),
    ('currentTeam', 'currentPharmacy'),
    ('ownedTeams', 'ownedPharmacies'),
    ('teamMemberships', 'pharmacyMemberships'),
    ('personalTeam', 'personalPharmacy'),
    ('switchTeam', 'switchPharmacy'),
    ('belongsToTeam', 'belongsToPharmacy'),
    ('isCurrentTeam', 'isCurrentPharmacy'),
    ('ownsTeam', 'ownsPharmacy'),
    ('teamRole', 'pharmacyRole'),
    ('toUserTeams', 'toUserPharmacies'),
    ('toUserTeam', 'toUserPharmacy'),
    ('toTeamPermissions', 'toPharmacyPermissions'),
    ('hasTeamPermission', 'hasPharmacyPermission'),
    ('fallbackTeam', 'fallbackPharmacy'),
    ('team_members', 'pharmacy_members'),
    ('team_invitations', 'pharmacy_invitations'),
    ('team_id', 'pharmacy_id'),
    ('Teams', 'Pharmacies'),
    ('teams', 'pharmacies'),
    ('Team', 'Pharmacy'),
    ('team', 'pharmacy'),
]

changed = 0
for root in ROOTS:
    for path in pathlib.Path(root).rglob('*'):
        if path.suffix not in EXT or not path.is_file():
            continue
        if any(str(path).startswith(s) for s in SKIP):
            continue
        original = path.read_text(encoding='utf-8')
        text = original
        for before, after in PAIRS:
            text = text.replace(before, after)
        if text != original:
            path.write_text(text, encoding='utf-8')
            changed += 1
print(f'{changed} fichiers modifiés')
PY
```

- [x] **Step 4: Vérifier qu'aucun résidu ni aucune malformation ne subsiste**

```bash
grep -rniE 'team' app database routes tests resources/js/components resources/js/pages resources/js/layouts resources/js/types config bootstrap --include=*.php --include=*.vue --include=*.ts
grep -rnE 'Pharmacys|pharmacys|Pharmaciess|pharmaciess|PharmacyPharmacy' app database routes tests resources/js --include=*.php --include=*.vue --include=*.ts
```

Expected: **aucune sortie** pour les deux commandes. La première attrape un identifiant oublié, la seconde une substitution qui s'est appliquée deux fois ou a mal pluralisé.

- [x] **Step 5: Régénérer les fonctions de route et lancer la suite**

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
```

`npm run build` et non `php artisan wayfinder:generate` : il régénère les routes *avec* les variantes `.form` et rafraîchit le manifeste Vite, dont les tests de page ont besoin après le renommage du dossier `pages/teams`.

Expected: PASS, **105 tests, 287 assertions** — exactement le chiffre de l'étape 1. Un test en moins signifie un fichier de test non renommé ; un test en échec signifie un identifiant mal substitué.

- [x] **Step 6: Vérifier le front et l'analyse statique**

```bash
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
npm run lint:check
npm run types:check
npm run build
```

Expected: phpstan 0 erreur ; lint propre ; `types:check` **0 erreur** ; build réussi. Toute erreur `TS2307 Cannot find module` signale un import Vue pointant sur un fichier renommé.

- [x] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: renommer la couche équipes vers le vocabulaire officine"
```

---

### Task 2: Champs de l'officine et disparition de l'espace personnel

Seul changement de comportement du plan. Une officine n'est jamais un espace personnel : la notion vient du starter kit et n'a pas de sens ici.

**Files:**
- Modify: `database/migrations/2026_01_27_000001_create_pharmacies_table.php`
- Modify: `app/Models/Pharmacy.php`
- Modify: `app/Concerns/HasPharmacies.php`
- Modify: `app/Data/UserPharmacy.php`
- Modify: `database/factories/PharmacyFactory.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `app/Http/Requests/Pharmacies/SavePharmacyRequest.php`
- Modify: `resources/js/types/pharmacies.ts`
- Modify: les composants Vue lisant `isPersonal`
- Test: `tests/Feature/Pharmacies/PharmacyTest.php`, nouveau `tests/Feature/Pharmacies/PharmacyProfileTest.php`

**Interfaces:**
- Consumes: tout ce que produit la tâche 1
- Produces:
  - `pharmacies` : `id`, `name`, `slug` (unique), `onpb_license` (nullable, unique), `city`, `owner_name`, timestamps, softDeletes — **sans** `is_personal`
  - `Pharmacy::$onpb_license`, `$city`, `$owner_name`
  - `Pharmacy::hasCompleteProfile(): bool` — vrai quand `city` et `owner_name` sont renseignés ; l'onboarding de l'incrément 3 s'y branchera
  - `UserPharmacy` sans propriété `isPersonal`
  - `PharmacyFactory` sans état `personal()`

- [x] **Step 1: Écrire les tests qui échouent**

Create `tests/Feature/Pharmacies/PharmacyProfileTest.php`:

```php
<?php

use App\Models\Pharmacy;
use Illuminate\Support\Facades\Schema;

test('a pharmacy carries its own identifying fields', function () {
    expect(Schema::hasColumns('pharmacies', ['onpb_license', 'city', 'owner_name']))->toBeTrue();
});

test('a pharmacy is never a personal space', function () {
    expect(Schema::hasColumn('pharmacies', 'is_personal'))->toBeFalse();
});

test('the ONPB licence is unique when given', function () {
    Pharmacy::factory()->create(['onpb_license' => 'ONPB-001']);

    expect(fn () => Pharmacy::factory()->create(['onpb_license' => 'ONPB-001']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('the ONPB licence may be left out entirely', function () {
    Pharmacy::factory()->count(2)->create(['onpb_license' => null]);

    expect(Pharmacy::query()->whereNull('onpb_license')->count())->toBe(2);
});

test('a profile is complete once the city and the owner are known', function () {
    expect(Pharmacy::factory()->create()->hasCompleteProfile())->toBeTrue()
        ->and(Pharmacy::factory()->create(['city' => ''])->hasCompleteProfile())->toBeFalse()
        ->and(Pharmacy::factory()->create(['owner_name' => ''])->hasCompleteProfile())->toBeFalse();
});
```

- [x] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Pharmacies/PharmacyProfileTest.php`
Expected: FAIL — les colonnes `onpb_license`, `city` et `owner_name` n'existent pas et `hasCompleteProfile()` est indéfinie.

- [x] **Step 3: Réécrire la migration**

Dans `database/migrations/2026_01_27_000001_create_pharmacies_table.php`, remplacer le bloc `pharmacies` :

```php
        Schema::create('pharmacies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('onpb_license', 50)->nullable()->unique();
            $table->string('city');
            $table->string('owner_name');
            $table->timestamps();
            $table->softDeletes();
        });
```

- [x] **Step 4: Mettre le modèle à jour**

Dans `app/Models/Pharmacy.php` : retirer `is_personal` de l'attribut `#[Fillable]` et du bloc `@property`, y ajouter `onpb_license`, `city` et `owner_name`, puis ajouter :

```php
    /**
     * Determine whether the pharmacy knows who and where it is.
     *
     * The onboarding flow gates on this: a pharmacy created from a Joomla
     * ticket alone has neither a city nor an owner yet.
     */
    public function hasCompleteProfile(): bool
    {
        return filled($this->city) && filled($this->owner_name);
    }
```

- [x] **Step 5: Retirer la notion d'espace personnel**

- `app/Concerns/HasPharmacies.php` : supprimer la méthode `personalPharmacy()`.
- `app/Data/UserPharmacy.php` : supprimer la propriété promue `bool $isPersonal`.
- `app/Concerns/HasPharmacies.php`, méthode `toUserPharmacy()` : supprimer l'argument `isPersonal:`.
- `resources/js/types/pharmacies.ts` : supprimer le champ `isPersonal`.
- Retirer toute lecture de `isPersonal` dans les composants Vue. Les localiser avec :

```bash
grep -rn 'isPersonal\|is_personal\|personalPharmacy' app database resources/js tests --include=*.php --include=*.vue --include=*.ts
```

- [x] **Step 6: Mettre les factories à jour**

Dans `database/factories/PharmacyFactory.php` : supprimer l'état `personal()`, et donner à `definition()` des valeurs métier :

```php
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Pharmacie '.fake()->unique()->lastName(),
            'onpb_license' => fake()->boolean(70) ? 'ONPB-'.fake()->unique()->numberBetween(1000, 9999) : null,
            'city' => fake()->randomElement(['Cotonou', 'Porto-Novo', 'Parakou', 'Abomey-Calavi', 'Bohicon']),
            'owner_name' => fake()->name(),
        ];
    }
```

Dans `database/factories/UserFactory.php`, `configure()` : remplacer `Pharmacy::factory()->personal()->create([...])` par `Pharmacy::factory()->create()`, et retirer le `'name' => $user->name."'s Team"` qui n'a plus de sens — la factory nomme désormais l'officine elle-même.

- [x] **Step 7: Corriger les tests que le changement casse**

Run: `php artisan test --compact`

Les tests de `tests/Feature/Pharmacies/PharmacyTest.php` qui s'appuient sur `personal()` ou sur `isPersonal` doivent être adaptés : une officine créée est simplement une officine. Ne pas supprimer de test — les réécrire pour qu'ils vérifient le même comportement d'appartenance sans la notion d'espace personnel. Si un test ne teste *que* l'espace personnel, il perd son objet : le retirer et le signaler dans le rapport final.

- [x] **Step 8: Vérifier**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
npm run types:check
npm run build
```

Expected: suite verte, phpstan 0 erreur, `types:check` à zéro erreur, build réussi.

- [x] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: champs propres de l'officine et fin de l'espace personnel"
```

---

## Auto-revue du plan

**Couverture de la spec §5.1 :** renommage des trois tables (tâche 1) ; `users.current_team_id` → `current_pharmacy_id` (tâche 1) ; préfixe de route (tâche 1, avec l'écart sur `current_pharmacy` documenté en tête) ; `is_personal` supprimée (tâche 2) ; `onpb_license`, `city`, `owner_name` ajoutées (tâche 2).

Non couvert ici, et c'est voulu : `insurers`, `pharmacy_insurer`, `declarations`, `settings`, la déduction du statut et `NetworkStatsService` appartiennent au plan 2B, qui part du vocabulaire produit par ce plan.

**Cohérence des types :** la tâche 2 ne consomme que des noms produits par la tâche 1 — `Pharmacy`, `PharmacyFactory`, `HasPharmacies`, `UserPharmacy`, la table `pharmacies`. `hasCompleteProfile()` est introduite en tâche 2 et sera consommée par l'onboarding de l'incrément 3, pas par 2B.

**Risque principal :** la substitution `('team', 'pharmacy')` est la plus générale de la liste et frappe aussi des mots anglais dans les commentaires et les libellés d'interface. C'est acceptable — « pharmacy » y est le mot juste — mais l'étape 4 doit être lue, pas seulement exécutée : un `grep` vide prouve l'absence de résidu, pas la justesse d'une phrase.
