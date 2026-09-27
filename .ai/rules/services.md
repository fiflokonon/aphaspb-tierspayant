---
paths:
  - 'app/Services/**'
---

# Services

## Courue, close, due
Trois mots, trois chiffres distincts à ne pas confondre dans les exports et agrégats :

- `penalite_fcfa` (export officine) = **courue**, sens inchangé.
- `penalite_potentielle_fcfa` (export réseau) = **due**. Nouvelles colonnes réseau `penalite_recouvree_fcfa`, `penalite_abandonnee_fcfa` ; nouvelles colonnes officine `penalite_statut` (null sans clause, sinon `due`/`payee`/`annulee`), `penalite_close_fcfa`, `penalite_close_le`, `penalite_due_fcfa`.
- `PenaltyCalculator::due()` rend 0 pour une déclaration close : le montant clos égale la courue par construction (garanti par `ReconcilePenaltySettlement`).

`InsurerPenaltyAggregates` ajoute `penalty_settled_amount` à recouvrée/abandonnée **sans** recalculer les tranches. Le journal (`PenaltyTally`), lui, recalcule les tranches des factures closes et les répartit en `accruedPaid`/`accruedWaived`/`accruedDue` : les deux chemins ne s'accordent que grâce à l'invariant de réconciliation.

Un décor de test qui clôt un mois doit utiliser un mois couvert dont le montant clos égale sa pénalité courue — sinon la réconciliation le lève à la prochaine sauvegarde.
