---
paths:
  - app/Http/Controllers/Pharmacy/DeclarationController.php
---

# Controllers Pharmacy

## Le choix de pénalité du formulaire n'agit que s'il change par rapport à ce que le formulaire montrait
Le formulaire repart pré-rempli sur la clôture en cours, et renvoie l'état qu'il a affiché dans le champ caché `penalty_settlement_shown` (validé `nullable|in:due,paid,waived`). `store()` n'applique le choix que s'il diffère de cet état **affiché**, pas de l'état en base au moment de l'envoi.

Deux raisons. Sans comparaison, un choix « payée » simplement redéposé reclôturerait aussitôt ce que la correction des versements du même envoi vient de lever (cas B de `ReconcilePenaltySettlement`). Et comparer à la base plutôt qu'à l'affiché ferait d'un formulaire périmé un geste : un « Due » resté ouvert pendant qu'un collègue clôt le mois sur l'écran assureur remettrait la pénalité en dû en silence (et un « Payée » périmé reclorait un mois remis en dû ailleurs).

Sans le champ caché (onglet ancien, API), repli sur l'état enregistré, lu **avant toute écriture** via `first(['penalty_settlement'])?->penalty_settlement->value` — surtout pas `->value()`, qui rendrait l'enum et ne serait jamais `===` à la chaîne du choix.
