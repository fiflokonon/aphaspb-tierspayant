# Les cinq pages restantes (incrément 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer les cinq pages d'attente par de vraies pages : historique et assureurs côté officine, pharmacies inscrites, gestion des assureurs et export CSV côté APhaSPB.

**Architecture:** Aucune nouvelle couche. Les pages consomment les services existants et les composants de la coquille. Deux exceptions justifiées : la liste des pharmacies inscrites ne passe pas par `NetworkStatsService` parce qu'elle ne lit aucune déclaration, et l'export CSV est un flux téléchargeable, pas une page Inertia.

**Tech Stack:** Laravel 13.29, Inertia 3.3, Vue 3.5, Tailwind 4, Pest 5.

**Spec:** `docs/superpowers/specs/2026-08-26-aphaspb-design-implementation-design.md`, plus le CDC V1.0 §3.4, §3.5 et le tableau des profils de §2.

## Global Constraints

- Montants en **FCFA entiers**, formatés par `App\Support\Fcfa` côté PHP et `formatFcfa` côté TypeScript — jamais `number_format` à la main, l'espace fine insécable compte.
- **La note privée n'est lisible que par l'officine.** Elle apparaît sur l'historique et nulle part ailleurs.
- Régénérer les routes avec **`npm run build`**, jamais `php artisan wayfinder:generate` seul.
- Vérifier avec **`composer ci:check`** en entier avant chaque commit.
- Ne pas nommer une route de façon à produire un identifiant TypeScript réservé.
- Vérifier le rendu **à 375 px et à 1900 px** avec Chromium headless avant de committer une page. Les deux derniers incréments ont livré des écrans cassés que seule la capture révélait.

## Deux règles de confidentialité, pas une

Le CDC autorise ce qu'un lecteur pressé croirait interdit, et c'est ce qui rend la règle facile à casser.

**Les noms d'officines sont autorisés sur la liste des pharmacies inscrites** — §2 du CDC : « Liste des pharmacies inscrites (nom, ville, date d'inscription) SANS accès à leurs déclarations individuelles ni à leurs montants ». La règle réelle est donc : *aucune déclaration, aucun montant, aucune note rattachable à une officine nommée*, et non *aucun nom d'officine*.

Conséquences pour les tests :

- `/admin/network` et `/admin/trends` gardent leurs assertions actuelles, noms compris : ces écrans agrègent des déclarations, donc un nom y serait une fuite.
- `/admin/pharmacies` doit interdire **montants, délais, statuts, notes et compteurs de déclarations**, et autoriser nom, ville, ONPB, date d'inscription.

**Le seuil d'anonymat n'est pas modifiable depuis l'interface.** Un admin qui le descendrait à 1 lirait les indicateurs d'un assureur déclaré par une seule officine — il l'identifierait. Le CDC ne présente comme réglable que le seuil des 30 jours (§3.5). L'écran de gestion n'expose donc que `payment_delay_threshold_days` ; `anonymity_min_pharmacies` reste en base, modifiable par un développeur avec une raison, et un test vérifie qu'aucune route ne l'écrit.

---

### Task 1: Historique des déclarations de l'officine

C'est la page vers laquelle mène la sortie du parcours de déclaration : aujourd'hui elle atterrit sur un placeholder.

**Files:**
- Create: `app/Http/Controllers/Pharmacy/DeclarationHistoryController.php`
- Create: `resources/js/pages/pharmacy/History.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/ComingSoonController.php`
- Test: `tests/Feature/Pharmacy/DeclarationHistoryTest.php`

**Interfaces:**
- Consumes: `Declaration`, `Insurer`, `DeclarationStatus`, `Fcfa`, `DataTable`, `StatusChip`, `FilterChip`
- Produces:
  - Route `GET /pharmacy/history`, nommée `pharmacy.history`, remplaçant la page d'attente
  - Prop `declarations` : liste triée du plus récent au plus ancien
  - Props `insurers`, `years`, `filters` pour les deux filtres

- [ ] **Step 1: Écrire le test qui échoue**

Couvrir :

