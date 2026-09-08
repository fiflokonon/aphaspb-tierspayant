---
paths:
  - routes/web.php
---

# Routes

## Ne pas nommer une route « exports » : Wayfinder génère `export const exports`
Wayfinder dérive le nom du symbole TypeScript du dernier segment du nom de route. Une route nommée `pharmacy.exports` produit `export const exports = ...` suivi de `exports.url = ...` ; `exports` étant aussi le global CommonJS, vue-tsc abandonne l'inférence expando et sort quatre `TS2339: Property 'url' does not exist` / `Property 'form' does not exist` dans un fichier **généré**, donc à cent lieues de la ligne fautive.

Le symptôme est identique à celui du piège Wayfinder déjà documenté (`artisan wayfinder:generate` lancé seul) : ne pas s'y tromper, ici `npm run build` ne répare rien. La route a été renommée `pharmacy.data-exports` le 08/09/2026.

Éviter de même tout nom dont le dernier segment est un mot réservé JS ou un global d'ambiance : `export`, `import`, `default`, `module`, `require`, `window`, `document`.
