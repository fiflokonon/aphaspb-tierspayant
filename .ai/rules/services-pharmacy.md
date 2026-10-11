---
paths:
  - 'app/Services/Pharmacy/**'
---

# Services Pharmacy

## Export du journal officine = trace par facture, à la date du jour
Depuis le 11/10/2026 (recette), les exports CSV/XLSX/PDF du journal des pénalités officine sont la trace par facture, pas le découpage mensuel : le fichier se joint à une réclamation et doit se lire seul. Source unique : PharmacyPenaltyTrace. `PharmacyPenaltyTraceRows` = une ligne par événement + une ligne « Pénalité exigible » par facture (seule à remplir `penalite_exigible`, pour sommer sans doublon) ; ses title()/detail()/status()/dueDetail() sont aussi les libellés du PDF (`exports/penalty-ledger-pharmacy`). Le fichier suit le filtre assureur mais ignore la période (elle ne borne que la courbe) ; nom `{slug}-penalites-au-{Y-m-d}`. L'ancien PharmacyPenaltyLedgerRows mensuel est supprimé ; l'export réseau reste mensuel. Les libellés de l'écran (PenaltyTrace.vue) doublent ceux de PHP : les changer des deux côtés.
