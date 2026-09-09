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
