---
paths:
  - 'app/Services/Network/**'
---

# Network

## Deux indicateurs de ponctualité : par déclaration et par argent — ils divergent volontairement
Depuis les échéances multiples (08/09/2026), `NetworkStatsService::perInsurer()` expose deux mesures distinctes du respect du délai standard :

- `withinThresholdShare` — part des **déclarations** réglées dans les temps. Jugée sur `declarations.delay_days`, qui porte le délai du **dernier** versement : un solde tardif fait sortir tout le mois du délai.
- `recoveredWithinDelayShare` — part de l'**argent** arrivé dans les temps, sommée versement par versement sur `declaration_payments.delay_days`, rapportée au **facturé**. Un acompte payé vite compte, même si le solde traîne ; ce qui n'a jamais été payé pèse comme ce qui a été payé en retard.

L'écart entre les deux est le signal utile, pas un bug : ne pas « harmoniser » les dénominateurs.

`recoveredWithinDelay()` est une **requête séparée**, pas une jointure dans l'agrégat principal : joindre les versements multiplierait les lignes de déclaration et gonflerait tous les `COUNT(*)` et `SUM(amount_invoiced)` voisins. `perInsurer()` coûte donc 3 requêtes, pas 2 (test dédié).

`declaration_payments.delay_days` est stocké pour la même raison que celui des déclarations : comparer une date à un intervalle porté par une colonne demanderait de l'arithmétique de dates en SQL, que ce projet évite.

Colonne d'export : `recouvre_dans_delai_pct`, insérée après `part_sous_seuil_pct` — les index des colonnes suivantes ont bougé d'un cran.

## Le pire retard se calcule en SQL, la pénalité non
**Le délai le plus long ne demande aucune boucle.** Sa définition — par déclaration, l'âge de l'encours si elle est ouverte, sinon son `delay_days`, les rejetées ignorées — se scinde en deux agrégats purs regroupés par assureur :

```sql
MAX(CASE WHEN status != 'rejected' AND amount_invoiced <= amount_received THEN delay_days END)
MIN(CASE WHEN status != 'rejected' AND amount_invoiced >  amount_received
              AND invoice_deposited_on IS NOT NULL THEN invoice_deposited_on END)
```

L'âge se déduit du second par une soustraction, et le résultat est le max des deux termes non nuls. Prendre le max dans chaque groupe puis entre les groupes donne le max global. **Un test confronte l'agrégat SQL à `LongestDelay` déclaration par déclaration** : le garder, c'est lui qui rend l'équivalence vérifiable plutôt que supposée.

**La pénalité garde une boucle** — ses tranches sont séquentielles — mais restreinte aux assureurs sous convention (`whereNotNull('penalty_trigger_days')`). Deux assureurs sur huit sous clause = un quart des lignes lues.

`InsurerPenaltyAggregates` coûte **quatre requêtes quel que soit le volume** : les clauses, l'agrégat de délai, les versements (**joints**, jamais un `whereIn` sur 40 000 identifiants), le curseur des déclarations. Un test l'épingle. `cursor()` plutôt que `get()` ne change ni le résultat ni le compte de requêtes, seulement la mémoire — aucun test ne peut les séparer.

`DeclarationWindow` porte le filtre période + ville, partagé par les requêtes parties d'une autre table. Ne pas le recopier : deux copies du filtre qui décide quelles officines entrent dans un agrégat finiraient par diverger.

## L'anonymat des agrégats de pénalité se décide en amont, pas à la sortie
`InsurerPenaltyAggregates::forInsurers()` et `NetworkStatsService::monthlyByInsurer()` reçoivent **les identifiants d'assureurs déjà autorisés** par `NetworkStatsService::perInsurer()`. Le seuil n'est pas réévalué : il est en amont. Ne pas leur faire appeler `anonymityMinPharmacies()` — ce serait un second point de décision, donc un second endroit où se tromper.

**Le seuil par défaut vaut 5** (`SettingsRepository::DEFAULTS`), pas 2 : `ANONYMITY_FLOOR = 2` n'est que le plancher réglable. Un test qui crée moins de 5 officines verra son assureur retenu, avec toutes ses cellules vides — cause d'échec non évidente à la lecture.

