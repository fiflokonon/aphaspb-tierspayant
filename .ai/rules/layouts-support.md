---
paths:
  - 'resources/js/layouts/**, app/Support/ConsoleNavigation.php'
---

# Layouts Support

## La coquille : icône côté serveur, repli, et trois pièges CSS qui ont mordu
**L'icône de navigation vient du serveur.** `ConsoleNavigation` pose une clé `icon` dans chaque `NavItem` ; `resources/js/lib/navIcons.ts` la traduit en composant Lucide. Pas de table indexée sur le libellé : les libellés de ce projet ont déjà été reformulés. Une clé inconnue rend `null`, jamais une icône par défaut.

`NavIconCoverageTest` confronte les deux listes **en lisant les sources** — seul moyen de couverture, Vitest tournant en environnement node sans monter de composant. Sa regex ne lit **que le corps de l'objet `ICONS`, commentaires retirés** : sur le fichier entier, une entrée mise en commentaire pendant un débogage la faisait passer au vert.

**Le repli de la barre** vit dans `sidebarCollapsed.ts` (`try/catch`, `localStorage` lève en navigation privée) et **n'existe pas sous 1024 px**, où la barre est déjà horizontale.

**Trois pièges rencontrés, chacun a causé un défaut réel :**

1. **SSR et classe dynamique.** `INERTIA_SSR_ENABLED` vaut `true` par défaut. Lire `localStorage` au `setup` donne `false` côté serveur, et **Vue ne corrige pas une classe divergente à l'hydratation** — patchFlag `CLASS` sans `dynamicProps`, boucle de props court-circuitée en production. Toute préférence d'affichage persistée doit être lue dans `onMounted`.
2. **Une infobulle en `::after` ne sort pas d'un conteneur qui défile.** `.apha-sidebar` et `.apha-navigation` sont en `overflow: hidden`, `.apha-nav` défile : le pseudo-élément était découpé et invisible. Le libellé du rail passe par `title` + `aria-label` natifs.
3. **Ne jamais écrire `display: revert`** pour une propriété que la même feuille déclare : `revert` remonte au-delà de tout l'origine auteur et rend la valeur du navigateur. `revert-layer` sans couches a le même défaut. Poser la valeur explicitement.

**Et un quatrième :** `:deep()` sur une classe utilitaire Tailwind attrape tout enfant qui l'emploie. `:deep(.w-full)` visait le bouton de déconnexion et attrapait aussi les liens de changement d'officine, réduits à des pastilles sans libellé qui changeaient pourtant d'officine au clic. Viser `[data-test='logout-button']`, stable, plutôt qu'un utilitaire.

`ConsoleHeader` a **deux formes autour de 1024 px** — en-tête clair au-dessus, bandeau `--officine-dark` en dessous — et un slot `#hero` gardé par `v-if="$slots.hero"` dans le template : un `computed` sur `useSlots()` ne se réévalue pas, l'objet étant muté sur place. Seul le tableau de bord le remplira.
