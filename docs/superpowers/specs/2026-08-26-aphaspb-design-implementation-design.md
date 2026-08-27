# Plateforme APhaSPB — bascule Joomla et implémentation du design

Date : 2026-08-26
Statut : validé, prêt pour le plan d'implémentation

## 1. Objet et périmètre

Transposer les maquettes du canvas « Propositions design APhaSPB » dans l'application
Laravel, après avoir remplacé l'authentification du starter kit par le flux Joomla
décrit dans `docs/architecture-auth-joomla.md`.

Le canvas contient quatre tours de proposition et dix maquettes. Deux générations du
tour 3 coexistent ; la plus récente fait référence (statut déduit automatiquement,
pas-à-pas de délai, quatrième KPI admin, tableau des montants agrégés).

**Dans le périmètre :**

- L'authentification Joomla côté Laravel, complète et testée.
- La coquille console partagée, reprise de `1c` et `2a`.
- Quatre écrans : `3a` déclaration avec montants, `3b` parcours des paiements
  de l'officine sur 12 mois, `3c` admin évolution des délais et encours réseau,
  `2a` admin indicateurs par assureur.
- L'onboarding métier de l'officine (issu de l'étape 2 de `1f`).

**Hors périmètre, mais présent en navigation avec page d'attente explicite :**
Pharmacies inscrites, Gestion des assureurs, Exports CSV, Profil et réglages.

**Écarté :** les variantes abandonnées du canvas (`1a` checklist dense, `1b`,
`1d` tableau admin sobre, `1e` classement en cartes), et le plugin Joomla lui-même,
qui reste à développer d'après le contrat d'interface déjà fixé dans
`docs/architecture-auth-joomla.md`.

## 2. Décisions actées

| Décision | Choix | Conséquence |
|---|---|---|
| Cadrage fonctionnel | Tour 3, avec montants | Le CDC V1.0 est obsolète sur son point « aucun montant nulle part » |
| Authentification | Joomla JWT d'abord, Fortify retiré | Aucun écran métier avant que l'auth soit en place |
| Appartenance | Officine = ancienne `Team`, renommée `Pharmacy` | Couche invitations et policies réutilisées |
| Transposition | Thème sur primitives shadcn + bibliothèque de graphiques | Deux dépendances ajoutées |
| Graphiques | `@unovis/vue` | Rendu SVG, thémable par variables CSS |
| Volumes admin | FCFA et pourcentage conjointement | Aucun agrégat n'est publié sous une seule forme |

Le CDC V1.0 reste la référence pour tout ce que le canvas ne contredit pas :
deux profils, une déclaration par assureur et par mois, rattrapage sur 12 mois,
note privée invisible pour l'APhaSPB, seuil d'anonymat à 5 officines déclarantes,
rappel mensuel par email le 25.

## 3. Retrait de Fortify

Rendre `users` sans mot de passe rend Fortify inopérant. Le retrait est donc un
préalable, pas un nettoyage optionnel.

**Dépendances retirées :** `laravel/fortify` (et sa dépendance passkeys) côté
Composer ; `@laravel/passkeys` et `vue-input-otp` côté npm.

**PHP supprimé :** `app/Actions/Fortify/` (2 fichiers),
`app/Providers/FortifyServiceProvider.php`, les 5 classes de `app/Http/Responses/`,
`app/Concerns/PasswordValidationRules.php`,
`app/Http/Controllers/Settings/SecurityController.php`,
`app/Http/Requests/Settings/PasswordUpdateRequest.php`,
`app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php`.

**Migrations :** suppression des colonnes `password` et `remember_token` et de la
table `password_reset_tokens` ; suppression des migrations
`2025_08_14_170933_add_two_factor_columns_to_users_table` et
`2024_01_01_000000_create_passkeys_table`.

**Vue supprimé :** `ManagePasskeys`, `ManageTwoFactor`, `PasskeyItem`,
`PasskeyRegister`, `PasskeyVerify`, `PasswordInput`, `TwoFactorRecoveryCodes`,
`TwoFactorSetupModal`, `composables/useTwoFactorAuth.ts`, les pages `settings/`
liées à la sécurité, et la route `.well-known/passkey-endpoints`.

**Tests remplacés — approuvé explicitement :** les 7 fichiers de
`tests/Feature/Auth/` et `tests/Feature/Settings/SecurityTest.php` couvrent des flux
qui cessent d'exister. Ils sont remplacés par `JoomlaCallbackTest`,
`JoomlaJwtGuardTest` et `TokenVersionTest`. Les 4 fichiers de
`tests/Feature/Teams/` sont conservés et renommés sous `tests/Feature/Pharmacies/`.

## 4. Authentification Joomla côté Laravel

Dépendance ajoutée : `firebase/php-jwt`, celle qu'emploie déjà le doc d'architecture.

### 4.1 Table `users`

`id`, `joomla_user_id` (unsigned big, unique), `name`, `email` (unique),
`email_verified_at` (nullable, alimenté depuis `/api/me`), `joomla_groups` (json,
nullable), `token_version` (unsigned int, défaut 0), `current_pharmacy_id`
(nullable), timestamps. **Aucune colonne de mot de passe.**

### 4.2 Composants

- `app/Auth/JoomlaJwtGuard.php` — guard `api`. Lit le Bearer, décode en RS256 avec
  la clé publique, refuse tout `aud` autre que celui configuré, `firstOrNew` sur
  `joomla_user_id`, met à jour `joomla_groups` si le claim diverge.
- `app/Http/Controllers/Auth/JoomlaCallbackController.php` — `POST /auth/callback`.
  Consomme le ticket JWT à usage unique, hydrate le profil via `/api/me` à la
  première venue, `Auth::login()` puis `session()->regenerate()`, redirige vers
  `/{pharmacy}/dashboard` ou `/admin/network` selon les groupes, ou vers
  l'onboarding si le profil de l'officine est incomplet.
- `app/Http/Middleware/VerifyJoomlaTokenVersion.php` — sur le groupe `web`
  authentifié. Revérifie le `token_version` auprès de Joomla au plus une fois par
  tranche de 15 minutes, en mémorisant l'horodatage du dernier contrôle en session ;
  déconnecte et invalide la session en cas d'écart. Ce middleware n'est pas
  retirable au nom de la performance.
- `app/Services/Joomla/JoomlaApiClient.php` — appels machine-to-machine à `/api/me`,
  secret partagé lu dans la configuration, jamais transmis au client.
- `config/joomla.php` — `issuer`, `audience`, `public_key_path`, `api_url`,
  `m2m_secret`, `groups.admin`, `groups.pharmacy`.

### 4.3 Autorisation

Gates déclarées dans `AppServiceProvider` : `manage-network`, `declare-payments`,
`manage-insurers`. Chacune teste l'intersection de `joomla_groups` avec les listes
de `config/joomla.php`. Aucun identifiant de groupe Joomla n'apparaît ailleurs que
dans ce fichier de configuration.

### 4.4 Réponses d'erreur

Tout échec d'authentification renvoie un 401 générique, sans jamais distinguer un
utilisateur inconnu d'un jeton invalide ou expiré.

### 4.5 Inscription

L'écran `1f` du canvas se scinde. L'étape 1 — création de compte, email, mot de
passe — appartient à Joomla et sort du périmètre Laravel. L'étape 2 devient un
onboarding Laravel déclenché au premier callback quand le profil est incomplet :
nom de l'officine, numéro ONPB (optionnel), ville, nom du titulaire, puis choix des
assureurs sous forme de liste plate cochable avec recherche et entrée « Autre »
libre.

## 5. Modèle de données métier

### 5.1 Renommages

`teams` → `pharmacies`, `team_members` → `pharmacy_members`, `team_invitations` →
`pharmacy_invitations`. Modèles, policies, requests, middlewares, composants Vue et
tests suivent. `users.current_team_id` → `current_pharmacy_id`. Le préfixe de route
`{current_team}` devient `{pharmacy}`. La colonne `is_personal` disparaît : une
officine n'est jamais un espace personnel.

`pharmacies` gagne `onpb_license` (nullable, unique si renseigné), `city`
(obligatoire) et `owner_name` (obligatoire).

### 5.2 `insurers` et `pharmacy_insurer`

`insurers` : `id`, `name`, `is_active` (défaut vrai). Table pivot
`insurer_pharmacy` : clé primaire composite `(pharmacy_id, insurer_id)`, aucune
colonne supplémentaire. Le nom suit la convention alphabétique de Laravel, et non
le `pharmacy_insurer` initialement écrit ici.

Un seeder crée les assureurs et courtiers du Bénin nommés dans le canvas et le CDC :
SUNU Assurances, NSIA Assurances, L'Africaine des Assurances, Sanlam Assurances,
Atlantique Assurances, Courtier — Ascoma Bénin.

### 5.3 `declarations`

| Colonne | Type | Contrainte |
|---|---|---|
| `id` | id | |
| `pharmacy_id` | FK `pharmacies`, indexé | jamais exposé dans les statistiques réseau |
| `insurer_id` | FK `insurers` | |
| `period_year` | smallint | |
| `period_month` | smallint | 1 à 12 |
| `amount_invoiced` | bigint unsigned | FCFA, entier — le XOF n'a pas de décimale |
| `amount_received` | bigint unsigned | défaut 0, validé ≤ `amount_invoiced` |
| `status` | enum | `paid`, `partial`, `unpaid`, `rejected` |
| `is_status_manual` | boolean | défaut faux |
| `delay_days` | smallint nullable | requis si `paid` ou `partial` |
| `private_note` | varchar(150) nullable | jamais lisible depuis l'espace admin |
| timestamps | | |

Index unique sur `(pharmacy_id, insurer_id, period_year, period_month)` : une
déclaration par assureur et par mois, modifiable, jamais dupliquée.

Le reste dû est un accesseur calculé (`amount_invoiced - amount_received`), pas une
colonne : le stocker créerait une seconde source de vérité.

### 5.4 Déduction du statut

Règle appliquée à l'enregistrement quand `is_status_manual` est faux :

- `amount_received == 0` → `unpaid`
- `amount_received == amount_invoiced` → `paid`
- entre les deux → `partial`

`rejected` est toujours un choix explicite du pharmacien et met `is_status_manual` à
vrai — aucun couple de montants ne permet de le déduire. Le pharmacien peut corriger
n'importe quel statut déduit ; la correction lève le drapeau et le statut n'est plus
recalculé.

### 5.5 Bornes de saisie

Une période antérieure de plus de 12 mois au mois courant est refusée. Une période
future est refusée. `delay_days` est compté depuis le dépôt de la facture.

### 5.6 `settings`

Table clé/valeur portant les deux seuils que l'admin modifie depuis l'interface :
`payment_delay_threshold_days` (défaut 30) et `anonymity_min_pharmacies` (défaut 5).
Lues par un petit service en cache, jamais dupliquées en dur dans une vue.

## 6. Agrégation réseau et confidentialité

`app/Services/Network/NetworkStatsService.php` est le **seul** chemin par lequel un
compte admin lit des déclarations. Concentrer la règle en un point la rend
vérifiable par un test unique.

- `perInsurer(Period $period, ?string $city)` — indicateurs de `2a` : nombre
  d'officines déclarantes, délai moyen sur les statuts `paid` et `partial`,
  pourcentage de déclarations sous le seuil, taux de rejet, taux de non-paiement.
- `delayTrend(int $months)` — courbe de `3c`, une série par assureur, délai moyen
  pondéré par les montants, moyenne réseau en série séparée.
- `aggregatedAmounts(Period $period)` — facturé, encaissé, encours et taux de
  recouvrement par assureur, plus les totaux réseau de `3c`.

**Règle du seuil.** Tout assureur dont le nombre d'officines déclarantes distinctes
est inférieur à `anonymity_min_pharmacies` renvoie un objet `InsufficientData`
portant le compte réel. Aucun indicateur, aucun montant, aucun point de courbe.
L'interface en fait une ligne calme et expliquée — « DONNÉES INSUFFISANTES · 3
officines déclarantes, les montants s'agrègent à partir de 5 » — jamais une erreur.

**Ce que l'espace admin ne peut pas atteindre**, par construction et non par
convention d'affichage : un montant rattachable à une officine, une note privée, une
déclaration individuelle, un nom d'officine lié à une déclaration.

Les agrégats sont calculés en SQL avec `GROUP BY insurer_id`, pas en PHP. Le CDC
projette 126 officines pour 7 assureurs sur 12 mois ; le N+1 est nommé dans
`CLAUDE.md` comme première cause de lenteur perçue.

## 7. Front

### 7.1 Thème

`resources/css/app.css` reprend la palette du canvas, dérivée du logo :

| Rôle | Valeur |
|---|---|
`--primary` | `#1f6f4a` vert officine
`--primary` foncé | `#17553a`
`--sidebar-background` | `#17211c` encre
`--accent` | `#e8c25c`, variantes `#d9a325` et `#b07c1a` or
`--destructive` | `#c0472f`, variantes `#a8391f` et `#8f2d18` terre cuite
`--background` | `#fdfbf7`, variantes crème `#fdf8ef`, `#faf8f3`, `#f7f5f0`
`--card` | `#fff`
bordures | `rgba(23,33,28,.07)` à `rgba(23,33,28,.14)`

Fontes via Google Fonts : Plus Jakarta Sans (texte et titres), JetBrains Mono
(chiffres, libellés d'axes, étiquettes capitales), Instrument Serif (titres
éditoriaux de `3c`).

Le canvas ne fournit aucune palette sombre. Les pages console sont donc claires
uniquement et le sélecteur d'apparence est retiré de la navigation. Inventer une
variante sombre serait hors périmètre.

### 7.2 Coquille partagée

`resources/js/layouts/ConsoleLayout.vue` reproduit le châssis stable depuis `1c` et
repris en `2a` : barre latérale encre avec bloc marque, navigation, et carte de
rappel en pied ; bandeau de titre portant le nom du contexte, la période, le
sélecteur de filtre et l'action principale ; emplacement de contenu.

`PharmacyLayout.vue` et `AdminLayout.vue` ne font que la remplir. Le rappel de pied
diffère : rappel du 25 côté officine (« 4 déclarations d'août restent à faire »),
rappel de conformité côté admin (« Montants agrégés — sommes du réseau uniquement »
et l'état du seuil d'affichage).

### 7.3 Composants métier

Sous `resources/js/components/aphaspb/` : `KpiCard`, `KpiRow`, `StatusChip` (les
quatre statuts en vert, or, gris et terre cuite), `AmountField` (clavier numérique,
groupage des milliers, suffixe FCFA), `DerivedStatusNotice` (« Statut déduit :
paiement partiel — 69 % réglé, reste 380 000 FCFA »), `DelayStepper` (pas-à-pas
`− 52 +`), `DataTable` (coquille de tableau filtrable, partagée par `2a`, `3c` et
l'historique), `InsufficientDataRow`, `ProgressMiniBar`.

Sous `components/aphaspb/charts/` : `InvoicedVsCollectedChart` (barres appariées
facturé/encaissé, l'écart matérialisant l'encours) et `DelayTrendChart` (courbes
multi-séries avec le seuil de 30 jours en repère pointillé), sur `@unovis/vue`.

### 7.4 Écrans

- **`3a` déclaration** — parcours guidé, un assureur par écran, progression `3/7`.
  Deux champs de montant, statut déduit affiché et corrigeable, pas-à-pas de délai,
  note privée repliée. Rappel de confidentialité en pied d'écran.

  **Responsive, pas mobile uniquement.** Le canvas présente cet artboard à 390 px
  parce que c'est le cas d'usage le plus contraint — une déclaration entre deux
  clients, sur un écran de 5 pouces — mais l'écran doit fonctionner à toutes les
  largeurs. Au-delà de la largeur du téléphone, le parcours reste un assureur par
  écran et se centre dans une colonne bornée plutôt que de s'étirer : les cibles
  tactiles de 44 px, les gros champs de montant et le pas-à-pas de délai gardent
  leur taille, ce qui les rend simplement confortables à la souris. Ne pas dériver
  vers une seconde mise en page au-dessus d'un point de rupture.
- **`3b` parcours des paiements (officine, desktop)** — quatre KPI (facturé sur
  12 mois, taux de recouvrement, délai moyen, encours à relancer avec ancienneté),
  barres facturé/encaissé mois par mois, courbe du délai avec seuil, puis
  l'historique filtrable avec les colonnes de montants.
- **`3c` admin évolution** — quatre KPI réseau, courbe d'évolution du délai par
  assureur avec moyenne réseau et seuil, tableau des montants agrégés par assureur
  incluant les lignes « données insuffisantes ». Tout volume est affiché à la fois
  en valeur FCFA et en part : les KPI portent le montant et son pourcentage
  (`4,79 Md FCFA · 79 %`), et la colonne recouvré du tableau donne le taux à côté
  du montant. Décision APhaSPB actée — ni les valeurs seules, ni les pourcentages
  seuls.
- **`2a` admin indicateurs par assureur** — la vue rapport, la plus proche du CSV que
  l'APhaSPB exportera : trois KPI, tableau triable (officines, délai moyen, ≤ 30 j
  avec mini-barres, rejet, non payé), rappel permanent du seuil d'anonymat.

### 7.5 Tous les écrans sont responsive

Le canvas fixe une largeur par artboard — 390 px pour la déclaration, 1040 px pour
les tableaux de bord — parce qu'un artboard doit bien choisir une largeur. Ce ne
sont **pas** des cibles d'appareil : aucun écran n'est réservé au téléphone ni au
bureau.

- La déclaration `3a` est dessinée dans le cas le plus contraint mais doit servir
  aux deux : un assureur par écran à toutes les largeurs, centré dans une colonne
  bornée au-delà du téléphone. Voir §7.4.
- Les écrans à coquille — `2a`, `3b`, `3c` — sont dessinés au bureau et doivent
  rester utilisables en dessous : la barre latérale se replie, les cartes KPI
  passent de trois colonnes à une, et les tableaux larges défilent
  **horizontalement dans leur propre conteneur**. Le corps de page ne défile
  jamais latéralement.
- Les cibles tactiles de 44 px relevées sur le canvas s'appliquent partout, pas
  seulement sous un point de rupture.

### 7.6 Conventions appliquées

Deferred props sur les deux graphiques, avec squelette pulsé. `prefetch` sur les
`<Link>` de navigation principale. Rechargements partiels sur les filtres de
tableau, jamais de rechargement complet des props. `<script setup>` et Composition
API partout. URLs via Wayfinder, jamais en dur.

## 8. Tests

Feature-first en Pest 5. Une paire de clés RSA de test permet de signer de vrais
JWT RS256 dans la suite, donc l'ensemble du flux est vérifiable sans installation
Joomla.

**Authentification** — un JWT valide ouvre une session et redirige selon le groupe ;
un `aud` étranger, une signature invalide, un jeton expiré et un jeton rejouable
donnent chacun un 401 générique ; le guard `api` lit le Bearer et ignore la session ;
un `token_version` désynchronisé déconnecte à la revérification suivante ; les Gates
accordent `manage-network` au groupe admin et le refusent au groupe pharmacie.

**Déclarations** — la déduction du statut sur les quatre cas ; la correction manuelle
survit à un nouvel enregistrement ; l'unicité assureur × mois interdit le doublon et
autorise la modification ; une période à 13 mois et une période future sont
refusées ; `delay_days` est exigé sur `paid` et `partial`.

**Agrégation** — un assureur à 4 officines déclarantes renvoie `InsufficientData`, le
même à 5 renvoie ses indicateurs ; les moyennes de délai excluent `unpaid` et
`rejected`.

**Confidentialité, le test qui compte le plus** — aucune route de l'espace admin ne
renvoie un montant d'officine, une note privée, ni une déclaration individuelle.

**Pages** — chaque écran répond avec les props attendues, y compris les props
différées.

## 9. Dépendances

Ajoutées : `firebase/php-jwt` (Composer), `@unovis/vue` et `@unovis/ts` (npm).
Retirées : `laravel/fortify`, `@laravel/passkeys`, `vue-input-otp`.

## 10. Points ouverts, hors chemin critique

Ces questions concernent le plugin Joomla, développé séparément, et ne bloquent
aucune tâche Laravel de cette spec :

- Le site Joomla utilise-t-il le MFA ? Si oui, le flux devient login → challenge →
  `/auth/mfa` → JWT, et le callback Laravel est inchangé.
- Existe-t-il des comptes migrés depuis Joomla 3 avec des hash legacy ?
- Laravel et Joomla partagent-ils le VPS ? La spec suppose des vhosts et des
  utilisateurs système distincts, comme l'exige `CLAUDE.md`.

## 11. Note sur CLAUDE.md

`CLAUDE.md` annonce « Laravel 12 + Inertia 2 ». Le dépôt est en Laravel 13.29,
Inertia 3.3 et Pest 5. À corriger dans le fichier au passage.

## 12. Nommage

Le dépôt nomme ses routes, URI, modèles et colonnes en anglais (`dashboard`,
`settings/profile`, `team_members`). Cette convention est conservée : le français
reste dans les libellés d'interface uniquement. Donc `/{pharmacy}/dashboard`,
`/{pharmacy}/declarations`, `/admin/network`, `/admin/trends`, et non des slugs
francisés.

## 13. Découpage d'exécution

Le périmètre est trop large pour un seul plan tenu d'un bout à l'autre. Trois
incréments, chacun laissant la suite de tests verte :

**Incrément 1 — authentification.** Retrait de Fortify, table `users` sans mot de
passe, `JoomlaJwtGuard`, `/auth/callback`, `VerifyJoomlaTokenVersion`,
`JoomlaApiClient`, `config/joomla.php`, Gates, et la nouvelle suite de tests d'auth.
À la fin de cet incrément l'application se connecte via Joomla et le tableau de bord
du starter kit s'affiche encore, non thémé.

**Incrément 2 — domaine.** Renommage `Team` → `Pharmacy` et ses tables, `insurers`,
`pharmacy_insurer`, `declarations`, `settings`, la règle de déduction du statut,
`NetworkStatsService` et le seuil d'anonymat, factories et seeders, et les tests de
déclaration, d'agrégation et de confidentialité. À la fin, tout le métier est en
place et testé, sans écran nouveau.

**Incrément 3 — écrans.** Thème et fontes, `ConsoleLayout` et les deux layouts,
les composants métier, les deux graphiques `@unovis/vue`, puis `3a`, `3b`, `3c`,
`2a`, l'onboarding, et les pages d'attente des entrées de navigation hors périmètre.

L'ordre est contraint : l'incrément 3 s'appuie sur les données de l'incrément 2, qui
s'appuie sur l'identité de l'incrément 1.
