# Clôture des pénalités — payée ou annulée par l'officine

Date : 27/09/2026
Statut : implémentée le 27/09/2026 — le §10 consigne les écarts décidés à l'exécution

> Suite du journal des pénalités (spec du même jour). Jusqu'ici, une pénalité
> courue restait acquise pour toujours, même une fois l'assureur à jour. Ce lot
> laisse l'officine la **clore** : l'assureur l'a payée, ou elle y renonce.

## 1. Demande

> Lorsque la pharmacie finit par confirmer le paiement total de la facture
> d'un mois donné, elle doit pouvoir annuler la pénalité ou la mentionner comme
> payée. À ce stade, la pénalité ne va plus compter.

## 2. Décisions prises en séance

| Question | Décision |
|---|---|
| Payée et annulée, une seule issue ou deux ? | **Deux issues distinctes.** « Payée » : l'assureur a versé la pénalité (argent recouvré). « Annulée » : l'officine y renonce (argent abandonné). Toutes deux sortent de la pénalité due, mais se comptent à part. |
| « Ne plus compter », où ? | **Due ≠ courue.** La pénalité close sort de tout ce qui dit « à réclamer » : tableau de bord, écran assureur, exports, agrégats réseau. Le journal garde l'historique de la pénalité courue et ajoute « dont payée », « dont annulée », « reste due ». |
| Quand le geste est-il possible ? | **Seulement quand le reste dû du mois vaut zéro** : facture couverte à 100 %. |
| Une correction rouvre le mois (cas A) | **La clôture tombe**, la pénalité redevient due et recommence à courir. |
| Une correction change le montant, mois toujours couvert (cas B) | **La clôture tombe** aussi : la pénalité redevient due au nouveau montant, et l'officine la re-marque. Aucun chiffre clos ne contredit le calcul. |
| Revenir sur son geste ? | Oui : « Remettre en dû », tant que la clôture existe. |
| Où faire le geste ? | **Aux deux endroits** : la ligne du mois sur l'écran assureur, et une section du formulaire du mois. |

### Exemple de référence (repris dans les tests)

Facture NSIA de mars : 1 000 000 F, déposée le 31/03, clause 60 jours / 2 %.
Versements 400 000 F le 15/06 et 600 000 F le 20/07.

- Tranche du 30/05 sur 1 000 000 F → 20 000 F ; tranche du 29/06 sur
  600 000 F → 12 000 F ; soldée le 20/07, plus rien ne court. **32 000 F.**
- Le 05/08, l'officine marque « payée » : clos 32 000 F, **due 0 F**.
- **Cas A** — le second versement valait 500 000 F : il reste 100 000 F, le
  mois est rouvert → clôture levée, pénalité de nouveau due, elle recourt.
- **Cas B** — le premier versement est arrivé le 25/05 : la tranche du 30/05
  porte sur 600 000 F → pénalité recalculée 24 000 F ≠ 32 000 F clos →
  clôture levée, **due 24 000 F**, à re-marquer.

## 3. Vocabulaire

- **Courue** : ce que la clause a produit (`PenaltyCalculator`, inchangé).
  L'historique ne bouge jamais du fait d'une clôture.
- **Close** : la part courue marquée payée ou annulée par l'officine.
- **Due** : courue − close. C'est ce qu'on réclame.

Null ≠ zéro, comme avant : « — » = pas de convention ; « 0 » = convention,
rien à réclamer.

## 4. Données

### 4.1 Migration `declarations`

| Colonne | Type | Rôle |
|---|---|---|
| `penalty_settlement` | `string(16)` nullable | `paid` / `waived` ; null = due. Enum PHP `PenaltySettlement` (cas `Paid`, `Waived`). |
| `penalty_settled_amount` | `unsignedBigInteger` nullable | La pénalité calculée **au moment du geste**, en FCFA entiers. |
| `penalty_settled_on` | `date` nullable | Jour du geste. |
| `penalty_settled_by` | `foreignId` nullable → `users`, `nullOnDelete` | Auteur du geste. |

Les quatre colonnes se lisent comme un tout (règle de la demi-clause) : elles
sont posées et levées ensemble, par une seule méthode du modèle.

Pas de colonne de pénalité courue : la règle « pas de cache de pénalité »
tient. Le montant clos n'est pas un cache — c'est un fait (ce qui a été
payé ou abandonné), et c'est précisément l'écart avec le calcul qui lève la
clôture (cas B).

### 4.2 Migration `declaration_revisions`

`penalty_settlement` et `penalty_settled_amount` rejoignent l'instantané de
`RecordDeclarationRevision::snapshot()` (colonnes nullables). Un geste laisse
donc une révision ; un aller-retour sans changement n'en laisse pas, comme
aujourd'hui.

## 5. Règles

### 5.1 Condition du geste

Clore est possible si et seulement si :

1. le mois est couvert : `amount_received >= amount_invoiced` ;
2. l'assureur a une convention (`hasPenaltyClause()`) ;
3. la pénalité calculée (`PenaltyCalculator::for()`) est > 0.

