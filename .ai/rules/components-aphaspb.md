---
paths:
  - 'resources/js/pages/pharmacy/Dashboard.vue, resources/js/components/aphaspb/KpiCard.vue'
---

# Components Aphaspb

## Tableau de bord : rouge plein assumé, chiffres à deux emplacements
**Le rouge plein des bandes de retard est une décision du client**, prise contre l'avis consigné dans la spec (trois blocs de force égale ne se distinguent plus). Ne pas la « corriger ». Le blanc sur `--terracotta` mesure **4,82:1** — au-dessus du seuil AA sans marge : ne pas éclaircir cette teinte sans recalculer.

La bande « dans le délai » reste **diluée**, choix explicite : le plein est le signal « en faute », et un assureur dans son délai ne l'est pas.

**Piège rencontré :** une règle `.insurer-banner.late .insurer-banner-days` existait plus bas dans le fichier et écrivait le retard en terracotta. À spécificité égale, la dernière gagne — « +234 j » sortait en rouge sur rouge, invisible. Corriger la règle **en place** plutôt que d'en empiler une seconde.

**Les chiffres clés sont rendus deux fois**, dans `DashboardKpis`, chacun masqué à la largeur de l'autre par `display: none` (qui retire aussi de l'arbre d'accessibilité). L'ordre approuvé diffère : grand écran → titre, bandes, chiffres ; mobile → bandeau portant titre *et* chiffres, puis bandes. Le slot `#hero` ne rend qu'à un endroit, d'où les deux emplacements. Dans le bandeau les trois tiennent sur **une rangée** même à 390 px — empilés ils faisaient 450 px et repoussaient les bandes hors de l'écran, le défaut même que le bandeau existe pour éviter.

**`KpiCard` a deux props de couleur distinctes** : `tone` (neutre / bon / alerte — la lecture sémantique de la valeur) et `surface` (`light` / `band` — le fond sur lequel la carte est posée). Ne pas les confondre. Les tailles compactes du bandeau vivent dans `KpiCard`, pilotées par `surface` — un `:deep()` sur ses classes utilitaires Tailwind casserait au premier changement de classe.

**Un test de suppression de panneau ne peut pas fonctionner ici** : `phpunit.xml` désactive le SSR, la réponse ne contient donc jamais le rendu du template. Le test écrit au plan a été retiré plutôt que gardé inopérant.

`PaletteSourceTest` interdit désormais tout **littéral** de couleur dans ce fichier — pas seulement la redéclaration d'un token.
