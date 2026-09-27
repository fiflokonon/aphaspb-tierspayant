---
paths:
  - app/Http/Controllers/Pharmacy/DeclarationController.php
---

# Controllers Pharmacy

## Le choix de pénalité du formulaire n'agit que s'il change
Le formulaire repart pré-rempli sur la clôture en cours. `store()` lit donc l'état d'avant l'enregistrement (`$before`, via `first(['penalty_settlement'])?->penalty_settlement->value` — surtout pas `->value()`, qui rendrait l'enum et ne serait jamais `===` à la chaîne du choix) **avant toute écriture**, puis n'applique le choix du formulaire que s'il diffère de cet état.

Sans cette comparaison, un choix « payée » simplement redéposé reclôturerait aussitôt ce que la correction des versements du même envoi vient de lever (cas B de `ReconcilePenaltySettlement`).
