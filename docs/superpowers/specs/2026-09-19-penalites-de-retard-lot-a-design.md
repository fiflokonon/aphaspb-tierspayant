# Pénalités de retard — lot A : socle et espace officine

> Lot A de la demande du 19/09/2026. Le **lot B** — colonnes agrégées dans
> l'export réseau et page admin par assureur — fera l'objet de sa propre spec
> et n'est pas couvert ici. Ce document dit explicitement, en §9, ce qui lui
> est renvoyé.

## 1. Problème

L'application mesure les retards de paiement et sait déjà dire quelle facture a
dépassé le délai convenu avec son assureur : `OverduePaymentsService` existe,
il est testé, et il alimente le digest e-mail quotidien. Mais rien de tout cela
n'apparaît sur le tableau de bord de l'officine, qui reste centré sur le
recouvrement global et l'ancienneté de l'encours.

Surtout, le réseau n'a aucun levier chiffré. Une convention de tiers payant
prévoit couramment une pénalité au-delà d'un certain délai, et ce délai n'est
pas celui du remboursement standard : c'est un second seuil, plus tardif, à
partir duquel l'assureur doit majorer sa dette. L'application ne connaît ni ce
seuil, ni le taux, ni le montant qui en découle.

Trois manques, donc :

1. le tableau de bord officine ne met pas en évidence les impayés ni les retards ;
2. `insurers` ne porte pas de délai de déclenchement de pénalité, ni de taux ;
3. aucun écran ni aucun fichier ne chiffre la pénalité.

## 2. Décisions tranchées

Ces sept points ont été arbitrés au cours du cadrage. Les rouvrir demande de
reprendre le calcul, pas seulement l'affichage.

| Question | Décision |
|---|---|
| Formule | Taux en pourcentage **porté par l'assureur**, appliqué au reste dû, par tranche de 30 jours, **non composé** |
| Base | **Dégressive** : chaque versement réduit la base des tranches suivantes |
| Mois soldé | La pénalité déjà courue **reste acquise** ; l'horloge s'arrête au dernier versement |
| Clause | **Optionnelle** : `NULL` = pas de pénalité avec cet assureur, aucun chiffre calculé |
| Délai le plus long | `max(pire délai d'un mois réglé, âge de la plus vieille facture impayée)` |
| Bandeau du tableau de bord | Nomme **la pire ligne**, n'invente aucun seuil d'ancienneté |
| Agrégation par assureur dans l'export officine | **PDF uniquement** ; le CSV reste une ligne par déclaration |

## 3. Modèle de données

Deux colonnes nullables sur `insurers`, ajoutées par une migration unique :

```php
Schema::table('insurers', function (Blueprint $table) {
    $table->unsignedSmallInteger('penalty_trigger_days')->nullable()->after('standard_delay_days');
    $table->unsignedSmallInteger('penalty_rate_bp')->nullable()->after('penalty_trigger_days');
});
```

| Colonne | Sens |
|---|---|
| `penalty_trigger_days` | Jour à partir duquel la pénalité mord, compté depuis `invoice_deposited_on`. **Indépendant de `standard_delay_days`** : une convention peut prévoir un remboursement à 45 jours et une pénalité à 90. |
| `penalty_rate_bp` | Taux par tranche de 30 jours, en points de base. `250` = 2,50 %. |

**Aucune valeur par défaut, aucun remplissage.** Tous les assureurs existants
sortent de la migration avec deux `NULL`, donc sans pénalité : aucun chiffre ne
bouge le jour du déploiement. C'est la même propriété que la migration de
`standard_delay_days`, qui avait pris soin de semer chaque ligne avec l'ancien
seuil global.

### Pourquoi les points de base et non `decimal(5,2)`

Une pénalité est de l'argent, et tout montant de ce projet est un entier FCFA.
`intdiv($base * $rate_bp, 10000)` reste exact quel que soit le montant ;
`$base * 2.5 / 100` passe par un flottant sur des nombres à neuf chiffres. Le
plafond de 100 % par tranche vaut 10 000 points de base, qui tient largement
dans un `unsignedSmallInteger`.

