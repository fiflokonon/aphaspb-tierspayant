---
paths:
  - 'app/Services/Exports/**'
---

# Exports

## Trois formats, deux périmètres : ne jamais fusionner l'export réseau et l'export officine
CSV, XLSX et PDF existent des deux côtés, mais les sources de lignes restent **deux classes distinctes** :

- `NetworkExportRows` — agrégats, ne nomme jamais une officine, retient tout assureur sous le seuil d'anonymat (ligne conservée avec une explication, jamais supprimée).
- `PharmacyExportRows` — une officine nommée, ne retient rien, **note privée incluse**. C'est légitime : la note repart vers celui qui l'a écrite. Elle ne doit apparaître dans aucun export réseau.

Les fusionner mettrait la règle de confidentialité derrière un `if`. Seuls les *rendus* sont partagés : `Exports\CsvRenderer` (point-virgule, décimales à la virgule, BOM UTF-8 posé par le contrôleur) et `Exports\XlsxWriter` (cellules typées).

PDF via `barryvdh/laravel-dompdf`, vues dans `resources/views/exports/`. Contraintes dompdf : ni flexbox ni grid — les colonnes sont des `<table>` —, `position: fixed` pour l'en-tête et le pied répétés, `page-break-inside: avoid` sur les lignes, et `content: counter(page)` pour la numérotation. Les binaires (xlsx, pdf) passent par un fichier temporaire + `deleteFileAfterSend()` : les writers d'OpenSpout et de dompdf posent leurs propres en-têtes et se battent avec `streamDownload()`.

Dans les tests, référencer une colonne par son nom (`array_search(...)` sur `COLUMNS`), jamais par son index : chaque colonne ajoutée cassait sinon trois assertions sans rapport.
