# Journal des pénalités — évolution mensuelle, écrans et exports

Date : 27/09/2026
Statut : spec validée en séance, à relire avant plan d'implémentation

> Suite des lots A et B des pénalités de retard. Ces deux lots calculent une
> pénalité **cumulée** : par assureur, par bande de retard, par période. Ce lot
> la **déplie dans le temps**.

## 1. Demande

> Tenir un journal de pénalité sur chaque mois, et possiblement rajouter aux
> tableaux de bord les courbes ou graphes d'évolution de pénalité pour
> l'ensemble des assureurs, avec la possibilité de filtrer par assureur et
> d'exporter, pour l'officine ainsi que pour l'ensemble du réseau.

On veut savoir si les assureurs s'améliorent ou se dégradent, et pouvoir
présenter un historique mois par mois, à l'officine comme au réseau.

## 2. Décisions prises en séance

| Question | Décision |
|---|---|
| À quel mois rattacher une pénalité ? | **Les deux vues** : le mois calendaire où chaque tranche tombe (« pénalité courue ») **et** le mois déclaré de la facture (« pénalité par mois déclaré »). |
| Figer les chiffres ou les recalculer ? | **Calcul à la volée**, sans table ni tâche planifiée. Une correction de saisie réécrit l'historique, et c'est voulu, puisque le chiffre corrigé est le bon. Même logique que le refus des instantanés au lot B. |
| Où placer les écrans ? | Une **carte « Évolution des pénalités »** sur `/dashboard` et `/admin/trends`, plus une **page « Journal des pénalités »** par espace, qui porte le tableau et les exports. |
| Que somme la série réseau « tous assureurs » ? | **Tous les assureurs sous convention**, y compris ceux masqués par le seuil. C'est cohérent avec `networkSummary()`. Risque accepté : voir §6. |

## 3. Calcul

### 3.1 Noyau : des tranches plutôt qu'une somme

On extrait de `PenaltyCalculator::accruedInDays()` un générateur
`tranches(...)`. Il prend les mêmes arguments et rend, pour chaque tranche
facturée, une paire `[numéro de jour, montant]`. `accruedInDays()` devient la
somme de ce générateur.

- Il n'y a qu'un seul algorithme. Le journal ne peut donc pas diverger des
  totaux déjà affichés par les lots A et B.
- Le `break` sur base nulle reste une optimisation. Il est sans effet sur le
  résultat.
- Pour éviter de payer un générateur par déclaration à 40 000 lignes, on peut
  garder une boucle nue dans `accruedInDays()`. Dans ce cas, un test
  d'équivalence le verrouille (§8). On tranche au plan, sur mesure.

Recopier la boucle dans un service de journal a été écarté : deux copies des
tranches finiraient par diverger. Le SQL est écarté pour les raisons déjà
données au lot B.

### 3.2 Les deux vues

Pour chaque déclaration sous convention, non rejetée et déposée :

- **Pénalité courue** : chaque tranche est rattachée au mois calendaire de son
  jour (`DayNumber` → `AAAA-MM`). Le cumul couru est la somme glissante,
  calculée côté serveur.
- **Pénalité par mois déclaré** : la somme des tranches de la déclaration est
  rattachée à son `period`. Le chiffre d'un mois ouvert continue de croître
  tant qu'il reste de l'encours.

La pénalité reste comptée **depuis le dépôt de facture** (règle des trois
horloges, `pages-pharmacy.md`).

### 3.3 Fenêtre de lecture

La vue « par mois déclaré » garde le filtre habituel : `DeclarationWindow`
pour le réseau, la fenêtre de 12 mois de `PharmacyStatsService` pour
l'officine.

La vue « courue » **ne peut pas** filtrer sur le mois déclaré. Une facture de
janvier encore impayée alimente septembre. On lit donc les déclarations dont
le dépôt précède la fin de la période, sauf celles **soldées avant son début**
(`amount_received >= amount_invoiced AND paid_on < début`). Ce filtre
s'exprime en SQL pur, sans arithmétique de dates. Les tranches hors période
sont calculées puis ignorées au regroupement.

