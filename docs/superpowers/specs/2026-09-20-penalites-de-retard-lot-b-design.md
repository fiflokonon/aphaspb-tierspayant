# Pénalités de retard — lot B : échelle réseau et pages par assureur

> Suite du lot A, spécifié le 19/09/2026 et livré. Celui-ci reprend les trois
> points que le lot A s'était explicitement interdits (§9 de sa spec) et y
> ajoute une page par assureur dans les deux PDF.

## 1. Problème

Le lot A a livré le calcul de pénalité, la mise en évidence des retards sur le
tableau de bord officine, un écran par assureur et trois colonnes dans les
exports officine. Il a laissé trois choses de côté, et pour une seule raison :
**le calcul ne passe pas à l'échelle du réseau**.

Sa spec le disait sans détour : « `PenaltyCalculator` ne se somme pas en SQL. À
l'échelle du réseau, ça ne passera pas tel quel. » Le lot B commence par
vérifier cette affirmation au lieu de la croire.

Il faut aussi corriger une lecture. La demande d'origine disait :

```
* À rajouter dans les données exportées par assureur
  - délai le plus long
  - pénalité potentielle (agrégation)
  - Prévoir une page par assureur
```

Les trois puces portent sur **le fichier exporté**. Le lot A a lu la troisième
comme un écran web et a construit `pharmacy/insurers/{insurer}`. L'écran est
conservé — il s'avère utile — mais la demande initiale visait bien **une page
par assureur dans le PDF**, et elle s'applique **aux deux** exports.

## 2. Ce que la mesure a dit

Tout le design découle de six mesures prises avant d'écrire une ligne, sur la
base de développement (911 déclarations, 1 019 versements) puis sur un jeu
synthétique de 40 000 déclarations — la cible retenue de ~400 officines, tout
le privé béninois, sur une fenêtre de 12 mois avec 8 assureurs.

| Chemin | Temps | Mémoire |
|---|---|---|
| 911 décl., Eloquent hydraté | 46 ms chargement + 107 ms calcul | 50 Mo crête |
| 911 décl., query builder brut | 8 ms + 14 ms | plate |
| **40 000 décl., objets `CarbonImmutable`** | **4 323 ms** | **273 Mo de données, 326 Mo crête** |
| **40 000 décl., numéros de jour entiers** | **42 ms** | **34 Mo de données, 78 Mo crête** |
| 160 000 conversions, `strtotime` | 99 ms | — |
| 160 000 conversions, `CarbonImmutable::parse` | 380 ms | — |

**Le goulot n'est pas l'arithmétique, c'est l'allocation d'objets `Carbon`.**
Cent fois plus rapide et huit fois plus léger en travaillant sur des entiers.
Budget complet pour 40 000 déclarations : ~350 ms de chargement + ~100 ms de
conversion + ~42 ms de calcul, soit **environ une demi-seconde**.

### Stratégies écartées

| Option | Raison du rejet |
|---|---|
| Table d'instantanés rafraîchie la nuit | Crée **un second chiffre** : l'export réseau annoncerait une pénalité d'hier pendant que l'écran officine en affiche une d'aujourd'hui. Sur un montant destiné à être opposé à un assureur, deux versions est exactement ce qu'il ne faut pas. Plus une table, une commande planifiée, et un mode de panne neuf — le job qui n'a pas tourné. |
| Cache court sur le résultat | Mêmes deux chiffres, en moins prévisible : selon l'instant, l'export et l'écran s'accordent ou non. |
| Tranches calculées en SQL | Demanderait une CTE récursive ou une table de nombres, et de l'arithmétique de dates que ce projet évite pour ne pas se lier à un moteur (SQLite en test, MySQL en production). |

La porte reste ouverte : le cœur en entiers est précisément ce qui rendrait un
instantané trivial à remplir, si le réseau dépassait un jour le millier
d'officines.

## 3. Décisions tranchées

| Question | Décision |
|---|---|
| Stratégie d'échelle | **Calcul à la volée**, cœur en numéros de jour entiers. Aucune table, aucun cache, **un seul chiffre**. |
| Délai le plus long au niveau réseau | **Entièrement en SQL** — deux agrégats, aucune boucle PHP |
| Anonymat | **Structurel** : l'agrégateur reçoit les identifiants déjà blanchis, il n'a pas la liberté de contourner le seuil |
| Colonnes d'export réseau | **Quatre**, pas deux : le délai le plus long, la pénalité, **et la clause qui l'a produite** |
| Page par assureur | Dans **les deux** PDF, réseau et officine |
| Détail global du PDF officine | **Conservé** : les pages par assureur s'y ajoutent, elles ne le remplacent pas |
| Assureur sous le seuil | **Aucune page** dans le PDF réseau |
| Écran web du lot A | **Conservé** ; le lot B n'y touche pas |