**Vérifié par mutation, et le résultat surprend** : pour le **CSV**, passer *tous* les assureurs à l'agrégateur ne fait rougir aucune assertion de sortie — la branche `withheld()` de `NetworkExportRows` protège déjà le fichier. Le filtre en amont est donc de la **défense en profondeur**, pas la garde porteuse. Un test à espion (`$this->mock(InsurerPenaltyAggregates::class)`) l'épingle explicitement, pour qu'une colonne ajoutée un jour au chemin « retenu » ne puisse pas la faire fuiter. Pour le **PDF**, en revanche, la garde est structurelle : les pages itèrent `$rows`, qui ne contient que des assureurs autorisés.

## Le seuil d'anonymat vaut à chaque granularité publiée, pas une fois par période
**Correction d'une règle précédente qui surestimait la garantie.** `NetworkStatsService::perInsurer()` décide du seuil sur `COUNT(DISTINCT pharmacy_id)` **de toute la période**. Cette clairance ne vaut **que pour les agrégats de période**.

Dès qu'une sortie désagrège un assureur autorisé — par mois, par ville, par statut —, le seuil doit être réévalué à cette granularité. Un assureur déclaré par cinq officines sur l'année peut n'en avoir eu qu'une en mars : la ligne de mars rend alors la facture exacte d'une officine nommable.

Le cas s'est produit : `monthlyByInsurer()` + les pages par assureur du PDF réseau imprimaient ce mois-là en clair. `NetworkPdfExport::withheldMonths()` retient désormais les mois sous le seuil.

Conséquences pratiques :

- une méthode d'agrégat qui désagrège **expose son `declaringPharmacies`** et ne décide pas ; c'est l'appelant qui détient `SettingsRepository` qui retient ;
- la ligne retenue est **conservée et vidée**, jamais supprimée — une ligne absente se lit « rien déclaré », pas « chiffres retenus ». Même choix que `NetworkExportRows::withheld()` ;
- le seuil par défaut vaut **5** (`SettingsRepository::DEFAULTS`), pas 2 : `ANONYMITY_FLOOR = 2` n'est que le plancher réglable ;
- un test d'absence de fuite doit avoir un décor **discriminant** : si le total de période de l'assureur coïncide numériquement avec la valeur retenue, le test rougit sur un agrégat parfaitement légitime.

## Le résumé réseau a un seuil, filtré ou non (28/09/2026)
**Remplace la règle « le résumé réseau n'a pas de seuil — sauf restreint à un assureur ».** Une revue a combiné les écrans : dans une ville de 5 officines dont une seule a déclaré, le suivi des déclarations la nomme, et « Statistiques réseau » / « Évolution » filtrés sur la ville publiaient son facturé et ses assureurs.