Ce filtre de fenêtre se range à côté de `DeclarationWindow` (nouvelle méthode,
ou classe sœur) pour ne pas le recopier. Le filtre ville reste celui de
`DeclarationWindow`.

### 3.4 Mois en cours

La pénalité courue du mois courant est partielle, calculée jusqu'à
aujourd'hui. Chaque point du journal porte un booléen `current`, rendu
« en cours » à l'écran et `mois_en_cours = oui` dans les exports.

### 3.5 Null et zéro

Les règles des lots A et B s'appliquent :

- un assureur sans convention n'apparaît pas dans le journal. Il est absent
  du filtre et, si une ligne par assureur l'exige, il s'affiche « — » ;
- une convention qui n'a rien produit s'affiche « 0 ».

La décision se prend sur `hasPenaltyClause()`, jamais sur le retour de
`for()`.

## 4. Services

Deux services, un par périmètre, comme pour les exports. Les fusionner
mettrait une règle de confidentialité derrière un `if`.

### 4.1 `App\Services\Pharmacy\PharmacyPenaltyLedger`

- Une officine, Eloquent avec `with(['insurer', 'payments'])`. Une centaine de
  lignes, une boucle suffit. On ne charge jamais `private_note`, qui est
  inutile ici.
- `forPharmacy(Pharmacy, Period $from, Period $to, ?int $insurerId): PenaltyLedger`

### 4.2 `App\Services\Network\NetworkPenaltyLedger`

- Même stratégie que `InsurerPenaltyAggregates` : query builder, curseur,
  numéros de jour entiers, `$today` hissé, versements **joints** (jamais de
  `whereIn` sur des identifiants de déclaration), restriction aux assureurs
  sous convention.
- **Nombre fixe de requêtes quel que soit le volume**, épinglé par un test.
- `forInsurers(list<int> $authorizedIds, Period $from, Period $to, ?string $city): PenaltyLedger`
  pour les séries par assureur, qui ne reçoivent que les assureurs autorisés
  par `perInsurer()`.
- La série totale se calcule sur **tous** les assureurs sous convention
  (décision §2), dans la même passe si possible, pour ne pas lire deux fois.
- Chaque point par assureur **expose** son nombre d'officines contributrices
  (`contributingPharmacies`). Le service ne décide pas de la rétention :
  c'est l'appelant qui détient `SettingsRepository` qui retient (règle
  « seuil à chaque granularité », `network.md`).
  - Vue courue : officines dont une tranche tombe dans ce mois.
  - Vue mois déclaré : officines déclarantes de ce mois.

### 4.3 Données

Des DTO `readonly` dans `app/Data/` :

- `PenaltyLedger` : les séries par assureur, la série totale, la liste des
  mois de la période.
- `PenaltyLedgerPoint` : `month` (`AAAA-MM`), `accrued`, `accruedCumulative`,
  `byDeclaredMonth`, `current`, et `contributingPharmacies` pour la variante
  réseau.

Les noms exacts sont fixés au plan.

## 5. Écrans

### 5.1 Carte « Évolution des pénalités »

Sur `pharmacy/Dashboard.vue` et `admin/Trends.vue`.

- Prop `penaltyTrend` **deferred**, avec `ChartSkeleton` en attendant.
- `ChartToolbar` avec :
  - la bascule **Courue par mois / Par mois déclaré** ;
  - `FilterSelect` assureur (« Tous les assureurs » par défaut, uniquement les
    assureurs sous convention) ;
  - le choix ligne / barres ;
  - l'export PNG existant (`exportChartToPng`).
- La bascule et le filtre passent par `useQueryState` et un **partial reload**
  `only: ['penaltyTrend']`.
- « Tous » affiche la série totale plus une série par assureur. Au-delà de
  trois, on reprend le trait pointillé de `DelayTrendChart`. Un seul assureur
  affiche sa série et son cumul.
- Côté réseau, un mois retenu forme un **trou** dans la courbe, jamais un
  zéro, avec une note sous le graphique.
- État vide : « Aucun assureur sous convention de pénalité ».
- Période : 12 mois côté officine, comme le reste de la page. `period` / `city`
  de `/trends` côté réseau.