## 4. Le cœur en jours entiers

`PenaltyCalculator` se dédouble **sans changer de comportement** :

```php
// Le cœur. Aucun objet date, que des entiers.
public function accruedInDays(
    int $amountInvoiced, int $amountReceived,
    int $depositedDay, ?int $paidDay,
    int $triggerDays, int $rateBp,
    array $payments,          // list<array{0: int montant, 1: int jour}>
): int

// L'API actuelle, devenue un adaptateur : convertit une fois, délègue.
public function accrued(..., CarbonImmutable $depositedOn, ...): int
```

`for()` et `total()` ne bougent pas.

**Les 17 tests de `PenaltyCalculatorTest` sont le contrat de non-régression**
— 27 avec ceux de `LongestDelayTest`, que le refactor ne devrait pas approcher.
Ils doivent tous rester verts **sans une seule modification**. S'il faut en
toucher un, le refactor a changé le comportement et pas seulement la
représentation : c'est un échec, pas un ajustement.

### `App\Support\DayNumber`

```php
DayNumber::fromDate('2026-05-01')   // 20574
DayNumber::fromCarbon($immutable)   // = fromDate($immutable->format('Y-m-d'))
DayNumber::today()                  // = fromCarbon(CarbonImmutable::now())
```

Trois précautions, chacune verrouillée par un test :

- **`fromCarbon()` passe par `format('Y-m-d')`**, jamais par l'horodatage.
  `app.timezone` vaut UTC aujourd'hui, mais s'il changeait, un
  `getTimestamp() / 86400` décalerait toutes les dates d'un jour une partie de
  l'année, silencieusement. La chaîne rend la conversion indépendante du fuseau.
- **`today()` dérive de `CarbonImmutable::now()`**, pas de `time()` : sinon
  `travelTo()` cesserait de piloter les tests, et toute la suite « la pénalité
  croît avec le temps » deviendrait fausse sans rougir.
- **Un test d'équivalence** compare, sur plusieurs centaines de paires de dates
  incluant des 29 février et des écarts négatifs,
  `DayNumber::fromDate($b) - DayNumber::fromDate($a)` au `diffInDays` de Carbon.
  C'est la propriété dont dépend tout le reste.

### Ce que ce refactor ne touche pas

`OverduePaymentsService` et `InsurerRelationshipReport` traitent des centaines
de lignes, pas des dizaines de milliers. Ils continuent d'appeler `accrued()`,
qui reste exact. Leur faire passer le chemin entier serait du bruit pour un
gain invisible.

## 5. L'agrégation réseau

### Le délai le plus long bascule en SQL

La définition du lot A prend, par déclaration, l'âge de l'encours si elle est
ouverte, sinon son `delay_days`, et ignore les rejetées. Regroupée par
assureur, elle se scinde en deux agrégats purs :

```sql
-- les mois soldés : le pire délai
MAX(CASE WHEN status != 'rejected' AND amount_invoiced <= amount_received
         THEN delay_days END) as worst_settled_delay
-- les mois encore dus : la plus vieille facture
MIN(CASE WHEN status != 'rejected' AND amount_invoiced > amount_received
              AND invoice_deposited_on IS NOT NULL
         THEN invoice_deposited_on END) as oldest_open_deposit
```