Le coût est une conversion aux deux bords : l'écran admin saisit et affiche
« 2,50 % », le contrôleur convertit. C'est exactement le contrat que respecte
déjà `AmountField` pour les montants formatés.

### Saisie : un seul formulaire pour les deux champs

`SaveInsurerRequest` documente que l'écran d'administration édite un champ à la
fois, chacun dans son petit formulaire, et qu'un champ absent vaut « inchangé ».
Les deux champs de pénalité font **exception et voyagent ensemble** : ils n'ont
aucun sens l'un sans l'autre, et c'est ce qui permet à `required_with` de tenir
dans les deux directions.

```php
'penalty_trigger_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365', 'required_with:penalty_rate_percent'],
'penalty_rate_percent' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:100', 'required_with:penalty_trigger_days'],
```

Soumettre les deux champs vides efface la clause. Le contrôleur convertit :
`penalty_rate_bp = (int) round($percent * 100)`.

**Aucune contrainte entre `penalty_trigger_days` et `standard_delay_days`.**
Un déclenchement antérieur au délai standard est incohérent mais reste
possible dans une convention mal rédigée, et refuser la saisie empêcherait de
consigner ce qui est signé. L'écran affiche les deux valeurs côte à côte pour
que l'incohérence saute aux yeux.

## 4. `PenaltyCalculator`

Classe nouvelle, `app/Services/Declarations/PenaltyCalculator.php`. Calcul pur,
sans effet de bord, sans colonne de cache.

```
penalty(declaration) :

  si penalty_rate_bp est null            → null
  si penalty_trigger_days est null       → null
  si invoice_deposited_on est null       → null
  si status = rejected                   → null

  fin     = amount_received >= amount_invoiced ? paid_on : aujourd'hui
  tranche = invoice_deposited_on + penalty_trigger_days
  total   = 0

  tant que tranche <= fin :
      base = amount_invoiced − somme des versements dont paid_on <= tranche
      si base <= 0  → arrêt définitif
      total   += intdiv(base × penalty_rate_bp, 10000)
      tranche += 30 jours

  retourner total
```

Quatre points méritent d'être dits une fois pour toutes :

- **La première tranche tombe le jour du déclenchement**, pas trente jours après.
  « Appliquer la pénalité dès que le délai est atteint » se lit littéralement.
- **Le versement du jour compte avant la tranche.** Comparaison `<=` : de
  l'argent arrivé le jour où la pénalité mord allège cette tranche-là.
- **L'arrêt sur base nulle est définitif.** Les versements ne font que
  s'ajouter, donc une base retombée à zéro ne peut plus remonter.
- **Un mois rejeté ne porte pas de pénalité**, cohérent avec
  `OverduePaymentsService::overdueQuery()`, qui exclut déjà `rejected` du retard.

### Pourquoi aucune colonne de cache

C'est la rupture assumée avec `delay_days`, et elle doit être comprise pour ne
pas être « corrigée » plus tard.

`delay_days` est stocké parce qu'il ne dépend que des données saisies : deux
dates entrent, un entier sort, et le hook `saving` le régénère à chaque
enregistrement. La pénalité, elle, **croît toute seule**. Un mois jamais réglé
voit la sienne augmenter tous les trente jours sans qu'aucune écriture ne
survienne. Une colonne exigerait une tâche quotidienne repassant sur toutes les
déclarations ouvertes, et le chiffre serait faux entre deux passages — c'est-à-dire
la plupart du temps. Le calcul à la lecture est toujours juste.

### Ce que ça coûte, et où ça fera mal

Ce calcul **ne se somme pas en SQL**. Il lui faut les versements de chaque
déclaration, donc un `with('payments')` et une boucle PHP.

Pour une officine sur douze mois — de l'ordre de six assureurs par douze mois,
soit une centaine de lignes au plus — c'est **deux requêtes** (les déclarations,
puis leurs versements en un `whereIn`) et une boucle courte. Négligeable, et
c'est tout ce que couvre ce lot.