- `NetworkStatsService::networkSummary()` et `aggregatedAmounts()` décident eux-mêmes (ils détiennent `SettingsRepository`, comme `perInsurer()`) : reposant sur 1 à seuil − 1 officines distinctes après filtres ville / assureur, ils rendent `withheld: true`, `required: N` et **toutes** les valeurs à null, compte d'officines compris. Zéro officine = zéros publiés (« rien déclaré »), pas retenu.
- `outstandingBeyond()` est protégé : il n'a pas de seuil propre et ne sort que par `networkSummary()`.
- `NetworkPdfExport::summary()` ne décide plus rien : l'ancien cas « assureur choisi sous le seuil » est un cas particulier de la règle générale. Le Blade teste `$summary['withheld']`.
- Écrans : KPI « retenu » + « moins de N officines déclarantes sur ce périmètre ».
- Non filtré par ville, s'ajoute la règle de partition (section dédiée plus bas) : `withheldReason: 'city-share'`.
- Risques **acceptés** (décision du 28/09/2026, texte à faire signer par l'APhaSPB, spec §3) : (1) « résumé publié − lignes assureurs publiées » rend la part des assureurs masqués (même classe que la décision du 27/09/2026 sur le journal) ; (2) deux périodes qui se recouvrent (« 12 derniers mois » − « année civile ») isolent une tranche de mois ; (3) « Gestion des assureurs » affiche le nombre d'officines conventionnées de chaque assureur, canal latéral pour ceux qui en ont 1 à N − 1. Ne pas les « corriger » sans nouvelle décision.

Le filtre lui-même vit dans `DeclarationWindow::apply()`, seul goulot des neuf agrégats. `InsurerPenaltyAggregates` ne le reçoit pas : son `whereIn` sur les assureurs autorisés le restreint déjà.

Tests : `the network summary resting on fewer officines than the threshold is withheld, even unfiltered`, `the city filter re-applies the threshold to the network summary`, `tests/Feature/Admin/CombinedScreensLeakTest.php` (scénario de revue) et `the report still renders when the summary is withheld` (rendu). Vérifié par mutation.

## Aucun compte exact sous le seuil
`InsufficientData::$declaringPharmacies` reste en mémoire pour les services, mais **ne sort jamais** : `InsurerIndicatorsResource` / `InsurerAmountsResource` le rendent null quand `sufficient` est faux, `NetworkExportRows::withheld()` écrit « moins de N » dans `officines_declarantes`, le PDF ne reçoit que le nom des assureurs retenus et vide `declaringPharmacies` des mois retenus. Sous le seuil, « 1 officine déclarante » à côté du suivi qui nomme les déclarantes désigne l'officine.

## La courbe des délais se retient point par point
`delayTrend()` compte les officines distinctes de chaque point assureur × mois ; un point sous le seuil n'est pas tracé (`insurers[id].withheld`), ni, non filtré par ville, un point dont la part des villes non publiées repose sur 1 à N − 1 officines (règle de partition, +1 requête groupée). La ligne réseau ne moyenne **que les points publiés** : y mêler un point caché le rendrait par « réseau × n − points visibles » ; n'étant qu'une fonction des points publiés, elle n'a pas besoin de règle de partition propre. Un mois sans aucun point publié est listé dans `withheldMonths`.

## Journal des pénalités : cumul vidé après un mois retenu, total sous seuil propre
`NetworkPenaltyJournal` est le seul point de décision du seuil pour le journal ; écrans et exports passent tous par lui.

- Un mois retenu vide **tous les cumuls suivants** de la série : cumul(M) − cumul(M−1) = couru(M), le rendrait déductible.
- Un mois est retenu si ses officines contributrices (couru **ou** mois déclaré) sont entre 1 et seuil − 1. Zéro officine = zéro publié, pas retenu.
- Série totale non filtrée : **tous** les assureurs sous convention, masqués compris — décision client du 27/09/2026, risque « un seul assureur masqué se déduit par différence » accepté. Verrouillé par `a masked insurer has no series but still counts in the unfiltered total` et `the unfiltered total resting on the threshold is published, masked insurers included` ; sous le seuil, `the unfiltered total is withheld when it rests on fewer officines than the threshold`.
- Deux exceptions : le mois du total est retenu quand sa **part cachée** (mois retenus des séries publiées, officines distinctes, par horloge) repose sur 1 à seuil − 1 officines — sinon total − séries visibles rend ce mois (27/09/2026) ; et quand le total lui-même y repose sur 1 à seuil − 1 officines, **avec ou sans filtre ville** (étendu au total non filtré le 28/09/2026 : un réseau d'une déclarante, que le suivi nomme, rendait sa pénalité). `PenaltyTally::ledger()` passe ces compteurs cachés à la closure, pour le total seulement. Non filtré par ville, séries et total suivent aussi la règle de partition, mois par mois (couru, déclaré, découpage) : la closure reçoit le mois et lit `PenaltyTally::cityCounts()`.
- Filtrée sur un assureur masqué, la série totale est retenue en bloc ; sur un assureur autorisé, elle suit sa rétention mois par mois.

`NetworkPenaltyLedger::tally()` coûte trois requêtes quel que soit le volume (test dédié).

## Le découpage par statut de clôture relève aussi du seuil
Due / recouvrée / abandonnée (`InsurerPenaltyAggregates` → exports réseau) et `accruedPaid` / `accruedWaived` / `accruedDue` (journal) désagrègent un assureur autorisé : une officine qui annule seule publierait son montant exact.

**Règle de partition** (27/09/2026) : le découpage n'est publié que si chaque part **non vide** repose sur ≥ seuil officines distinctes ; sinon les trois parts tombent **ensemble** (null), la courue / le couru du mois restant publiés. Une seule part cachée se retrouverait par différence avec les autres.

- Les agrégats exposent `PenaltySplitPharmacies` (compté dans le curseur existant : toujours 4 requêtes) ; `NetworkExportRows` / `NetworkPdfExport` décident via `restsOnFewerThan()`. CSV/XLSX : cellules vides ; PDF : « retenu » + une ligne d'explication.
- Journal : `PenaltyTally::ledger(..., $splitWithheld)` passe les officines de chaque part et, pour le total, celles des parts cachées des séries publiées (mois retenus ou découpages retenus) ; `NetworkPenaltyJournal` retient, séries **et** total, filtre ou non. `PenaltyLedgerMonth::splitWithheld` dit « retenu » à l'écran et dans les exports. Toujours 3 requêtes.
- Le journal officine n'est pas concerné (l'officine lit ses propres chiffres).
- La partition reste stricte pour la due aussi : un assureur autorisé sans aucune clôture, dont 1 à seuil − 1 officines seulement ont couru une pénalité, garde `penalite_potentielle_fcfa` (et le titre du PDF) retenus, même quand le journal ne retient rien (test `without closures, a due resting on too few officines stays withheld`).

## Un mois retenu du journal retient le découpage de l'export
La due / recouvrée / abandonnée de période d'un assureur autorisé (`NetworkExportRows`, `NetworkPdfExport`), moins les mois publiés du journal réseau du **même périmètre** (période, ville, filtre assureur), rend tout mois que le journal retient — en entier ou dans son seul découpage. Cas courant : mêmes fenêtres, `to` = mois courant.

- Les trois chiffres de l'export tombent donc ensemble dès que la série de l'assureur a un mois non futur `withheld` ou `splitWithheld` : `PenaltyLedger::insurersWithWithheldMonths()`, lu sur `NetworkPenaltyJournal::for()` **une fois par export**, jamais par assureur.
- S'ajoute à la règle de partition, ne la remplace pas. PDF : « retenu » + note qui cite le journal (`splitWithheldByLedger`).
- Un assureur sans série (pas de clause) n'est pas concerné. L'export gagne les requêtes du journal ; `InsurerPenaltyAggregates` (4) et `tally()` (3) sont inchangés.
- Tests : `a month whose status split the journal withholds…` et `a month the journal withholds entirely…` — vérifiés par mutation (garde neutralisée, les deux rougissent).

## DeclarationCompleteness est la seule exception nom–déclaration
Le CDC interdit à l'espace réseau « un nom d'officine lié à une déclaration ». Exception décidée le 28/09/2026 (spec suivi des déclarations, §3), **à faire valider par écrit côté APhaSPB avant la production** : `DeclarationCompleteness` + `Admin\DeclarationFollowUpController` montrent, par officine nommée et par mois terminé, l'état complet / partiel / rien — et rien d'autre.

Bornes à ne pas franchir : jamais d'assureur (nom, id, nombre), de montant, de statut, de date ou de note privée ; le lecteur ne lit que des `COUNT(DISTINCT insurer_id)` groupés, jamais une ligne `declarations` hydratée. Deux requêtes quel que soit le nombre d'officines (test dédié). Mêmes règles que `DeclarationCalendar` côté officine, qui fait la même jointure sur `insurer_pharmacy` (complet = chaque assureur coché déclaré ; décochés ignorés des deux côtés ; rejetée = déclarée), plus : officines sans assureur, supprimées ou inscrites après la fin du mois exclues.

« Pharmacies inscrites » (`RegisteredPharmaciesController`) reste sans aucune donnée de déclaration. Test de confidentialité : `the screen never carries an insurer, an amount or a private note` — chercher « insurer » dans les seules props de l'écran, la coquille nommant « Gestion des assureurs ».

## Aucun chiffre réseau sous le seuil, aucun compte exact sous le seuil
Décision du 28/09/2026, après une fuite par combinaison d'écrans (suivi des déclarations + stats filtrées sur une ville d'une seule déclarante). 1) Tout agrégat réseau (résumé, montants réseau, point de courbe assureur × mois et réseau, mois du total du journal, filtré ou non) repose sur ≥ seuil officines distinctes à sa propre granularité, sinon il est retenu : conservé, vidé, expliqué (« retenu », « moins de N officines déclarantes »), jamais supprimé. Zéro officine se publie à zéro. 2) Le compte exact d'officines d'une entrée retenue ne sort jamais (props, CSV/XLSX, PDF, notifications) : `InsufficientData::$declaringPharmacies` reste interne. Garde-fous : `CombinedScreensLeakTest`, `summaryIsWithheld()`, resources à `declaringPharmacies` null.

