# Unification du patron console — étape 2

> Étape 1 (livrée le 29/08/2026) : le shell console porte son pied de compte —
> identité et déconnexion — sur les espaces admin, officine et onboarding.
> Ce document couvre l'étape 2 : la migration des trois pages restées sur le
> starter kit Laravel, et la suppression de celui-ci.

## 1. Problème

L'application fait cohabiter deux familles de layouts. `resources/js/app.ts`
aiguille ainsi :

| Pages | Layout | Origine |
|---|---|---|
| `pharmacy/*`, `admin/*` | `ConsoleLayout` | maquette APhaSPB |
| `onboarding/*` | `OnboardingLayout` | maquette APhaSPB |
| `settings/*`, `pharmacies/*` | `AppLayout` + `settings/Layout` | starter kit, jamais repris |

La nav console propose « Profil & réglages » dans les deux espaces
(`ConsoleNavigation::admin()` et `::pharmacy()`). Cliquer cette entrée éjecte
l'utilisateur du shell : autre barre latérale, autre typographie, copie en
anglais. Trois pages sont concernées — `settings/Profile`, `pharmacies/Index`,
`pharmacies/Edit` — pour 606 lignes.

Ce n'est pas seulement esthétique. Le sélecteur d'officine
(`PharmacySwitcher.vue`) n'est monté que par `AppHeader` et `AppSidebar` : un
titulaire multi-officines ne peut changer d'officine que depuis cette zone
étrangère, jamais depuis les écrans où il travaille.

## 2. Décisions actées

| Question | Décision |
|---|---|
| Périmètre | Les trois pages migrent, puis le starter kit est supprimé. |
| Profondeur de refonte de `pharmacies/Edit` | Coquille console + tableaux `DataTable` ; modales, dropdowns et avatars restent des composants `ui/`. |
| Langue | Tout en français, libellés de rôles compris. |
| Atterrissage après changement d'officine | Tableau de bord de la nouvelle officine. |
| Emplacement de la déconnexion | Pied de la barre latérale — **fait en étape 1**. |

## 3. Architecture cible

### 3.1 Un seul layout console

`AdminLayout.vue` et `PharmacyLayout.vue` sont aujourd'hui **octet pour octet
identiques** : tous deux lisent `useConsoleShell()` et passent le résultat à
`ConsoleLayout`. La distinction admin/officine vit déjà entièrement dans le
descripteur construit par `ConsoleNavigation::forUser()`, pas dans le layout.

Les deux fusionnent en un `ConsoleShellLayout.vue`, et l'aiguillage se réduit à
trois cas :

```ts
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
```

`settings/*` et `pharmacies/*` tombent dans le cas par défaut et héritent du
shell correspondant au profil de l'utilisateur — c'est précisément le
comportement voulu, ces pages étant accessibles aux deux profils.

La prop `focus` (posée par `Declare.vue` via `setLayoutProps`) est conservée
telle quelle sur le layout fusionné.

### 3.2 Un utilisateur sans espace n'est plus sans issue

`ConsoleNavigation::forUser()` renvoie `null` pour un utilisateur authentifié
qui n'a ni `manage-network` ni `declare-payments`. Aujourd'hui il obtient
`AppLayout`, donc un menu de déconnexion. Après suppression du starter kit il
obtiendrait une barre vide et aucune sortie.

`forUser()` renvoie donc, pour ce cas, un shell minimal :
`['space' => null, 'nav' => [], 'notices' => [], 'account' => …]`. La barre
n'affiche alors que le logo et le pied de compte.

### 3.3 Changement d'officine

`PharmacyController::switch()` renvoie actuellement `back()`. Seule la route
`dashboard` porte `{current_pharmacy}` dans son chemin (`routes/web.php:64`),
si bien que revenir en arrière depuis le tableau de bord réaffiche l'ancienne
officine. `PharmacySwitcher.vue` compense par une réécriture de
`window.location` côté client.

`switch()` redirige désormais vers `route('dashboard', $pharmacy)`. Contrat
unique et prévisible, et la réécriture d'URL côté client disparaît avec le
composant.

Le sélecteur est relogé dans le pied de la barre console, sous l'identité :

```
│ ─────────────── │
│ OFFICINE        │
│ Pharmacie X   ▾ │   ← liste dépliée en place, pas de dropdown flottant
│ ─────────────── │
│ Awa Hounkpatin  │
│ Se déconnecter  │
```