**Pour le réseau entier, ça ne passera pas tel quel.** `PenaltyCalculator` est
donc conçu pour accepter aussi bien une déclaration seule qu'une collection
préchargée, et le lot B tranchera sa propre stratégie d'agrégation. Le signaler
ici évite de le redécouvrir au milieu du lot B.

## 5. Tableau de bord officine

### Source des données

`OverduePaymentsService::forPharmacy()` renvoie déjà les bonnes lignes, triées
de la plus ancienne à la plus récente. Il est réutilisé tel quel.

`App\Data\OverdueLine` gagne une propriété `penalty: ?int`. Le service charge
les versements des déclarations concernées en **une requête**
(`whereIn('declaration_id', …)`) et les passe au calculateur. Le digest e-mail,
qui consomme le même service, hérite du champ sans le rendre — l'intégrer à
l'e-mail n'est pas dans ce lot.

### Le bandeau

Au-dessus du titre de l'écran, rendu **seulement s'il existe au moins une
facture en retard**.

```
⚠  3 factures en retard · 4,2 M FCFA
   la plus ancienne : Mutuelle, mai 2026, +87 j · pénalité courue 210 000 FCFA
```

**Il nomme la pire ligne plutôt que de compter au-delà d'un seuil.** Une
formulation du type « dont 1 au-delà de 90 jours » introduirait une **troisième
horloge** sur le même écran : `ConsoleNavigation::chaseNotice()` compte déjà
« au-delà de 60 jours » depuis la **fin du mois déclaré**, tandis que
`OverdueLine::ageDays` compte depuis le **dépôt de facture**. Deux seuils
voisins mesurés depuis deux dates différentes se contrediraient visiblement.
Nommer la pire ligne n'invente aucun seuil, et c'est de toute façon le chiffre
qui pèse en relance.

**Le montant de pénalité du bandeau ne couvre que les factures en retard**, pas
les mois déjà soldés tardivement qui conservent leur pénalité acquise. Un
bandeau annonçant un total que la table juste en dessous ne retrouve pas serait
illisible. La pénalité totale réclamable, mois soldés compris, vit sur la page
par assureur (§6) et dans l'export (§7).

### La table « Factures en retard »

Insérée **après la `KpiRow`** — qui reste à trois colonnes — et **avant** le
graphique du parcours des paiements.

| Assureur | Mois | Déposée | Retard | Reste dû | Pénalité |
|---|---|---|---|---|---|

- **Retard** affiche le *dépassement* (`ageDays − standardDelayDays`, « +48 j »),
  jamais l'âge brut : chaque assureur a son propre délai, et « 48 jours » ne
  veut rien dire sans lui. Le délai convenu part en infobulle.
- **Pénalité** vaut « — » quand l'assureur n'a pas de clause, jamais « 0 ».
  Zéro se lit « rien à réclamer », le tiret « pas de clause ».
- Le nom de l'assureur est un lien vers sa page (§6).
- **Les huit pires lignes seulement.** Le pied de bloc annonce « et N autres »
  et mène à `pharmacy.history?insurer=…`, dont le filtre existe déjà. Une
  officine portant quarante factures en retard noierait le reste de l'écran.

### Pas de `Inertia::defer` sur ce bloc

La convention du projet réserve les deferred props aux requêtes dépassant
~200 ms, ce qui vaut pour le graphique du parcours, déjà différé. Ici ce sont
trois requêtes courtes — les délais standards distincts, les lignes en retard,
puis leurs versements en un `whereIn` — dont les deux premières existent déjà.
Et surtout, un squelette pulsant à l'endroit exact où l'alerte doit apparaître
irait contre le but du changement.

### `chaseNotice` ne bouge pas

L'encart de la barre latérale redit une partie de ce que dit le bandeau, avec
son horloge à lui. Il est laissé intact dans ce lot plutôt que d'élargir le
périmètre. C'est un candidat au nettoyage une fois le lot B posé.

## 6. Page par assureur

### Route et contrôleur

`GET pharmacy/insurers/{insurer}`, nommée **`pharmacy.insurers.show`**.