## Règle de partition par ville : non filtré moins les villes publiées
Revue du 28/09/2026 (2e tour). Les villes partitionnent le réseau : un chiffre NON filtré par ville, moins les chiffres publiés de chaque ville, rend les villes retenues et les officines sans ville. `CityPartition` (pure) calcule la part cachée H = officines des villes à 1…N−1 + officines sans ville (toujours) ; 1 ≤ H < N → le chiffre non filtré est retenu (`InsufficientData::$cityShare`, `withheldReason: 'city-share'`). Appliquée par l'appelant qui détient le seuil, à chaque granularité : `networkSummary()`/`aggregatedAmounts()`, `perInsurer()`/`aggregatedByInsurer()` (+1 requête groupée assureur × ville : perInsurer coûte 4 requêtes), points assureur × mois de `delayTrend()`, mois de `monthlyByInsurer()` (PDF), mois des séries et du total du journal (`PenaltyTally::cityCounts()`, ville jointe au curseur : toujours 3 requêtes), découpage due/payée/annulée (`withholdsSplit`, export et journal ; ville jointe : `InsurerPenaltyAggregates` toujours 4 requêtes). Sous filtre ville, rien à soustraire : règle inactive. Les comptes par ville ne sortent jamais. `PharmacyFactory` a une ville fixe (Cotonou) pour que ces tests soient déterministes.

