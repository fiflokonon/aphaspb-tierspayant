# Tableaux de bord et graphiques (incrément 3C)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Livrer les deux derniers écrans du périmètre : `3b`, le parcours des paiements de l'officine sur 12 mois, et `3c`, l'évolution des délais et les encours agrégés côté APhaSPB.

**Architecture:** Les métriques d'officine vivent dans un `PharmacyStatsService` distinct de `NetworkStatsService`. La séparation n'est pas cosmétique : le service réseau ne doit jamais savoir lire une officine nommément, et le service d'officine ne doit jamais agréger le réseau. Deux classes rendent cette frontière lisible et testable.

**Tech Stack:** Laravel 13.29, Inertia 3.3, Vue 3.5, Tailwind 4, `@unovis/vue`, Pest 5.

**Spec:** `docs/superpowers/specs/2026-08-26-aphaspb-design-implementation-design.md` (§6, §7.3, §7.4, §7.5)

## Global Constraints

- Montants en **FCFA entiers**.
- **Aucun montant rattachable à une officine, aucune note privée, aucune déclaration individuelle** dans l'espace admin. La lecture admin passe par `NetworkStatsService`.
- Les volumes admin s'affichent **en FCFA et en pourcentage conjointement** (décision APhaSPB, spec §2).
- Agrégats en SQL, pas en PHP. Le test de comptage de requêtes est un garde-fou, pas une formalité.
- Deferred props sur les deux graphiques, avec squelette pulsé. Rechargements partiels sur les filtres.
- **Responsive** (spec §7.5) : les graphiques se redimensionnent, les tableaux larges défilent dans leur propre conteneur, le corps de page ne défile jamais latéralement.
- Régénérer les routes avec `npm run build`. Vérifier avec `composer ci:check` en entier.

## Deux indicateurs de délai, pas un

Le canvas nomme les choses précisément et il faut le suivre :

- `2a` — « DÉLAI MOYEN RÉSEAU · statuts payés et partiels confondus » : moyenne **simple** des délais déclarés. Déjà implémentée.
- `3b` et `3c` — « pondéré par les montants » : `Σ(delay_days × amount_received) / Σ(amount_received)`. Un assureur qui règle vite deux petites factures et lentement une énorme ne doit pas paraître rapide.

Les deux coexistent. Ne pas remplacer l'un par l'autre.

## L'ancienneté de l'encours se dérive, sans nouvelle colonne

`3b` répartit l'encours en 0–30 / 31–60 / 61–90 / > 90 jours. Nous ne stockons aucune date de facture — et c'est voulu, le CDC l'exclut. L'ancienneté est donc comptée depuis la **fin du mois déclaré** : un reste dû sur juillet 2026 a, au 15 août, 15 jours d'ancienneté. C'est une approximation assumée, à écrire dans l'interface pour qu'elle ne soit pas lue comme une date de facture.

## Relevé du canvas — 3b

Quatre blocs après le bandeau : trois KPI (`FACTURÉ SUR 12 MOIS` 800/26px avec unité `FCFA` et note « 7 assureurs · 78 déclarations » ; `TAUX DE RECOUVREMENT` ; `VOTRE DÉLAI MOYEN` avec « pondéré par les montants reçus »), puis le graphique « Parcours des paiements » — barres empilées encaissé + reste à recouvrer, en millions de FCFA, mois en cours cerclé en or — puis deux cartes côte à côte : « Encours par ancienneté » en quatre lignes et « Qui vous doit le plus » en liste décroissante, l'assureur sans encours portant la mention « à jour ».

La barre latérale porte un rappel supplémentaire : « Encours à relancer · 2,93 M FCFA · dont 1,18 M au-delà de 60 jours ».

## Relevé du canvas — 3c

Quatre KPI (`FACTURÉ · RÉSEAU` en `Md FCFA` avec « 126 officines déclarantes », `ENCAISSÉ`, `ENCOURS DU RÉSEAU` avec « dont 0,71 Md au-delà de 90 j », `DÉLAI MOYEN PONDÉRÉ` avec « seuil de référence 30 j »), puis la courbe d'évolution par assureur avec la ligne de seuil `SEUIL 30 JOURS` en pointillé, puis le tableau « Montants agrégés par assureur » — colonnes `ASSUREUR`, `OFFICINES (n)`, `FACTURÉ`, `ENCOURS`, `RECOUVRÉ` — avec les lignes « données insuffisantes ».