- l'historique ne renvoie que les déclarations de l'officine courante — deux officines, chacune ne voit que les siennes ;
- le tri est du plus récent au plus ancien, année puis mois ;
- **la note privée est présente dans la réponse** — c'est le seul écran où elle doit apparaître ;
- le filtre par assureur restreint la liste ;
- le filtre par année restreint la liste ;
- un filtre sur un assureur que l'officine n'a pas coché ne fuit rien : la liste revient vide, pas garnie ;
- chaque ligne porte l'URL de correction, qui mène au parcours de déclaration sur le bon assureur et le bon mois ;
- un compte admin reçoit un 403.

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Pharmacy/DeclarationHistoryTest.php`
Expected: FAIL — la route rend encore `pharmacy/ComingSoon`.

- [ ] **Step 3: Écrire le contrôleur**

Charge les déclarations de l'officine avec leur assureur en une requête — `with('insurer')`, pas une requête par ligne. Les filtres sont validés : `insurer` doit appartenir aux assureurs de l'officine, `year` doit être un entier dans la plage des déclarations existantes. Un filtre invalide est ignoré plutôt que de lever : un paramètre bricolé dans l'URL ne doit pas produire une erreur, seulement aucun effet.

L'URL de correction est calculée côté serveur : `route('pharmacy.declare', ['insurer' => …, 'year' => …, 'month' => …])`.

- [ ] **Step 4: Écrire la page**

Reprend le tableau de `1c`, augmenté des colonnes de montants de `3b` : `ASSUREUR`, `MOIS`, `STATUT`, `FACTURÉ`, `REÇU`, `RESTE DÛ`, `DÉLAI`, `NOTE PRIVÉE`, `ACTION`. Gabarit `1.6fr .8fr .9fr 1fr 1fr 1fr .7fr 1.4fr .8fr`, dans le `DataTable` existant — donc défilement horizontal sur téléphone, jamais de défilement du corps de page.

Le statut passe par `StatusChip`, dont le libellé vient du serveur. Les filtres sont des `FilterChip` appliqués par **rechargement partiel** sur `declarations` seulement.

Pied de tableau : « N déclarations · export CSV réservé à l'APhaSPB » — l'officine n'exporte pas, le CDC ne le prévoit pas.

- [ ] **Step 5: Vérifier, y compris visuellement**

```bash
vendor/bin/pest tests/Feature/Pharmacy/DeclarationHistoryTest.php
npm run build
composer ci:check
```

Puis capturer `/pharmacy/history` à 375 px et 1900 px et **regarder les images**.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: historique des déclarations de l'officine"
```

---

### Task 2: Mes assureurs

**Files:**
- Create: `app/Http/Controllers/Pharmacy/PharmacyInsurersController.php`
- Create: `resources/js/pages/pharmacy/Insurers.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/ComingSoonController.php`
- Test: `tests/Feature/Pharmacy/MyInsurersTest.php`