Chaque officine est un `<Link method="post">` vers `pharmacies.switch`. Le bloc
n'apparaît qu'à partir de deux officines : un titulaire mono-officine n'a rien
à choisir. Les données descendent par le descripteur console — comme `nav` —
sous la forme `account.pharmacies: [{name, slug, switchHref, current}]`, et non
par les props partagées `pharmacies`/`currentPharmacy`, afin que la barre reste
un rendu passif de ce que le serveur lui remet.

La création d'officine (`CreatePharmacyModal`, aujourd'hui déclenchée depuis le
sélecteur) reste sur `pharmacies/Index`, où elle est déjà présente.

## 4. Les trois écrans

Patron commun, celui des huit écrans déjà livrés : `<Head>`, puis
`<ConsoleHeader eyebrow title>`, puis des cartes
`rounded-[11px] border border-border bg-card p-4`. Champs via
`components/aphaspb/FormField.vue` et `TextInput.vue`, tableaux via
`DataTable`/`DataTableRow`, action principale via `PrimaryAction`.

### 4.1 `settings/Profile.vue`

Le plus simple : 87 lignes, un formulaire à deux champs.

- Bandeau : eyebrow `MON COMPTE`, titre `Profil & réglages`.
- Une carte « Identité » : nom, adresse e-mail, bouton « Enregistrer ».
- La note sur l'e-mail non vérifié est conservée et traduite — elle dit une
  chose vraie et propre à ce projet : la vérification appartient à Joomla.
- `Heading`, `Label`, `Input`, `Button` cèdent la place à `FormField` +
  `TextInput` + `PrimaryAction`.

### 4.2 `pharmacies/Index.vue`

154 lignes, une liste d'officines avec trois actions par ligne.

- Bandeau : eyebrow `MON COMPTE`, titre `Mes officines`, action
  « + Nouvelle officine » (`CreatePharmacyModal` conservée).
- La liste devient un `DataTable` : colonnes `OFFICINE`, `RÔLE`, `ACTIONS`.
- Les trois boutons-icônes sous `Tooltip` deviennent des libellés textuels
  (« Ouvrir », « Modifier », « Quitter ») : la barre de 212 px et les cibles de
  44 px de la maquette s'accommodent mal d'icônes seules, et un intitulé
  supprime le besoin d'infobulle.
- État vide : « Vous n'appartenez à aucune officine pour l'instant. »

### 4.3 `pharmacies/Edit.vue`

365 lignes, cinq sections. Coquille console, contenu conservé.

| Section | Devient |
|---|---|
| Nom de l'officine (formulaire) | Carte « Officine » + `FormField` |
| Membres | `DataTable` — `MEMBRE`, `RÔLE`, `ACTIONS` |
| Invitations en attente | `DataTable` — `E-MAIL`, `RÔLE`, `ENVOYÉE LE`, `ACTIONS` |
| Suppression | Carte de zone dangereuse, bordure `terracotta` |

- Bandeau : eyebrow `MON COMPTE`, titre = nom de l'officine. Les fils d'Ariane
  disparaissent avec `Breadcrumbs` ; le retour se fait par l'entrée de nav
  « Profil & réglages » et par un lien « ← Mes officines » sous le bandeau.
- `defineOptions({ layout: … })` et ses `breadcrumbs` sont retirés des trois
  pages.
- Les cinq modales (`Invite`, `RemoveMember`, `CancelInvitation`,
  `DeletePharmacy`, `LeavePharmacy`), le `DropdownMenu` de rôles et les
  `Avatar` restent en `components/ui`. Leur copie est traduite ; leur structure
  ne bouge pas — reka-ui apporte le piégeage du focus et les rôles ARIA qu'une
  réécriture perdrait.

## 5. Traduction

- `PharmacyRole::label()` renvoie aujourd'hui `ucfirst($this->value)`, soit
  « Owner », « Admin », « Member ». Il passe à un `match` explicite :
  **Titulaire**, **Gestionnaire**, **Membre**. Trois appelants suivent
  (`HasPharmacies::167`, `PharmacyController::69` et `::79`) sans changement de
  code, et `PharmacyTest:68` compare déjà à `PharmacyRole::Owner->label()`,
  donc reste vert.