---

### Task 1: Dépendance graphique et métriques de l'officine

**Files:**
- Modify: `package.json` (via npm install)
- Create: `app/Services/Pharmacy/PharmacyStatsService.php`
- Create: `app/Data/AgeingBucket.php`
- Test: `tests/Feature/Pharmacy/PharmacyStatsTest.php`

**Interfaces:**
- Consumes: `Declaration`, `Insurer`, `Period`, `DeclarationStatus`
- Produces:
  - `@unovis/vue` et `@unovis/ts` installés
  - `AgeingBucket` : `string $label`, `int $amount`, `int $fromDays`, `?int $toDays`
  - `PharmacyStatsService::summary(Pharmacy, int $months)` → `array{invoiced, received, outstanding, recoveryRate, weightedDelayDays, insurers, declarations}`
  - `::monthlyJourney(Pharmacy, int $months)` → `list<array{key, label, invoiced, received, outstanding, isCurrent}>`
  - `::ageingBuckets(Pharmacy)` → `list<AgeingBucket>`
  - `::outstandingByInsurer(Pharmacy)` → `list<array{insurerName, outstanding}>` décroissant
  - `::outstandingBeyond(Pharmacy, int $days): int`

- [x] **Step 1: Installer la dépendance**

```bash
npm install @unovis/vue @unovis/ts
```

Environ 173 paquets, essentiellement des modules `d3` individuels — petits et secouables à l'arborescence. Vérifier ensuite que `npm run build` passe et relever le poids du bundle : si la charge de `@unovis` dépasse nettement le reste, le signaler plutôt que de la laisser passer silencieusement.

- [x] **Step 2: Écrire les tests qui échouent**

Create `tests/Feature/Pharmacy/PharmacyStatsTest.php`, couvrant :

- le récapitulatif somme facturé et reçu sur la fenêtre, et l'encours est leur écart ;
- le taux de recouvrement est `reçu / facturé`, et vaut `null` sans rien de facturé ;
- **le délai moyen est pondéré par les montants reçus** : deux déclarations, 10 jours sur 100 000 et 100 jours sur 900 000, donnent 91 jours, pas 55 ;
- le délai pondéré ignore `unpaid` et `rejected` ;
- le parcours mensuel renvoie exactement `$months` entrées, y compris les mois sans déclaration, à zéro ;
- le mois courant est marqué ;
- les seuils d'ancienneté classent un encours de juillet vu le 15 août dans `0–30 j`, et un encours de mars dans `> 90 j` ;
- l'ancienneté ne compte que le reste dû, pas le facturé ;
- « qui vous doit le plus » trie par encours décroissant et inclut à zéro les assureurs à jour ;
- **une officine ne voit que ses propres déclarations** : deux officines, chacune ne lit que les siennes ;
- le récapitulatif tient en un nombre borné de requêtes.

- [x] **Step 3: Lancer les tests pour vérifier qu'ils échouent**

Run: `vendor/bin/pest tests/Feature/Pharmacy/PharmacyStatsTest.php`
Expected: FAIL — `Class "App\Services\Pharmacy\PharmacyStatsService" not found`

- [x] **Step 4: Écrire le service**

Toutes les méthodes filtrent sur `pharmacy_id` en premier — c'est la frontière de confidentialité de ce service, symétrique de celle de `NetworkStatsService`. Requêtes par `DB::table('declarations')` comme côté réseau : une ligne d'agrégat n'est pas un modèle, et ne pas hydrater évite de charger `private_note`.

Le délai pondéré s'écrit en SQL littéral :

```sql
SUM(CASE WHEN status IN ('paid', 'partial') THEN delay_days * amount_received ELSE 0 END)
  / NULLIF(SUM(CASE WHEN status IN ('paid', 'partial') THEN amount_received ELSE 0 END), 0)
```

L'ancienneté se calcule en PHP à partir des couples `(period_year, period_month, outstanding)` renvoyés par une requête groupée : la conversion « fin de mois → jours écoulés » dépend de la date du jour, et l'écrire en SQL la rendrait dépendante du moteur.

- [x] **Step 5: Vérifier et committer**

```bash
vendor/bin/pest tests/Feature/Pharmacy/PharmacyStatsTest.php
npm run build
composer ci:check
git add -A && git commit -m "feat: métriques de paiement de l'officine et dépendance graphique"
```

