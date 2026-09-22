---
paths:
  - 'resources/js/layouts/console/**'
---

# Console

## Les actions de compte vivent dans ConsoleAccountMenu, et nulle part ailleurs
Un seul composant porte le nom de la personne, le changement d'officine et la déconnexion : `ConsoleAccountMenu.vue`. `ConsoleTopBar` le monte à partir de lg, l'en-tête de `ConsoleSidebar` en dessous — mêmes actions, deux emplacements exclusifs.

Ne pas rouvrir un second emplacement. `ConsoleAccountFooter.vue` (pied du rail) a été supprimé le 22/09/2026 pour cette raison : il disparaissait sous 1024 px, et sur bureau on avait le nom en haut à droite et la déconnexion en bas à gauche pour le même compte.

Le pied du rail ne garde que « Replier » et l'état de la plateforme.

L'encart « INFORMATIONS » du rail a été retiré le même jour, avec toute sa chaîne : `console.notices` dans le payload, `ConsoleSidebarNotice.vue`, `ConsoleNavigation::chaseNotice()` et `anonymityNotice()`. Si une info de ce genre revient, ne pas la remettre dans le rail sans décider d'abord où elle vit à toutes les largeurs — le rail est masqué sous 1024 px.

La même règle vaut pour `ConsoleSpaceChip.vue`, qui dit l'espace ouvert (officine ou réseau) : bandeau supérieur à partir de lg, bandeau vert de l'en-tête en dessous. Il lit le shell lui-même (`useConsoleShell`), sans prop — voir .ai/rules/layouts.md.