Sinon, aucune action n'est proposée, et le serveur refuse (message clair).

### 5.2 Clore, remettre en dû

Une action unique, `App\Actions\Declarations\SettlePenalty`, porte les deux
gestes :

- `settle(Declaration, PenaltySettlement, User)` : vérifie §5.1, pose les
  quatre colonnes avec `penalty_settled_amount = PenaltyCalculator::for()`.
- `reopen(Declaration)` : lève les quatre colonnes.

Chacun écrit une révision (après l'écriture, comme dans
`DeclarationController::store()`), dans une transaction.

### 5.3 Levée automatique (cas A et B)

`App\Actions\Declarations\ReconcilePenaltySettlement` est appelée **à la fin de
`RecordPaymentInstalments::handle()`**, par où passe tout enregistrement d'une
déclaration. Le hook `saving` ne peut pas le faire : il n'a ni les versements
ni l'assureur.

Si la déclaration est close et que :

- le mois n'est plus couvert → levée, motif `uncovered` (cas A) ;
- `PenaltyCalculator::for()` ≠ `penalty_settled_amount` → levée, motif
  `amountChanged` (cas B).

Elle rend le motif (ou null) pour que l'appelant affiche un message.

## 6. Écrans

### 6.1 Écran assureur (`/pharmacy/insurers/{insurer}`)

Sur la ligne de chaque mois :

- due > 0 et §5.1 vrai → menu « Marquer payée » / « Annuler la pénalité » ;
- close → pastille « Payée 32 000 · le 05/08 » ou « Annulée · le 05/08 »,
  action « Remettre en dû ».

Routes (groupe `pharmacy.`) :

- `POST /pharmacy/declarations/{declaration}/penalty-settlement` —
  `pharmacy.penalty-settlement.store`, champ `outcome` (`paid` | `waived`) ;
- `DELETE` même URL — `pharmacy.penalty-settlement.destroy`.

La déclaration doit appartenir à l'officine de la session : sinon **404**
(pas 403, pour ne pas confirmer qu'elle existe). Après le geste, retour sur
l'écran avec un message flash.

### 6.2 Formulaire du mois (`/pharmacy/declare`)

Section « Pénalité de ce mois », affichée quand la déclaration enregistrée
porte une pénalité > 0, **couverte ou non** : choix **due / payée /
annulée**, pré-rempli sur l'état actuel. Tant que le mois enregistré n'est pas
couvert, la section le dit (« possible une fois le mois entièrement réglé »)
sans désactiver le choix : c'est ce qui permet de saisir le dernier versement
et de clore dans le même envoi. Le serveur tranche sur les versements
enregistrés.

Champ `penalty_settlement` (nullable, `paid` | `waived`) dans
`SaveDeclarationRequest`. Dans `DeclarationController::store()`, l'ordre
devient : `updateOrCreate` → `RecordPaymentInstalments` (qui réconcilie) →
**appliquer le choix** via `SettlePenalty` → `RecordDeclarationRevision`.

- Dernier versement saisi et « payée » dans le même envoi : la pénalité est
  calculée sur les nouveaux versements et close si §5.1 tient.
- Choix de clôture sur un mois qui ne remplit pas §5.1 : ignoré, message.
- Choix « due » sur un mois clos : remise en dû.

### 6.3 Message de levée automatique

Après tout enregistrement qui lève une clôture : « La pénalité de mars 2026
(NSIA) n'est plus close : le mois n'est plus entièrement réglé. » ou « … : son
montant a changé (24 000 F au lieu de 32 000 F). »

### 6.4 Tableau de bord officine

Le montant de pénalité affiché devient la **due**. Les bandeaux de retard et
`OverduePaymentsService` ne changent pas : ils ne lisent que des mois ouverts,
qui ne peuvent pas être clos.

## 7. Chiffres, exports, journal

### 7.1 Calcul

`PenaltyCalculator` gagne une lecture « due » par déclaration : courue si non
close, 0 si close (le montant clos égale la courue, §5.3 le garantit). Tous
les affichages « à réclamer » passent par elle : `InsurerRelationshipReport`,
`PharmacyPdfExport`, `PharmacyExportRows`, `InsurerPenaltyAggregates`.
`total()` garde sa règle null / zéro.

### 7.2 Exports officine

`penalite_fcfa` garde son sens (**courue**) : les classeurs existants restent
justes. Juste après, quatre colonnes : `penalite_statut` (`due` / `payee` /
`annulee`), `penalite_close_fcfa`, `penalite_close_le`, `penalite_due_fcfa`.
Le PDF officine affiche la due par assureur et par mois, avec la pastille.

### 7.3 Agrégats et exports réseau

- `penalite_potentielle_fcfa` devient la pénalité **due** — ce que
  « potentielle » promettait : ce qu'on peut encore réclamer.
- Deux colonnes : `penalite_recouvree_fcfa` (payée), `penalite_abandonnee_fcfa`
  (annulée), insérées juste après.
- `InsurerPenaltyAggregates` lit `penalty_settlement` dans son curseur
  existant : **toujours quatre requêtes**.
- Anonymat : mêmes assureurs autorisés, même rétention.

### 7.4 Journal mensuel

- `PenaltyLedgerMonth` gagne `settledPaid`, `settledWaived`, `due`.
- Vue « couru » : les tranches d'une facture close, tombées ce mois-là, vont
  à « dont payée » ou « dont annulée ». Vue « mois déclaré » : toute la
  pénalité de la facture va à son mois.
- `due = accrued − settledPaid − settledWaived` (resp. `declared − …`).
- La courbe garde la courue ; la bascule gagne une troisième horloge
  « Reste due ».
- Tableau et exports du journal : trois colonnes, `penalite_payee`,
  `penalite_annulee`, `penalite_due`.
- Rétention : un mois retenu vide aussi ces colonnes, et leurs cumuls
  s'interrompent de la même façon. La part cachée du total (§6 de la spec du
  journal) ne change pas de définition : elle porte sur les officines, pas
  sur les montants.