**Interfaces:**
- Consumes: `InsurerChecklist` (écrit pour l'onboarding), `SavePharmacyInsurersRequest`
- Produces: routes `GET` et `PATCH /pharmacy/insurers`, nommées `pharmacy.insurers` et `pharmacy.insurers.update`

- [ ] **Step 1: Écrire le test qui échoue**

Couvrir :

- la page liste les assureurs actifs et coche ceux de l'officine ;
- décocher un assureur le retire du parcours mensuel **sans supprimer ses déclarations passées** — c'est le test qui compte : l'historique est un registre, pas une conséquence de la sélection courante ;
- une déclaration existante continue de compter dans les statistiques réseau après le décochage ;
- tout décocher est refusé — déclarer sans assureur n'a pas de sens, la règle est déjà dans `SavePharmacyInsurersRequest` ;
- le champ libre crée un assureur inactif, comme à l'onboarding ;
- un compte admin reçoit un 403.

- [ ] **Step 2 à 4: Implémenter**

Le contrôleur réutilise `SavePharmacyInsurersRequest` tel quel. La page réutilise `InsurerChecklist` avec un en-tête différent — pas d'« ÉTAPE 2 SUR 2 » ici.

**Avertissement à afficher** quand l'officine décoche un assureur pour lequel elle a des déclarations : « Vos déclarations passées sont conservées. Cet assureur ne vous sera simplement plus proposé chaque mois. » Le contrôleur fournit la liste des assureurs concernés pour que le front sache lesquels annoter.

- [ ] **Step 5: Vérifier et committer**

Capturer aux deux largeurs, puis :

```bash
composer ci:check
git add -A && git commit -m "feat: gestion des assureurs de l'officine"
```

---

### Task 3: Pharmacies inscrites

**Files:**
- Create: `app/Http/Controllers/Admin/RegisteredPharmaciesController.php`
- Create: `resources/js/pages/admin/Pharmacies.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/ComingSoonController.php`
- Test: `tests/Feature/Admin/RegisteredPharmaciesTest.php`

**Interfaces:**
- Consumes: `Pharmacy` directement — cette page ne lit aucune déclaration, donc `NetworkStatsService` n'a rien à faire ici
- Produces: route `GET /admin/pharmacies`, nommée `admin.pharmacies`

- [ ] **Step 1: Écrire le test qui échoue**

Couvrir :

- la liste renvoie nom, ville, ONPB et date d'inscription ;
- **elle ne renvoie aucun montant, délai, statut, note privée ni compteur de déclarations** — assertions explicites sur la réponse sérialisée, dans l'esprit du test de confidentialité existant ;
- le filtre par ville restreint la liste ;
- la recherche par nom restreint la liste ;
- les officines supprimées (softDeletes) n'apparaissent pas ;
- un compte officine reçoit un 403 ;
- le nombre total et le nombre de villes distinctes sont fournis, parce que `2a` affiche déjà « 126 / 148 inscrites » et que les deux chiffres doivent concorder.

**Décision assumée :** aucune colonne « a déjà déclaré » par officine. Le CDC autorise l'identité mais pas les déclarations individuelles, et un booléen par officine est une donnée de déclaration. Le rapport global reste sur `2a`.

- [ ] **Step 2 à 4: Implémenter**

Contrôleur simple, pagination à 50 par page via `Inertia::scroll()` ou une pagination classique — trancher à l'écriture selon ce que la coquille rend le mieux ; 148 officines ne justifient pas d'infrastructure.

Page dans le `DataTable` existant : `OFFICINE`, `VILLE`, `N° ONPB`, `INSCRITE LE`. Un cartouche rappelle en pied : « Cette liste ne donne accès à aucune déclaration ni à aucun montant. »

- [ ] **Step 5: Vérifier et committer**

---

### Task 4: Gestion des assureurs et seuil de référence

**Files:**
- Create: `app/Http/Controllers/Admin/InsurerManagementController.php`
- Create: `app/Http/Requests/Admin/SaveInsurerRequest.php`
- Create: `app/Http/Requests/Admin/SaveThresholdRequest.php`
- Create: `resources/js/pages/admin/Insurers.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/ComingSoonController.php`, `resources/js/pages/admin/Network.vue`
- Test: `tests/Feature/Admin/InsurerManagementTest.php`

**Interfaces:**
- Consumes: `Insurer`, `SettingsRepository`, la Gate `manage-insurers`
- Produces:
  - `GET /admin/insurers` (`admin.insurers`), `POST` (`admin.insurers.store`), `PATCH /admin/insurers/{insurer}` (`admin.insurers.update`)
  - `PATCH /admin/threshold` (`admin.threshold.update`) — le seuil des 30 jours uniquement
  - Le lien « modifier » de `2a`, à côté du seuil, pointe désormais sur cette page

- [ ] **Step 1: Écrire le test qui échoue**

Couvrir :

- la liste montre tous les assureurs, actifs et inactifs, avec le nombre d'officines qui les ont cochés ;
- créer un assureur ; un nom déjà pris est refusé ;
- renommer un assureur ;
- désactiver un assureur le retire des formulaires **sans supprimer ses déclarations** ni le retirer des statistiques passées ;
- réactiver un assureur le remet dans les formulaires ;
- un assureur créé par une officine via le champ libre arrive inactif et peut être validé ici — c'est le circuit prévu à l'onboarding ;
- le seuil de paiement se modifie et `2a` reflète immédiatement la nouvelle valeur ;
- un seuil hors de 1–365 est refusé ;
- **aucune route n'écrit `anonymity_min_pharmacies`** — le test tente de le poser par les deux routes de mise à jour et vérifie que la valeur en base est inchangée ;
- la Gate `manage-insurers` protège toutes les routes : une officine reçoit un 403.

- [ ] **Step 2 à 4: Implémenter**

`SaveThresholdRequest` n'accepte **que** la clé `payment_delay_threshold_days`, entier entre 1 et 365. Ne pas écrire une route générique « mettre à jour un réglage » : une route qui accepte une clé arbitraire est exactement le chemin par lequel le seuil d'anonymat finirait par devenir modifiable.

La page liste les assureurs dans le `DataTable` : `ASSUREUR`, `OFFICINES (n)`, `ÉTAT`, `ACTION`. Les inactifs sont en tonalité `muted` avec un cartouche « INACTIF ». Un bloc séparé porte le seuil de référence, avec le rappel que le seuil d'anonymat des 5 officines n'est pas réglable et pourquoi.

- [ ] **Step 5: Vérifier et committer**

---

### Task 5: Export CSV des statistiques agrégées

**Files:**
- Create: `app/Http/Controllers/Admin/NetworkExportController.php`
- Create: `app/Services/Network/NetworkCsvExport.php`
- Create: `resources/js/pages/admin/Exports.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/ComingSoonController.php`
- Test: `tests/Feature/Admin/NetworkExportTest.php`

**Interfaces:**
- Consumes: `NetworkStatsService::perInsurer()` et `::aggregatedByInsurer()`
- Produces:
  - `GET /admin/csv-exports` (`admin.csv-exports`) — la page de choix de période
  - `GET /admin/csv-exports/download` (`admin.csv-exports.download`) — le flux CSV
  - `NetworkCsvExport::rows(Period, Period, ?string $city): iterable`

- [ ] **Step 1: Écrire le test qui échoue**

Couvrir :

- le téléchargement renvoie un `text/csv` avec un nom de fichier daté ;
- l'en-tête porte les colonnes attendues ;
- une ligne par assureur au-dessus du seuil, avec ses agrégats ;
- **un assureur sous le seuil produit une ligne portant la mention « données insuffisantes » et aucun chiffre** — ni délai, ni montant, ni taux. C'est le test qui compte : un export est un fichier qui circule, et c'est le pire endroit pour une fuite ;
- le fichier ne contient **aucun nom d'officine, aucun montant individuel, aucune note** ;
- le filtre de ville et la période sont respectés ;
- une officine reçoit un 403 sur les deux routes ;
- le CSV est encodé en UTF-8 avec BOM, sinon Excel massacre les accents — « L'Africaine des Assurances » doit rester lisible.

- [ ] **Step 2: Décider des colonnes**

Proposition, à confirmer par l'APhaSPB avant diffusion du premier fichier :

```
assureur;officines_declarantes;declarations;delai_moyen_jours;delai_moyen_pondere_jours;
part_sous_seuil_pct;taux_rejet_pct;taux_non_paiement_pct;facture_fcfa;encaisse_fcfa;
encours_fcfa;taux_recouvrement_pct
```

Séparateur point-virgule, décimales à la virgule : c'est ce qu'attend un Excel configuré en français, et le fichier est destiné à des notes de plaidoyer, pas à un pipeline de données.

- [ ] **Step 3 à 5: Implémenter et vérifier**

Le service renvoie un itérable de lignes, le contrôleur les diffuse en `StreamedResponse` — 7 assureurs ne le justifient pas aujourd'hui, mais le coût est nul et cela évite d'avoir à y revenir si l'APhaSPB demande un export par mois et par ville.

- [ ] **Step 6: Commit**

---

## Auto-revue du plan

**Couverture :** les cinq pages d'attente sont traitées, dans l'ordre où elles servent. `ComingSoonController` finit vide de toute entrée : le supprimer, ainsi que ses deux pages Vue et son test, à la dernière tâche — laisser un contrôleur mort serait pire qu'une page d'attente.

**Ce qui reste après cet incrément :** le rappel mensuel par email du 25 (CDC §3.6), qui n'est pas une page mais une commande planifiée, et le plugin Joomla avec ses trois questions ouvertes.

**Cohérence des types :** aucun nouveau DTO. `InsurerChecklist` est réutilisé par la tâche 2, `DataTable` par les tâches 1, 3 et 4. `Fcfa::format` par 1 et 5.

**Risque principal :** le CDC autorise les noms d'officines sur une page et les interdit ailleurs. Un développeur qui lirait « aucun nom d'officine côté admin » dans les tests existants et l'appliquerait à la tâche 3 la croirait fausse ; un autre qui lirait le CDC trop vite pourrait ajouter un compteur de déclarations par officine sur cette liste et ouvrir une brèche. Les deux règles sont écrites en tête de plan pour cette raison, et chaque test dit laquelle il vérifie.

**Risque secondaire :** la route de réglage du seuil. Une route générique « mettre à jour un réglage par clé » serait plus courte à écrire et rendrait le seuil d'anonymat modifiable par une requête forgée. Le plan impose une requête qui n'accepte qu'une seule clé, et un test qui tente l'autre.
