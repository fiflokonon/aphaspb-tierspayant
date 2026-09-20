---
paths:
  - 'resources/js/pages/pharmacy/**'
---

# Pages Pharmacy

## Trois horloges de retard cohabitent — ne pas en inventer une quatrième
Le même retard se compte depuis deux dates différentes selon l'écran, et c'est assumé :

- **depuis le dépôt de facture** : `Declaration::delay_days`, `OverdueLine::ageDays`, `LongestDelay`, `PenaltyCalculator` ;
- **depuis la fin du mois déclaré** : `PharmacyStatsService::outstandingByMonth()` (tranches d'ancienneté) et `ConsoleNavigation::chaseNotice()` (« au-delà de 60 jours »).

C'est pourquoi le bandeau du tableau de bord **nomme la pire ligne** au lieu de compter au-delà d'un seuil : un « dont N au-delà de 90 j » à côté du « au-delà de 60 j » de la barre latérale ferait deux chiffres contradictoires pour la même facture.

La table des factures en retard affiche le **dépassement** (`ageDays − standardDelayDays`), jamais l'âge brut : chaque assureur a son propre délai, et « 120 jours » ne veut rien dire sans lui.

Côté montants, utiliser `formatAmount()` de `lib/fcfa.ts`, jamais `formatFcfa()` nu : ce dernier rend une **chaîne vide** pour 0 et les négatifs, ce qui transforme « rien encaissé » en cellule muette. `formatAmount()` rend « 0 » sur zéro et « — » sur null — et null veut dire « pas de clause de pénalité », pas « rien à réclamer ».