Le dernier segment est `show` : aucun des pièges Wayfinder documentés
(`exports`, mots réservés JS, globaux d'ambiance) ne s'applique, et
`pharmacy.insurers.update` prouve déjà que le préfixe partagé passe.
Régénération par **`npm run build`**, jamais par `artisan wayfinder:generate`.

Contrôleur neuf : `App\Http\Controllers\Pharmacy\InsurerRelationshipController`,
en `__invoke`. Il n'est **pas** greffé sur `PharmacyInsurersController`, dont le
métier est la case à cocher « je travaille avec cet assureur », pas l'analyse.
Page `resources/js/pages/pharmacy/Insurer.vue`, à côté de `Insurers.vue`.

**Garde d'accès** : 404 si l'officine n'a ni déclaré à cet assureur ni ne l'a
coché. Même logique que `PharmacyExportController::insurerId()` : un assureur
inconnu ne doit pas rendre une page vide, qui se lirait « rien déclaré ».

### Les chiffres

Classe nouvelle `App\Services\Pharmacy\InsurerRelationshipReport`, méthode
`build(Pharmacy, Insurer, Period $from, Period $to)`, renvoyant les agrégats
(`App\Data\InsurerRelationship`) **et** les lignes mois par mois.

**Pourquoi pas une méthode de `PharmacyStatsService`.** L'en-tête de cette
classe pose deux contraintes explicites : elle ne lit qu'en *query builder*,
jamais en Eloquent, précisément pour ne jamais charger `private_note` ; et elle
ne produit que des agrégats. L'écran par assureur viole les deux — il lui faut
les versements de chaque déclaration pour la pénalité, et une ligne par mois.
Y greffer `forInsurer()` obligerait à réécrire cet en-tête pour dire l'inverse
de ce qu'il dit. Une classe dédiée coûte un fichier et garde les deux contrats
lisibles.

La requête sélectionne **explicitement ses colonnes** et laisse `private_note`
de côté : l'écran ne l'affiche pas, et `pharmacy/History` reste le seul endroit
où la note apparaît.

Le filtrage se fait sur les ordinaux `period_year * 12 + period_month`, comme
`PharmacyExportRows::declarations()` — surtout pas via
`PharmacyStatsService::window()`, qui raisonne en mois glissants. Les deux
écrans doivent tomber d'accord sur ce que couvre « les douze derniers mois »,
puisque l'un mène à l'autre par un bouton d'export.

**`App\Services\Declarations\LongestDelay`**, troisième petite classe pure : le
délai le plus long est calculé à l'identique ici et dans la synthèse du PDF
(§7), et deux implémentations divergeraient.

`InsurerRelationship` porte : facturé, encaissé, reste dû, taux de recouvrement,
délai moyen pondéré, **délai le plus long**, **pénalité réclamable**, nombre de
mois déclarés, et le rappel de convention (délai standard, délai de
déclenchement, taux).

**Délai le plus long** = `max(plus long delay_days parmi les mois réglés, âge de
la plus vieille facture encore impayée)`. Une seule valeur qui répond à « quel
est le pire retard constaté avec cet assureur », soldé ou non.

L'âge d'un impayé se compte **depuis le dépôt de facture**, la même horloge que
`OverdueLine::ageDays` et que `delay_days` — surtout pas depuis la fin du mois
déclaré, qui est l'horloge des tranches d'ancienneté et donnerait un second
chiffre pour la même facture. Une déclaration sans `invoice_deposited_on` n'a
pas d'âge et n'entre dans aucun des deux termes. Le tout vaut `null` si rien
n'a été déclaré sur la période.

**Pénalité réclamable** = somme des pénalités de tous les mois de la période,
mois soldés tardivement compris — c'est là qu'apparaît la pénalité acquise que
le bandeau du tableau de bord, lui, ne compte pas.

### Contenu de l'écran

- **Rappel de convention** en en-tête : délai standard, délai de déclenchement,
  taux, en lecture seule, avec la mention que c'est l'APhaSPB qui les
  renseigne. Sans lui, la colonne pénalité est un nombre tombé du ciel.
- **KPI** : facturé, encaissé, taux de recouvrement, délai moyen pondéré,
  délai le plus long, pénalité réclamable.
- **Table des mois** déclarés avec cet assureur : mois, statut, facturé,
  encaissé, reste dû, dépôt, dernier versement, délai, pénalité, et le lien qui
  rouvre le mois.
- **Sélecteur de période** (`PeriodPicker` + `StatsPeriod`, déjà construits pour
  l'écran d'export) plutôt qu'une fenêtre de douze mois figée. Le délai le plus
  long et la pénalité réclamable changent franchement de sens selon la fenêtre :
  les figer masquerait précisément la vieille facture qui fait tout l'intérêt du
  chiffre.
- Un bouton « Exporter » pointant sur `pharmacy.data-exports.download?insurer=…`,
  qui existe déjà.

### Chemin d'accès

Deux entrées, aucune dans la navigation latérale :

- depuis le nom de l'assureur dans la table des factures en retard (§5) ;
- depuis `pharmacy/Insurers.vue`, où chaque nom devient un lien — mais
  seulement pour les assureurs portant au moins une déclaration.

`ConsoleNavigation::items()` marque une entrée active par préfixe de chemin,
donc « Mes assureurs » reste allumé sur cette page. C'est le comportement voulu.

## 7. Export officine

### Trois colonnes de plus

Insérées après `dans_le_delai` dans `PharmacyExportRows::COLUMNS` :

```
delai_declenchement_penalite_jours
taux_penalite_pct
penalite_fcfa
```

Toutes vides quand l'assureur n'a pas de clause. Les deux premières existent
pour rendre la troisième **vérifiable** : un montant de pénalité sans le taux
qui l'a produit est inauditable dans un tableur.

`PharmacyExportRows::declarations()` charge déjà `payments` et l'assureur ; il
suffit d'ajouter les deux nouvelles colonnes au `select` de la relation
(`insurer:id,name,standard_delay_days,penalty_trigger_days,penalty_rate_bp`), et
le calcul ne coûte **aucune requête supplémentaire**.

Les tests référencent chaque colonne par `array_search()` sur `COLUMNS`, jamais
par son index, comme l'impose la règle du projet : l'insertion ne casse donc
aucune assertion.

### Synthèse par assureur : deux colonnes de plus dans le PDF

Le fichier officine est **une ligne par déclaration**, pas par assureur. Le
« délai le plus long » et la « pénalité potentielle » demandés n'y ont pas de
case, et leur en fabriquer une dupliquerait la même valeur sur chaque ligne du
même assureur.

**Le tableau de synthèse par assureur existe déjà** dans le PDF :
`PharmacyPdfExport::perInsurer()` alimente une table « Assureur / Décl. /
Facturé / Encaissé / Reste dû / Recouvrement / Délai moyen / Versements » dans
`resources/views/exports/pharmacy.blade.php`. Il n'y a donc **rien à créer** :
elle gagne deux colonnes, **Délai le plus long** et **Pénalité**.

Ces deux valeurs sont calculées **dans `perInsurer()`, sur la collection
`$declarations` que le fichier liste déjà** — surtout pas déléguées à
`InsurerRelationshipReport` (§6). Seul `LongestDelay` est partagé, parce que
c'est une définition et non une fenêtre de lecture. L'en-tête de `PharmacyPdfExport`
refuse explicitement cette délégation, et la raison tient toujours : un rapport
dont la synthèse contredirait sa propre table de détail serait pire qu'un
rapport sans synthèse. Les versements sont déjà préchargés par
`PharmacyExportRows::declarations()`, donc le calcul ne coûte aucune requête.

**Conséquence assumée** : la page par assureur et le PDF peuvent afficher des
valeurs différentes pour le même assureur si les fenêtres diffèrent. C'est déjà
le cas du délai moyen et du taux de recouvrement, et chaque document porte sa
période en en-tête.

Le CSV et le XLSX gardent leur granularité : la pénalité y est ligne à ligne, et
un tableur la somme trivialement. La vraie agrégation par assureur tombe dans
l'export **réseau** du lot B, qui est déjà une ligne par assureur.

Contraintes dompdf à respecter, déjà documentées : ni flexbox ni grid — les
colonnes sont des `<table>` —, `page-break-inside: avoid` sur les lignes. La
table de synthèse passe de huit à dix colonnes : vérifier qu'elle tient encore
en A4 portrait.

## 8. Tests

**`tests/Unit/Services/Declarations/PenaltyCalculatorTest.php`**

- assureur sans clause → `null` ;
- déclaration sans date de dépôt → `null` ;
- statut `rejected` → `null` ;
- facture déposée pile au jour de déclenchement → une tranche exactement ;
- un jour avant le déclenchement → zéro ;
- base dégressive : deux versements intercalés, chacun allégeant les tranches
  suivantes ;
- mois soldé en retard → pénalité **figée à la date du dernier versement**, et
  qui ne bouge plus quand le temps passe (`travel()`) ;
- mois jamais réglé → pénalité qui croît de tranche en tranche (`travel()`) ;
- base retombée à zéro en cours de route → arrêt, pas de tranche fantôme.

**Migration** : aucun assureur existant ne sort avec une clause de pénalité.

**`SaveInsurerRequest`** : les deux champs obligatoires l'un avec l'autre dans
les deux sens ; les deux vides effacent la clause ; conversion pourcentage →
points de base exacte sur une valeur à décimale (2,50 → 250).

**`PaymentJourneyTest`**

- bandeau et table absents quand rien n'est en retard ;
- présents avec le bon décompte, le bon reste dû et la bonne pire ligne ;
- pénalité à `null` pour un assureur sans clause ;
- troncature à huit lignes, avec le reste correctement annoncé ;
- **contrôle du nombre de requêtes de l'écran** — le point faible étant le
  chargement des versements.

**`tests/Feature/Pharmacy/InsurerRelationshipTest.php`**

- 404 sur un assureur ni déclaré ni coché ;
- 200 sur un assureur coché sans déclaration ;
- délai le plus long issu d'un **encours** et non d'un mois réglé ;
- pénalité réclamable incluant bien un mois soldé tardivement ;
- changement de période qui déplace les trois chiffres.

**`PharmacyExportTest`**

- les trois nouvelles colonnes, référencées par leur nom ;
- vides pour un assureur sans clause ;
- colonnes « délai le plus long » et « pénalité » présentes dans la table de
  synthèse du PDF, absentes du CSV et du XLSX.

## 9. Ce que ce lot ne fait pas

Renvoyé au **lot B** :

- colonnes `delai_le_plus_long_jours` et `penalite_potentielle_fcfa` dans
  `NetworkExportRows` ;
- page admin par assureur, soumise au seuil d'anonymat ;
- stratégie d'agrégation de la pénalité à l'échelle du réseau — le calcul par
  déclaration en PHP ne passera pas à cette échelle (§4).

Explicitement hors périmètre, des deux lots, sauf demande :

- mention de la pénalité dans le digest e-mail `OverduePaymentsDigest` ;
- refonte de `ConsoleNavigation::chaseNotice()` et de son horloge à 60 jours ;
- pénalités composées, ou tout autre mode de calcul que la tranche simple ;
- historisation des pénalités (aucune colonne, aucun instantané — §4).

## 10. Ordre d'implémentation

1. Migration + `Insurer` (casts, `Fillable`, accesseur de pourcentage).
2. `PenaltyCalculator` et ses tests unitaires — rien ne dépend de l'UI.
3. `SaveInsurerRequest` + `InsurerManagementController` + `admin/Insurers.vue`.
4. `OverdueLine::penalty` et `OverduePaymentsService::forPharmacy()`.
5. Bandeau et table du tableau de bord.
6. `LongestDelay` + `InsurerRelationshipReport` + `InsurerRelationship`.
7. Route, contrôleur et page par assureur ; `npm run build` pour Wayfinder.
8. `PharmacyExportRows` (3 colonnes) puis `PharmacyPdfExport::perInsurer()`
   (2 colonnes dans la table de synthèse existante).
9. `composer ci:check` en entier — pas seulement `pint --dirty`.
