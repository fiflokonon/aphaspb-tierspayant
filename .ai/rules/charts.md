---
paths:
  - 'resources/js/components/aphaspb/charts/**'
---

# Charts

## vue-tsc ne valide pas les props d'unovis : vérifier dans les typings
`npm run types:check` laisse passer n'importe quel nom de prop sur un composant `Vis*`. Contrôle négatif fait le 31/08/2026 : `:bogus-prop="42"` ajouté sur `VisDonut` → vue-tsc sort sans une erreur. Une prop mal orthographiée (`arc-width` écrit `arcwidth`, `line-dash-array` en `dash-array`) est donc silencieusement ignorée à l'exécution, et le graphique se dessine faux sans que rien ne le signale — ni eslint, ni prettier, ni les tests Pest, qui n'exécutent aucun JS.

Avant de committer un nouveau composant de graphique, confronter chaque prop à la config de la librairie :
`grep -oE "^\s+[a-zA-Z]+[?]?:" node_modules/@unovis/ts/components/<composant>/config.d.ts`

Les props `x` / `y` / `color` viennent de la config XY de base, pas du fichier du composant.
