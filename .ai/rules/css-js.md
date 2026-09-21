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

## Le socle visuel : surfaces, échelle, et une garde sans liste
Quatre familles de bordure : surface (une carte/panneau — perd son contour au profit de var(--surface-shadow) / var(--surface-shadow-raised) au survol), contrôle (input, bouton — garde la sienne), jeton (dessin volontaire, ex. anneau de focus) et décor (séparateur interne, ex. border-b/border-t à l'intérieur d'une surface). Seule la première perd son contour ; ne pas confondre un séparateur interne avec le contour extérieur.

Rayons : --radius-card (12px, carte), --radius-band (11px, bande d'alerte), --radius-nav (9px, élément de navigation). Ombres : --surface-shadow et --surface-shadow-raised (survol) — jamais recalculées à la main (color-mix, box-shadow littéral) : toujours var(--surface-shadow...).

Échelle typographique : --text-label (9.5px, avec tracking-[0.14em], réservé aux étiquettes capitales) et --text-meta (12.5px, tout le reste sous 10px qui n'est pas une étiquette). Le serif (--font-serif) sert au titre d'écran et aux chiffres clés, jamais avec font-bold. Seuil responsive toujours écrit en 1023.98px (max-width).

`:deep()` interdit sur un utilitaire Tailwind (ex. :deep(.p-4)) : sur-cible et casse au premier changement de classe côté enfant. Cibler structurellement (data-*, élément, classe propre au composant) — et vérifier dans le template ce que la règle atteint réellement avant de la garder.

tests/Feature/Design/PaletteSourceTest.php (test 'a cleaned file writes no colour literal') couvre désormais tout resources/js sans liste d'exceptions : un littéral hex/rgba nouveau dans un bloc <style> y fait rougir la suite où qu'il soit dans l'arbre. Ne pas réintroduire de liste de fichiers « couverts ».