Nouveau composant : `resources/js/components/aphaspb/charts/PenaltyTrendChart.vue`
(unovis). Chaque prop `Vis*` est vérifiée contre `config.d.ts` (règle
`charts.md`, vue-tsc ne la contrôle pas).

### 5.2 Page « Journal des pénalités »

| Espace | Route | Nom | Garde |
|---|---|---|---|
| Officine | `GET /pharmacy/penalties` | `pharmacy.penalty-ledger` | `can:declare-payments`, `onboarded` |
| Officine | `GET /pharmacy/penalties/download` | `pharmacy.penalty-ledger.download` | idem |
| Réseau | `GET /admin/penalties` | `admin.penalty-ledger` | `can:manage-network` |
| Réseau | `GET /admin/penalties/download` | `admin.penalty-ledger.download` | idem |

Les noms évitent un dernier segment qui ferait collision dans Wayfinder
(cf. `data-exports`, `show`).

Chaque espace a une entrée de navigation dans `ConsoleNavigation`.

Contenu de la page :

- Filtres : période (`StatsPeriod`), assureur, et ville côté réseau.
- En tête, `PenaltyTrendChart`, le même composant que la carte.
- Dessous, `PenaltyLedgerTable.vue` : une ligne par mois, avec les colonnes
  Mois · Pénalité courue · Cumul couru · Pénalité des factures du mois.
  - Avec « Tous les assureurs », une ligne de total est ajoutée, et un clic sur
    un mois déplie le détail par assureur.
  - Le mois courant porte la mention « en cours ».
  - Côté réseau, une ligne retenue est **conservée et vidée** avec la mention
    « sous le seuil ». Une ligne absente se lirait « rien couru ».
- Montants via `formatAmount()`, jamais `formatFcfa()` nu.
- Trois boutons d'export (CSV / XLSX / PDF) portant les filtres courants.

Contraintes front : `<script setup>`, `<style scoped>`, jetons de couleur
uniquement (la garde des littéraux couvre `resources/js`).

La colonne « Encours pénalisable », évoquée en séance, est **hors lot** : elle
n'est ni une pénalité ni mensuelle, et `/dashboard` la montre déjà.

## 6. Anonymat réseau

- **Séries par assureur** : uniquement les assureurs autorisés par
  `NetworkStatsService::perInsurer()` sur la période. Chaque mois est
  **réévalué** contre le seuil (`contributingPharmacies`) et retenu s'il est
  en dessous.
- **Série totale** : tous les assureurs sous convention, sans seuil, comme
  `networkSummary()`.
  - **Risque assumé en séance** : quand un seul assureur est masqué, sa
    pénalité se déduit par différence (total − somme des séries visibles). Si
    cet assureur n'a qu'une officine contributrice ce mois-là, c'est la
    pénalité de cette officine.
  - Décision du 27/09/2026, à reconsidérer si le risque devient réel. Un test
    verrouille le comportement, pour qu'un changement soit délibéré.
- **Filtre sur un assureur sous le seuil** : la « série totale » devient les
  chiffres de cet assureur. Elle est donc retenue, à l'écran comme à l'export.
  C'est la règle du résumé réseau (`NetworkPdfExport::summary()`). La
  condition porte sur la rétention, pas sur des lignes vides : un assureur
  sans rien couru rend des zéros.
- Le compte « N assureurs masqués sous le seuil » s'affiche comme sur les
  autres écrans réseau.

## 7. Exports

### 7.1 Sources de lignes

Deux classes distinctes, avec les rendus partagés (`CsvRenderer`,
`XlsxWriter`) :

- `App\Services\Pharmacy\PharmacyPenaltyLedgerRows` : l'officine est nommée
  dans l'en-tête, rien n'est retenu, pas de note privée (le journal ne porte
  que des montants).
- `App\Services\Network\NetworkPenaltyLedgerRows` : aucune officine nommée.
  Les lignes retenues sont conservées et vidées, avec l'explication dans la
  colonne `retenu`.

### 7.2 Colonnes

Déclarées dans une constante `COLUMNS`, référencées par leur nom dans les
tests :