## 8. Tests (Pest)

Décor de référence : l'exemple du §2.

- **Règles** : clore refusé sur mois non couvert, pénalité nulle, assureur
  sans convention ; déclaration d'une autre officine → 404 ; payée et
  annulée → due 0 ; remettre en dû → due revenue.
- **Levée automatique** : cas A (versement réduit) lève ; cas B (date du
  premier versement) lève avec motif `amountChanged` ; ré-enregistrement
  identique ne lève pas ; message affiché dans chaque cas.
- **Formulaire** : dernier versement + « payée » dans le même envoi clôt ;
  « payée » sur mois ouvert ignoré avec message ; « due » sur mois clos
  remet en dû.
- **Chiffres** : écran assureur, exports officine et PDF, agrégats et exports
  réseau excluent le clos de la due ; recouvrée et abandonnée justes ; nombre
  de requêtes réseau inchangé.
- **Journal** : « dont payée » au bon mois dans les deux vues ; mois retenu
  vide les nouvelles colonnes.
- **Révisions** : un geste laisse une révision ; un aller-retour sans
  changement n'en laisse pas.

Vérification finale : `composer ci:check`.

## 9. Hors lot

- Saisie d'un montant de pénalité négocié différent du calculé.
- Clôture d'une pénalité par le réseau (admin).
- Notification à l'assureur ou au réseau lors d'une clôture.
- Clôture groupée de plusieurs mois en un geste.

## 10. Écarts avec la version validée

Décidés pendant l'exécution. Là où ils contredisent les sections précédentes,
**ce sont eux qui font foi**.

- **§7.4** — les noms suivent le plan, pas la lettre du §7.4 : la ligne du
  journal porte `accruedPaid`, `accruedWaived`, `accruedDue`, `declaredDue`
  (pas `settledPaid` / `penalite_payee`), et les exports `dont_payee`,
  `dont_annulee`, `reste_due`. `declaredDue` ne sort d'aucune colonne
  d'export ni d'aucun graphe : il ne vit que dans la donnée du journal.
- **§6.1 (revue)** — le tableau du journal s'élargit (largeur minimale
  880 px, défilement horizontal) plutôt que de cacher les trois nouvelles
  colonnes sur écran étroit : une colonne absente se lirait « rien couru »,
  ce que le journal s'interdit déjà pour une ligne retenue.
- **§7.2** — `penalite_statut` est vide (pas « due ») quand l'assureur n'a
  pas de clause : null dit « pas de convention », la chaîne `due` dirait
  « une convention, rien n'est clos ». Même distinction que pour le montant.
- **§7.1** — la due par ligne suit le même null que la courue : un mois
  rejeté sous convention lit null, pas 0, comme `PenaltyCalculator::for()`.
  La règle « clause d'abord » du plan ne s'applique qu'au niveau du lot
  (`dueTotal()`), pas ligne par ligne.
- **§6.4** — confirmé sans changement : les chiffres du tableau de bord ne
  couvrent que les mois ouverts, qui ne peuvent pas être clos.
- **§7.3 / §7.4 (revue, tranché le 27/09/2026)** — « mêmes assureurs
  autorisés, même rétention » ne suffisait pas : le découpage par statut
  désagrège un assureur autorisé, et une officine qui annule seule publiait
  son montant exact. **Règle de partition** : due / recouvrée / abandonnée
  (exports réseau) et `accruedPaid` / `accruedWaived` / `accruedDue` (journal
  réseau, séries et total) ne sont publiées que si chaque part non vide
  repose sur au moins le seuil d'officines ; sinon les trois sont retenues
  ensemble, la courue restant publiée. Le total retient aussi son découpage
  quand les parts cachées des séries publiées tomberaient sous le seuil. Le
  journal officine n'est pas concerné.
- **Trou connu, laissé ouvert** — modifier la clause d'un assureur
  (`InsurerManagementController::update()`) ne réconcilie aucune déclaration
  déjà close.