---

### Task 2: Métriques réseau manquantes

**Files:**
- Modify: `app/Services/Network/NetworkStatsService.php`
- Test: `tests/Feature/Network/NetworkStatsServiceTest.php`

**Interfaces:**
- Produces:
  - `::networkSummary()` gagne `weightedDelayDays` et `outstandingBeyond90`
  - `::aggregatedByInsurer(Period, Period, ?string)` → par assureur : officines déclarantes, facturé, encours, taux de recouvrement — **sous seuil d'anonymat**, comme `perInsurer`

- [x] **Step 1: Écrire les tests qui échouent**

Ajouter à `NetworkStatsServiceTest` :

- le délai réseau pondéré diffère de la moyenne simple sur un jeu volontairement déséquilibré ;
- `aggregatedByInsurer` renvoie `InsufficientData` sous le seuil, exactement comme `perInsurer` — **c'est le test qui compte** : une seconde méthode d'agrégation par assureur est un second endroit où la règle d'anonymat peut être oubliée ;
- l'encours au-delà de 90 jours ne compte que les mois assez anciens.

- [x] **Step 2 à 4: Implémenter, vérifier, committer**

`aggregatedByInsurer` réutilise `baseQuery` et applique le même filtre de seuil que `perInsurer`. Si la duplication du filtre devient tentante, extraire la décision « suffisant ou non » dans une méthode privée appelée par les deux — un seul endroit doit décider.

```bash
composer ci:check
git add -A && git commit -m "feat: délai réseau pondéré et montants agrégés par assureur"
```

---

### Task 3: Les deux graphiques

**Files:**
- Create: `resources/js/components/aphaspb/charts/InvoicedVsCollectedChart.vue`
- Create: `resources/js/components/aphaspb/charts/DelayTrendChart.vue`
- Create: `resources/js/components/aphaspb/charts/ChartSkeleton.vue`
- Create: `resources/js/lib/millions.ts`

**Interfaces:**
- Produces:
  - `formatMillions(value: number): string` — « 56,2 M », « 6,84 Md », locale française
  - `InvoicedVsCollectedChart` props : `points: {key, label, invoiced, received, outstanding, isCurrent}[]`
  - `DelayTrendChart` props : `series: {name, points: Record<string, number>}[]`, `network: Record<string, number>`, `threshold: number`
  - `ChartSkeleton` props : `height?: number` — squelette pulsé pour les props différées

- [x] **Step 1: Écrire le formatage**

`formatMillions` bascule en milliards au-delà de 1 000 M et sépare la décimale par une virgule, comme le canvas (`56,2 M`, `6,84 Md`).

- [x] **Step 2: Écrire le graphique de barres empilées**

Encaissé en vert officine, reste à recouvrer en gris chaud, empilés — l'écart *est* l'encours, ce qui est le propos du canvas. Axe des ordonnées en millions, axe des abscisses en initiales de mois. Le mois courant reçoit un cerclage or. Séries et couleurs via les jetons CSS, pas des littéraux.

- [x] **Step 3: Écrire le graphique de courbes**

Une courbe par assureur, moyenne réseau en pointillé, et une ligne de seuil horizontale annotée `SEUIL 30 JOURS`. Le seuil vient de la prop, jamais codé en dur : l'admin peut le modifier.

- [x] **Step 4: Vérifier et committer**

Contrôler le rendu à trois largeurs, le conteneur devant se redimensionner sans débordement horizontal du corps de page.

```bash
npm run build && npm run types:check && npm run lint:check
git add -A && git commit -m "feat: graphiques des paiements et des délais"
```

---

### Task 4: Écran 3b — le parcours des paiements de l'officine

**Files:**
- Create: `app/Http/Controllers/Pharmacy/PaymentJourneyController.php`
- Create: `resources/js/pages/pharmacy/Dashboard.vue`
- Modify: `routes/web.php`, `app/Support/ConsoleNavigation.php`
- Delete: `resources/js/pages/Dashboard.vue`, `app/Http/Controllers/DashboardController.php`
- Test: `tests/Feature/Pharmacy/PaymentJourneyTest.php`

**Interfaces:**
- Consumes: `PharmacyStatsService`, les graphiques de la tâche 3
- Produces:
  - La route `dashboard` rend désormais `pharmacy/Dashboard`
  - Le rappel latéral de l'officine devient « Encours à relancer », alimenté par `outstandingBeyond()`

