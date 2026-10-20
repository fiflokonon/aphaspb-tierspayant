---
paths:
  - 'app/Services/Declarations/**'
---

# Services Declarations

## La pénalité n'a pas de colonne de cache, et c'est voulu
`delay_days` est stocké parce qu'il ne dépend que des données saisies : deux dates entrent, un entier sort, et le hook `saving` le régénère. La pénalité, elle, **croît toute seule** : un mois jamais réglé voit la sienne augmenter tous les 30 jours sans qu'aucune écriture ne survienne. Une colonne exigerait un job quotidien, et le chiffre serait faux entre deux passages.

Conséquence à connaître avant d'optimiser : `PenaltyCalculator` ne se somme pas en SQL. Il lui faut les versements de chaque déclaration, donc `with('payments')` ou un `whereIn` groupé. À l'échelle d'une officine c'est une centaine de lignes ; **à l'échelle du réseau (lot B) il faudra une autre stratégie**.

Deux portes d'entrée sur un seul algorithme : `for(Declaration)` pour du code qui hydrate de l'Eloquent, `accrued(...)` sur valeurs nues pour `OverduePaymentsService`, qui lit en query builder et ne doit jamais charger `private_note`.

Le `break` sur base nulle a disparu avec la base fixe (10/10/2026) : une tranche ne dépend plus des versements, la course ne s'arrête qu'au solde (voir la règle « base fixe » plus bas).

## paid_on existe dès le premier versement — ne pas le lire comme « mois réglé »
`syncFromInstalments()` pose `paid_on` sur le **versement le plus récent**, y compris quand il ne solde rien. Le hook `saving` en tire donc un `delay_days` pour tout mois seulement *entamé* : un acompte reçu au 9ᵉ jour donne `delay_days = 9` pendant que la dette vieillit depuis 261 jours.

Conséquence : **`delay_days !== null` ne veut pas dire « mois réglé »**. Tester le solde (`amount_invoiced <= amount_received`), jamais la présence d'un délai.

`LongestDelay::for()` a porté ce bug : il lisait `$declaration->delay_days ?? $this->openAge(...)`, et le `??` court-circuitait la branche « encours » exactement sur le cas le plus grave. L'ordre correct est l'inverse — l'encours d'abord, le délai en repli — et un test qui force `delay_days` à null pour un mois partiellement payé décrit un état que la production ne produit jamais : il passe par une porte dérobée et ne prouve rien.

Corollaire pour `PenaltyCalculator::total()` : décider du null sur `hasPenaltyClause()` de l'assureur, pas sur le retour de `for()`, qui rend aussi null pour un mois rejeté ou jamais déposé. Sinon un assureur sous convention affiche « — », qui se lit « pas de clause ».

## Le journal des pénalités lit plus large que la période
La vue « pénalité courue » ne peut pas filtrer sur le mois déclaré : une facture de janvier encore impayée court en septembre. `PenaltyLedgerWindow` lit donc, en plus des mois de la période, toute facture déposée avant sa fin et pas soldée avant son début ; `PenaltyTally` écarte ensuite les tranches hors période. Bornes : `< premier jour du mois suivant` et `>= premier jour`, jamais `<= dernier jour` (les dates remontent avec une heure).

`accruedInDays()` est la somme de `tranches()` : un seul algorithme pour le total et le journal. Ne pas y remettre une boucle propre.

`PenaltyTally` ne fait aucune requête et ne retient rien : il compte les officines derrière chaque mois et laisse la décision à l'appelant (closure `$withheld`).

## Pénalité : base fixe (montant facturé) depuis le 10/10/2026
Décision du client en recette, qui **remplace la base dégressive** de la spec lot A (docs/superpowers/specs/2026-09-19-penalites-de-retard-lot-a-design.md, l. 36 et 117) : chaque tranche de 30 jours vaut `intdiv(amount_invoiced × rate_bp, 10000)`, même après un acompte. Les versements n'agissent plus que sur la fin de la course : un mois soldé (`amount_received >= amount_invoiced`) s'arrête **le jour du solde**, sinon il court jusqu'à aujourd'hui. Ne pas réintroduire la déduction des versements.

Jour du solde (décision du 11/10/2026) : `PenaltyCalculator::paidOffDay()`, premier versement où le cumul atteint le montant facturé — **pas `paid_on`**, qui porte le versement le plus récent : un trop-perçu tardif ferait courir la pénalité jusqu'à lui. C'est la seule raison d'être du paramètre `$payments` de `tranches()` ; ne pas le retirer, et toujours le fournir (trié par date). `$paidDay` n'est que le repli sans versements.

La trace par facture (`App\Services\Pharmacy\PharmacyPenaltyTrace`, prop différée `penaltyTrace` du journal officine) passe par `tranches()` : ne pas lui donner sa propre boucle.

## « Pas de facture ce mois » vit dans nil_declarations, jamais dans declarations
Depuis le 15/10/2026, un assureur sans facture pour un mois se répond par une ligne `nil_declarations` (NilDeclaration), pas par une déclaration à 0 FCFA : celle-ci exigerait une date de dépôt et fausserait chaque agrégat qui compte ou moyenne les déclarations. La table ne sert qu'à la complétude : DeclarationCalendar (officine) et Network\DeclarationCompleteness (suivi admin) comptent tous deux sur `NilDeclaration::answeredMonths()` (UNION des deux tables, contrainte poussée dans chaque branche) — ne pas réécrire l'un sans l'autre. Aucune statistique ne lit cette table. Une vraie déclaration du même mois supprime la mention (DeclarationController::store(), dans la transaction) ; SaveNilDeclarationRequest refuse la mention si une facture existe. Piège : `Eloquent\Collection::only()` filtre par clé primaire du modèle, pas par clé de tableau.