**Troisième tour (même jour) : l'assureur « city-share » reste masqué dans les totaux.** Synthèse − lignes publiées − ses lignes de ville publiées rendait ses cellules cachées. La synthèse, les montants réseau (et l'encours > 90 j) ainsi que chaque mois du total du journal (découpage compris) sont donc aussi retenus quand l'**union**, en officines distinctes, des cellules cachées des assureurs « city-share » repose sur 1 à N − 1 officines (`CityPartition::hiddenCellsOfCityShareInsurers()`, `NetworkPenaltyJournal::hiddenCells()` sur `PenaltyTally::cityPharmacies()`). Union d'identifiants, pas somme de comptes : une officine déclare à plusieurs assureurs. `NetworkStatsService::partitionCells()` lit les paires distinctes (assureur, officine, ville) en une requête, qui remplace l'ancien comptage groupé : aucune requête de plus. Les assureurs retenus faute d'officines restent dans le risque accepté « synthèse − assureurs publiés ».

**NULL et ''** sont deux groupes en SQL mais une seule clé « sans ville » (`CityPartition::key()`) : les comptes groupés s'**additionnent** (`+=`), jamais ne s'écrasent (test `officines with a null city and with an empty city add up in the hidden share`).

**Casse des villes.** Les regroupements PHP (`PenaltyTally`, `InsurerPenaltyAggregates`, `partitionCells()`) comparent la chaîne exacte, le filtre ville compare en SQL. Identiques sous SQLite (pilote de `.env.example` et de `config/database.php`, seul visé aujourd'hui). Sous une collation MySQL insensible à la casse, « Cotonou » et « cotonou » seraient une seule ville filtrable mais deux compartiments : si la production passe à MySQL, normaliser (trim + minuscules) des deux côtés avant de s'y fier.