- [x] **Step 1: Écrire le test qui échoue**

Couvrir : les KPI, le parcours et l'ancienneté arrivent en props ; le graphique est une **prop différée** ; une officine ne voit que ses chiffres ; un compte admin ne peut pas atteindre l'écran ; le rappel latéral porte l'encours au-delà de 60 jours.

Le tableau de bord du starter kit affichait les invitations en attente — `DashboardTest` le couvre. Ces tests suivent : soit l'écran conserve le bloc d'invitations, soit les tests correspondants perdent leur objet et sont retirés en le signalant. **Décider en écrivant, pas après.** L'artboard `3b` ne montre aucune invitation ; le bloc part donc, et `PharmacyInvitationTest` continue de couvrir le flux d'invitation par ailleurs.

- [x] **Step 2 à 6: Implémenter, vérifier, committer**

Les deux graphiques passent par `Inertia::defer()` avec `ChartSkeleton` en attente. Les cartes « ancienneté » et « qui vous doit le plus » ne sont pas différées : elles sortent de la même requête que les KPI.

```bash
composer ci:check
git add -A && git commit -m "feat: parcours des paiements de l'officine sur 12 mois"
```

---

### Task 5: Écran 3c — évolution et encours réseau

**Files:**
- Create: `app/Http/Controllers/Admin/NetworkTrendsController.php`
- Create: `resources/js/pages/admin/Trends.vue`
- Modify: `routes/web.php`, `app/Support/ConsoleNavigation.php`
- Test: `tests/Feature/Admin/NetworkTrendsTest.php`

**Interfaces:**
- Consumes: `NetworkStatsService::delayTrend()`, `::aggregatedByInsurer()`, `::networkSummary()`
- Produces:
  - Route `GET /admin/trends` nommée `admin.trends`, entrée « Évolution » dans la navigation admin
  - Page `admin/Trends`

- [x] **Step 1: Écrire le test qui échoue**

Couvrir : les quatre KPI réseau ; la courbe est une prop différée ; le tableau des montants agrégés rend les lignes insuffisantes ; le seuil vient des réglages, pas d'une constante ; **aucune note privée ni nom d'officine dans la réponse** ; un compte officine reçoit un 403.

- [x] **Step 2 à 6: Implémenter, vérifier, committer**

Chaque volume s'affiche en FCFA **et** en part, conformément à la décision APhaSPB de la spec §2 : `4,79 Md FCFA · 70 %`, jamais l'un sans l'autre.

```bash
composer ci:check
git add -A && git commit -m "feat: évolution des délais et encours réseau"
```

---

## Auto-revue du plan

**Couverture :** §6 gagne les deux méthodes manquantes (tâche 2) ; §7.3 les deux graphiques (tâche 3) ; §7.4 les écrans `3b` et `3c` (tâches 4 et 5) ; §7.5 responsive vérifié à trois largeurs (tâche 3). La décision « FCFA et pourcentage » de §2 est appliquée en tâche 5.

**Après cet incrément, le périmètre validé est clos.** Restent explicitement hors périmètre, jamais planifiés : le rappel mensuel par email du 25 (CDC §3.6), l'export CSV (V1.1 du CDC), l'historique de l'officine, la liste des pharmacies inscrites et la gestion des assureurs côté admin — tous encore en pages d'attente. À reprendre dans un incrément 4 si l'APhaSPB les demande pour le pilote.

**Cohérence des types :** `PharmacyStatsService` (tâche 1) est consommé par la tâche 4 seulement ; les ajouts à `NetworkStatsService` (tâche 2) par la tâche 5. `formatMillions` (tâche 3) sert les deux écrans. `AgeingBucket` ne franchit pas la frontière du service d'officine.

**Risque principal :** `aggregatedByInsurer` est un **second** chemin d'agrégation par assureur, donc un second endroit où le seuil d'anonymat peut être omis. Le plan l'exige testé exactement comme `perInsurer`, et suggère d'extraire la décision de suffisance en un point unique. Si les deux méthodes divergent un jour, c'est là que la fuite arrivera.

**Risque secondaire :** l'ancienneté comptée depuis la fin du mois déclaré est une approximation. Elle doit être écrite dans l'interface — « ancienneté comptée depuis la fin du mois déclaré » — sinon un utilisateur la lira comme une date de facture, ce que le CDC a explicitement refusé de stocker.