L'âge se déduit du second par une soustraction en PHP (`aujourd'hui − ce jour`),
et le résultat est le maximum des deux termes non nuls.

**L'équivalence est exacte** — prendre le maximum dans chaque groupe puis entre
les groupes donne le maximum global — et elle est verrouillée par un test qui
confronte, sur un jeu mêlant mois soldés, partiels, impayés et rejetés, ce que
rend l'agrégat SQL et ce que rend `LongestDelay` déclaration par déclaration.

### La pénalité garde une boucle, mais restreinte

Ses tranches sont séquentielles ; elle ne se somme pas en SQL. Elle se
restreint en revanche aux seuls assureurs sous convention :

```php
->whereNotNull('insurers.penalty_trigger_days')
->whereNotNull('insurers.penalty_rate_bp')
```

Si deux assureurs sur huit ont une clause, on charge un quart des déclarations.

### `App\Services\Network\InsurerPenaltyAggregates`

```php
public function forInsurers(array $insurerIds, Period $from, Period $to, ?string $city = null): array
// → insurerId => App\Data\InsurerPenaltyFigures{ penalty: ?int, longestDelayDays: ?int }
```

**L'anonymat est structurel, pas conditionnel.** La méthode reçoit **les
identifiants déjà blanchis** par `NetworkStatsService::perInsurer()`. Un
assureur sous le seuil n'est pas filtré à la sortie : il n'entre jamais dans la
requête. C'est plus sûr qu'un `if` de retenue de plus, et ça évite de calculer
pour rien.

**Pourquoi une classe à part** et non une méthode de `NetworkStatsService`,
dont l'en-tête dit pourtant « concentrer chaque agrégat ici est ce qui rend la
règle d'anonymat vérifiable » :

1. `perInsurer()` est appelée par `maskedInsurerCount()`, que
   `ConsoleNavigation` invoque **sur chaque page admin**. Y greffer une boucle
   PHP ferait payer le calcul de pénalité à l'écran des pharmacies inscrites,
   qui n'en a que faire.
2. Tout le reste de `NetworkStatsService` est du SQL. Une boucle sur des
   dizaines de milliers de lignes n'a pas la même nature, et les mélanger
   brouillerait les deux.

La règle d'anonymat n'est pas dupliquée pour autant : elle reste décidée une
seule fois, dans `perInsurer()`, et cette classe n'a pas la liberté de la
contourner.

**`null` et `0` se décident sur la clause, pas sur les lignes.** La requête de
pénalité ne voit que les assureurs sous convention ; un assureur sans clause n'y
produit aucune ligne, et il faut pourtant rendre `null` pour lui et `0` pour un
assureur sous convention dont rien n'a encore couru. `forInsurers()` lit donc
les clauses des assureurs demandés — une lecture de plus, sur huit lignes — et
initialise à `0` ceux qui en portent une, à `null` les autres. C'est le même
invariant qu'au lot A : un tiret dit « pas de convention », un zéro dit « une
convention, rien à réclamer ».

**Deux requêtes sur les déclarations, plus une sur les assureurs**, quel que
soit le nombre de lignes : les agrégats de délai
(SQL pur, groupés par assureur), puis les versements des déclarations
concernées — **jointes**, jamais un `whereIn` sur 40 000 identifiants. Seule la
boucle de pénalité parcourt les déclarations, et elle le fait en `cursor()` :
les agrégats de délai, eux, ne rendent qu'une ligne par assureur.

**Lecture en query builder, jamais en Eloquent** : `private_note` ne doit pas
franchir un chemin réseau, et c'est la règle qui vaut pour tout ce dossier.

## 6. Les colonnes de l'export réseau

`NetworkExportRows::COLUMNS` gagne quatre colonnes :

| Colonne | Position |
|---|---|
| `delai_le_plus_long_jours` | après `delai_moyen_pondere_jours` |
| `delai_declenchement_penalite_jours` | après `delai_standard_jours` |
| `taux_penalite_pct` | après la précédente |
| `penalite_potentielle_fcfa` | à la fin, après `taux_recouvrement_pct` |

Quatre et non deux : **un montant de pénalité sans le taux qui l'a produit est
invérifiable dans un tableur**, et l'export réseau finit dans un courrier
adressé à l'assureur, où le chiffre doit pouvoir être refait. Même raisonnement
qu'au lot A pour l'export officine.

Un assureur sous le seuil garde sa ligne de retenue, désormais large de quatre
cases de plus, toutes vides : `NetworkExportRows::withheld()` remplit déjà par
`array_fill`, donc rien à faire.

Les tests référencent chaque colonne par son nom via `array_search()` sur
`COLUMNS`, jamais par son index : l'insertion ne casse aucune assertion.

## 7. Une page par assureur dans les deux PDF

Même forme des deux côtés, deux sources différentes. Chaque page s'ouvre sur
`page-break-before: always`, porte le nom de l'assureur en titre, puis trois
blocs :

1. **La convention** — délai de remboursement, déclenchement de la pénalité,
   taux ; ou « aucune clause de pénalité enregistrée ». Sans elle, la pénalité
   imprimée plus bas est un nombre sans provenance.
2. **Les indicateurs** — ceux de la table récapitulative, respirés en blocs
   lisibles plutôt qu'en colonnes de 6,8 px, plus le délai le plus long et la
   pénalité potentielle.
3. **Le détail mois par mois de cet assureur seul.**

### Côté réseau

Le troisième bloc demande un agrégat neuf : une ligne par assureur et par mois
(déclarations, facturé, encaissé, reste dû, délai moyen), en une requête groupée
`insurer_id, period_year, period_month`, **restreinte aux assureurs déjà
blanchis** — la même restriction structurelle qu'en §5.

`delayTrend()` fait déjà ce groupement pour la seule moyenne de délai. Je ne
l'étends pas : il alimente un graphique, et y ajouter quatre colonnes ferait
payer ce poids à l'écran des tendances. Une méthode voisine, un test chacun.

**Seuls les assureurs blanchis ont une page.** Ceux retenus gardent leur
mention dans la liste existante, sans page — une page par assureur masqué qui
ne contiendrait que des tirets dirait au lecteur quels assureurs sont peu
déclarés, ce qui est précisément l'information que le seuil protège.

### Côté officine

Le bloc est gratuit : `PharmacyExportRows::declarations()` a déjà chargé toutes
les déclarations avec leurs versements. Un `groupBy('insurer_id')` en mémoire
suffit, et chaque ligne de mois porte ce que la table de détail globale affiche
déjà, pénalité comprise.

**Le détail global ne bouge pas** : la table chronologique tous assureurs
confondus reste à sa place, et les pages par assureur viennent après. Chaque
déclaration de l'officine est donc imprimée deux fois, sous deux ordres de
lecture — c'est le choix retenu, pas un oubli.

### Contraintes dompdf

Déjà documentées et toujours valables : ni flexbox ni grid — les blocs
d'indicateurs sont des `<table>` —, `page-break-inside: avoid` sur les lignes,
en-tête et pied répétés en `position: fixed`. Le compteur `content: counter(page)`
continue de fonctionner à travers les sauts.

## 8. Tests

**`DayNumber`**

- équivalence avec `diffInDays` de Carbon sur plusieurs centaines de paires,
  dont des 29 février et des écarts négatifs ;
- `today()` suit `travelTo()` ;
- `fromCarbon()` ne dépend pas de `app.timezone` — contrôlé en basculant la
  configuration sur un fuseau décalé et en vérifiant que le numéro ne bouge pas.

**`PenaltyCalculator`**

- les 17 tests de `PenaltyCalculatorTest`, **inchangés**, tous verts ;
- `accruedInDays()` et `accrued()` rendent le même entier sur le même cas.

**`InsurerPenaltyAggregates`**

- équivalence du délai le plus long avec `LongestDelay` sur un jeu mêlant mois
  soldés, partiels, impayés et rejetés ;
- un assureur sans clause rend `penalty = null`, un assureur sous convention
  dont rien n'a couru rend `0` ;
- un assureur absent de la liste blanchie n'apparaît dans aucune sortie ;
- **compte de requêtes constant** entre 20 et ~2 000 déclarations : c'est la
  propriété déterministe, et celle qui casse en premier si quelqu'un remet un
  `whereIn` sur les identifiants. Pas d'assertion de durée ni de mémoire —
  trop instables en CI pour valoir un test rouge par semaine.

**Export réseau**

- les quatre colonnes, référencées par leur nom ;
- la ligne de retenue porte bien quatre cases vides de plus ;
- un assureur sous le seuil n'a ni chiffre ni page, dans les trois formats.

**PDF**

- une page par assureur blanchi, et pas une de plus ;
- aucune page pour un assureur sous le seuil, et son nom absent de tout le
  document ;
- la pénalité d'une page égale celle de la table récapitulative ;
- côté officine, la somme des pages par assureur égale la table de synthèse.

## 9. Ce que ce lot ne fait pas

- **Aucun écran web neuf.** L'espace admin garde ses cinq écrans ; la page par
  assureur est un objet de papier.
- **`OverduePaymentsService` et `InsurerRelationshipReport` ne changent pas de
  chemin** : ils restent sur l'API Carbon.
- **Pas de table d'instantanés ni de cache** — écartés en §2, et la porte reste
  ouverte.
- **Pas de mention de la pénalité dans les digests e-mail** ; `OverdueLine`
  la porte depuis le lot A mais aucun e-mail ne la rend.
- **`ConsoleNavigation::chaseNotice()` et son horloge à 60 jours** restent en
  l'état, comme au lot A.

## 10. Ordre d'implémentation

1. `DayNumber` et ses tests — aucune dépendance.
2. `PenaltyCalculator::accruedInDays()` + `accrued()` adaptateur ; les 27 tests
   du lot A doivent passer sans retouche.
3. `InsurerPenaltyFigures` + `InsurerPenaltyAggregates` (délai en SQL, pénalité
   en boucle restreinte).
4. Les quatre colonnes de `NetworkExportRows`.
5. L'agrégat mensuel par assureur pour le PDF réseau.
6. Les pages par assureur dans `resources/views/exports/network.blade.php`.
7. Les pages par assureur dans `resources/views/exports/pharmacy.blade.php`.
8. `composer ci:check` en entier, puis contrôle négatif sur chaque garde neuve.
