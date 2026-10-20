---
paths:
  - app/Http/Requests/Pharmacy/SaveDeclarationRequest.php
---

# Pharmacy

## Un montant reçu exige sa date de paiement — décision réaffirmée, ne pas la relâcher
Si `resolvedStatus()` est `paid` ou `partial`, `paid_on` est obligatoire (`withValidator()` → « Indiquez la date de paiement. »). La règle inverse tient aussi : une date de paiement sans montant reçu est refusée.

La question a été rouverte le 08/09/2026 — rendre `paid_on` facultatif pour laisser l'officine le compléter plus tard — puis **tranchée dans l'autre sens** : l'obligation reste. Ne pas la relâcher sans redemander.

Raison technique de la garder : `delay_days` naît de la paire de dates, et tous les agrégats de délai (`NetworkStatsService`, `PharmacyStatsService`) filtrent sur le seul statut. Une déclaration encaissée sans date compterait donc comme un paiement le jour même — moyennes tirées vers zéro, `withinThresholdShare` en chute, point à 0 jour sur `delayTrend()`. Relâcher la validation impose de reprendre ces agrégats en même temps (filtrer aussi sur `delay_days IS NOT NULL`, dénominateurs compris).

Côté écran, `pharmacy/Declare` passe `required` au `DateField` de `paid_on` — le champ n'est affiché que si un montant a été reçu, soit exactement le cas où le serveur l'exige. Le dépôt de facture, lui, ne s'appuie encore que sur la validation serveur.

Verrouillé par `tests/Feature/Pharmacy/DeclarationTest.php`, contrôle négatif vérifié.

## Montant facturé à 0 = « pas de facture », sauf mois déjà déclaré
Depuis le 15/10/2026, `isNilDeclaration()` (montant 0, aucun versement, statut non rejeté, aucune déclaration existante pour ce mois) détourne l'envoi vers `RecordNilDeclarations` : aucune ligne `declarations` à 0, et la date de dépôt n'est pas exigée (`requiredIf`). Un mois déjà déclaré passé à 0 est refusé : basculer supprimerait la déclaration et son historique de révisions. La case `remaining_without_invoice` (jamais cochée par défaut, pour qu'un oubli reste visible chez l'administrateur) marque ensuite les assureurs restants du mois. Attention : `AmountField` poste toujours « 0 » quand le champ est vide, donc un formulaire envoyé sans saisie devient un « pas de facture ». Le bouton affiche alors « Enregistrer : pas de facture ».