- Toute la copie des trois pages et des cinq modales passe en français.
- `settings/Layout.vue` disparaissant, ses libellés « Settings » /
  « Manage your profile and account settings » disparaissent avec lui.

## 6. Suppressions

Le graphe de dépendances est clos : une fois les trois pages migrées, ces
fichiers n'ont plus aucun référent.

```
layouts/     AppLayout · app/AppSidebarLayout · app/AppHeaderLayout
             settings/Layout · AdminLayout · PharmacyLayout   (fusionnés)
components/  AppShell · AppContent · AppSidebar · AppSidebarHeader · AppHeader
             AppLogo · AppLogoIcon · Breadcrumbs · NavMain · NavFooter
             NavUser · UserInfo · UserMenuContent · PharmacySwitcher · Heading
déjà morts   PlaceholderPattern · TextLink · AlertError · PharmacyInvitationAlert
```

Vingt-cinq fichiers supprimés, un créé — `ConsoleShellLayout.vue`.

`components/ui/*`, les sept modales et `PendingInvitationsModal` restent : les
pages migrées s'en servent encore. `CreatePharmacyModal` survit aussi à la
disparition de `PharmacySwitcher`, `pharmacies/Index` l'utilisant déjà.

La suppression est le **dernier** pas. Tant qu'une page les référence encore,
rien ne part.

## 7. Tests

Il n'y a pas de runner JS dans ce dépôt (`package.json` n'installe ni vitest ni
testing-library) et en ajouter un demanderait l'accord préalable prévu par
`CLAUDE.md`. La couverture est donc celle des tests de fonctionnalité Inertia,
qui portent sur les props et les redirections, jamais sur le rendu Vue.

| Fichier | Ce qui est ajouté ou modifié |
|---|---|
| `tests/Feature/Console/ConsoleShellTest.php` | Un utilisateur sans espace reçoit un shell réduit qui porte quand même `account`. Les officines du sélecteur ne sont présentes qu'à partir de deux, et chacune porte son `switchHref`. |
| `tests/Feature/Pharmacies/PharmacyTest.php` | `users can switch pharmacies` : la redirection attendue devient `route('dashboard', …)` de la nouvelle officine, plus `back()`. |
| `tests/Unit/PharmacyRoleTest.php` *(nouveau)* | Les trois libellés français. |
| `tests/Feature/Settings/ProfileUpdateTest.php` | Composant `settings/Profile` inchangé, props inchangées : rien à modifier, sert de garde-fou. |

Les tests existants de `pharmacies/*` portent sur les props et les
autorisations, pas sur le balisage : la migration doit les laisser verts sans
retouche. Tout test qui casse signale une régression de contrat, pas un simple
changement de présentation — sauf `role_label`, dont la valeur change.

## 8. Ordre d'exécution

Chaque pas laisse l'application fonctionnelle et la suite verte.

1. `PharmacyRole::label()` en français, avec son test unitaire.
2. `switch()` redirige vers le tableau de bord de la nouvelle officine ; test
   ajusté.
3. `ConsoleNavigation` : shell réduit pour un utilisateur sans espace, et
   `account.pharmacies` pour le sélecteur.
4. Pied de barre : le sélecteur d'officine rejoint `ConsoleAccountFooter`.
5. Traduction des six modales — elles sont partagées, et les pages qui suivent
   les montent déjà en français.
6. `settings/Profile.vue` au patron console.
7. `pharmacies/Index.vue` au patron console.
8. `pharmacies/Edit.vue` au patron console.
9. Fusion `AdminLayout` + `PharmacyLayout` → `ConsoleShellLayout`, aiguillage
   de `app.ts` réduit à trois cas, puis suppression des 25 fichiers de la
   section 6 et `composer ci:check` complet.

## 9. Hors périmètre

- Aucun changement de dépendance, `package.json` compris.
- Aucun changement au modèle d'authentification Joomla, aux gates, ni aux
  services de statistiques.
- Aucune retouche aux huit écrans déjà au patron console.
- Les deux échecs préexistants de `tests/Feature/Auth/TokenVersionTest.php`
  (`the check is skipped inside the recheck window`, `a guest is not checked at
  all`) sont antérieurs à ce chantier — vérifiés sur arbre propre — et ne sont
  pas traités ici.
