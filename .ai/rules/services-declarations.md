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
