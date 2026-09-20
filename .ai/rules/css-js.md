---
paths:
  - 'resources/css/**, resources/js/**'
---

# Css Js

## La couleur a une source unique : app.css
**Onze** fichiers portaient chacun une copie de la même palette **turquoise** (`#008f83`), concurrente du vert officine du thème. Sept sous les noms `--apha-*` ; quatre — `ConsoleSidebar`, `pharmacy/Dashboard`, `pharmacy/Declare`, `pharmacy/Insurer` — sous les noms **du thème** (`--primary`, `--ink`, `--gold`, `--border`), ce qui est pire : ces noms servent tout le système de design. Toutes supprimées le 20/09/2026.

`tests/Feature/Design/PaletteSourceTest.php` interdit la douzième, sur les deux familles de noms. Vérifié par mutation.

**Piège à connaître avant de « finir le ménage » :** `--muted` et `--light` restent déclarés localement dans ces quatre fichiers, et c'est délibéré. Les pages les emploient comme **couleurs de texte** (`#788585`) quand `:root` réserve `--muted` à une **surface** (`#faf8f3`, presque blanche). Les supprimer rend le texte secondaire illisible. Le conflit se règle par un renommage, pas par une suppression.

Les alias `--apha-*` dans `:root` sont **transitoires** : ils pointent sur les tokens du thème pour que les 338 usages existants survivent, et disparaîtront quand les lots 3 et 4 auront renommé leurs usages.

**Aucun dégradé ne subsiste dans `resources/`.** Six motifs ont couvert les 81 occurrences : halo décoratif → déclaration supprimée ; faux-blanc → `#fff` ; fond de bouton → `var(--primary)` ; liseré multicolore → `var(--primary)` ; barre de progression → `var(--gold-mid)` ; dégradé d'une seule teinte à opacité variable → l'aplat de sa teinte la plus forte.

Reste **128 occurrences de turquoise écrit en dur** dans les pages, traitées aux lots 3 et 4.

Le bloc `[data-sidebar='sidebar']` d'`app.css` cible les composants shadcn `ui/sidebar`, qui existent mais **ne sont importés nulle part** : c'est du CSS mort, candidat à suppression.