| Colonne | Officine | Réseau |
|---|---|---|
| `mois` | ✓ | ✓ |
| `assureur` | ✓ | ✓ |
| `penalite_courue` | ✓ | ✓ |
| `cumul_couru` | ✓ | ✓ |
| `penalite_mois_declare` | ✓ | ✓ |
| `mois_en_cours` | ✓ | ✓ |
| `retenu` | — | ✓ |

- Une ligne par couple (mois, assureur), puis une ligne « Tous assureurs » par
  mois.
- Le filtre assureur réduit le fichier à cet assureur.

### 7.3 Contrôleurs et formats

- `App\Http\Controllers\Pharmacy\PenaltyLedgerController` et
  `App\Http\Controllers\Admin\PenaltyLedgerController`, chacun avec `index` et
  `download`.
- Un seul `match` sur `format` dans `download` : le fichier ne peut pas
  couvrir une période ou un assureur différents de l'écran.
- L'officine est **toujours** lue depuis la session (`currentPharmacy`),
  jamais depuis la requête.
- CSV : BOM posé par le contrôleur, `fputcsv(..., ';', '"', '')`.
- XLSX : feuille « Journal des pénalités ».
- PDF : dompdf, vues `resources/views/exports/penalty-ledger-pharmacy.blade.php`
  et `penalty-ledger-network.blade.php`.
  - Tableaux uniquement (ni flex ni grid).
  - `position: fixed` pour l'en-tête et le pied.
  - `page-break-inside: avoid` sur les lignes.
  - `counter(page)` pour la numérotation.
- XLSX et PDF : `tempnam()` sans extension ajoutée, puis `deleteFileAfterSend()`.
- Noms de fichier : `{slug}-penalites-AAAA-MM.{ext}` pour l'officine,
  `reseau-penalites-AAAA-MM.{ext}` pour le réseau.

## 8. Tests (Pest)

**Noyau**

- Sur des décors variés (acompte, solde tardif, jamais réglé, versement le jour
  d'une tranche), la somme des `tranches()` vaut `accruedInDays()`.
- Une tranche tombe dans le bon mois calendaire, en fin de mois et en changement
  d'année.

**Ledger officine**

- Décor où les deux vues divergent : une facture de mars impayée court en juin,
  juillet et août, et sa pénalité est rattachée à mars.
- Une convention sans retard rend 0. Un assureur sans convention est absent.
- Une déclaration rejetée ne court pas.
- Le mois courant est marqué `current`.
- Une facture d'une autre officine n'entre jamais.

**Ledger réseau**

- Nombre de requêtes constant (épinglé).
- Une facture déclarée avant la période qui court encore pendant la période
  est incluse. Une facture soldée avant la période est exclue.
- Un mois sous le seuil est retenu, avec un **décor discriminant** : le total de
  période de l'assureur ne coïncide pas avec la valeur retenue.
- La série totale inclut les assureurs masqués (décision §2 verrouillée).
- La série totale est retenue quand le filtre vise un assureur sous le seuil.
- Les séries par assureur ne reçoivent que les assureurs autorisés (espion sur
  le service, comme pour `InsurerPenaltyAggregates`).

**HTTP**

- `/pharmacy/penalties` ne montre que l'officine de la session. Un paramètre
  forgé ne change rien.
- `/admin/penalties` renvoie 403 sans `manage-network`.
- Les trois formats sortent avec le bon `Content-Type` et le bon nom de
  fichier, officine et réseau.
- Le PDF réseau se rend avec un mois retenu, sans 500 (test de rendu, pas
  seulement de données).
- `penaltyTrend` est une prop deferred sur `/dashboard` et `/admin/trends`, et
  un partial reload la restreint au filtre demandé.

Vérification finale : `composer ci:check` en entier.

## 9. Hors lot

- Clôture mensuelle figée ou pièce opposable datée (écartée en séance).
- Colonne « Encours pénalisable » dans le journal.
- Ajout du journal dans les exports PDF officine et réseau existants. Il vit
  sur sa propre page.
- Notifications sur l'évolution des pénalités.
