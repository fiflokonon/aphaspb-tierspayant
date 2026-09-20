---
paths:
  - 'app/Services/Declarations/**'
---

# Services Declarations

## La pénalité n'a pas de colonne de cache, et c'est voulu
`delay_days` est stocké parce qu'il ne dépend que des données saisies : deux dates entrent, un entier sort, et le hook `saving` le régénère. La pénalité, elle, **croît toute seule** : un mois jamais réglé voit la sienne augmenter tous les 30 jours sans qu'aucune écriture ne survienne. Une colonne exigerait un job quotidien, et le chiffre serait faux entre deux passages.

Conséquence à connaître avant d'optimiser : `PenaltyCalculator` ne se somme pas en SQL. Il lui faut les versements de chaque déclaration, donc `with('payments')` ou un `whereIn` groupé. À l'échelle d'une officine c'est une centaine de lignes ; **à l'échelle du réseau (lot B) il faudra une autre stratégie**.

Deux portes d'entrée sur un seul algorithme : `for(Declaration)` pour du code qui hydrate de l'Eloquent, `accrued(...)` sur valeurs nues pour `OverduePaymentsService`, qui lit en query builder et ne doit jamais charger `private_note`.

Le `break` sur base nulle est une **optimisation, pas une règle** : une base retombée à zéro ne remonte jamais, donc `break` et `continue` donnent le même total. Aucun test ne peut les distinguer — ne pas en écrire un qui prétendrait le faire.

## paid_on existe dès le premier versement — ne pas le lire comme « mois réglé »
`syncFromInstalments()` pose `paid_on` sur le **versement le plus récent**, y compris quand il ne solde rien. Le hook `saving` en tire donc un `delay_days` pour tout mois seulement *entamé* : un acompte reçu au 9ᵉ jour donne `delay_days = 9` pendant que la dette vieillit depuis 261 jours.

Conséquence : **`delay_days !== null` ne veut pas dire « mois réglé »**. Tester le solde (`amount_invoiced <= amount_received`), jamais la présence d'un délai.

`LongestDelay::for()` a porté ce bug : il lisait `$declaration->delay_days ?? $this->openAge(...)`, et le `??` court-circuitait la branche « encours » exactement sur le cas le plus grave. L'ordre correct est l'inverse — l'encours d'abord, le délai en repli — et un test qui force `delay_days` à null pour un mois partiellement payé décrit un état que la production ne produit jamais : il passe par une porte dérobée et ne prouve rien.

Corollaire pour `PenaltyCalculator::total()` : décider du null sur `hasPenaltyClause()` de l'assureur, pas sur le retour de `for()`, qui rend aussi null pour un mois rejeté ou jamais déposé. Sinon un assureur sous convention affiche « — », qui se lit « pas de clause ».
