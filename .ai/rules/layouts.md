---
paths:
  - 'resources/js/layouts/**'
---

# Layouts

## La barre console doit rester lg:h-screen lg:sticky
`ConsoleSidebar.vue` porte `lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto`. Ne pas les retirer.

Sans hauteur propre, l'`<aside>` est un enfant flex de `min-h-screen lg:flex-row` : il s'étire à la hauteur du **document**, et son pied — poussé en bas par le `flex: 1 1 auto` de `.apha-navigation` — colle au bas de la page, pas au bas de la fenêtre. Sur le tableau de bord, il se retrouvait 600 px sous la ligne de flottaison : le bouton « Replier » et l'état de la plateforme existaient dans le DOM mais étaient introuvables.

Constaté à l'époque sur « Se déconnecter », qui vivait dans ce pied via `ConsoleAccountFooter` (composant supprimé le 22/09/2026, voir la règle sur le menu de compte). Le symptôme a changé de gravité, pas de cause.

Il n'y a pas de runner JS dans le projet : cette régression ne peut être vue qu'au navigateur. `php artisan serve` puis `/dev/login/officine` en local, et regarder le bas de la barre sur le tableau de bord.

## .app-shell doit passer en colonne sous 1024 px, et le halo doit y disparaître
Deux défauts distincts rendaient **toutes** les pages de la console inutilisables sous 1024 px. Vérifiés au navigateur : page blanche à 1023, parfaite à 1024.

**1. Le contenu partait hors écran.** `ConsoleLayout.app-shell` est `display: flex` sans jamais de `flex-direction`, donc en rangée à toute largeur. Sous 1024, `ConsoleSidebar.apha-sidebar` passe en `width: 100%` mais **garde le `flex-shrink: 0`** de sa règle de base : elle réclame toute la rangée sans céder, et `.app-content` — en `flex: 1`, donc `flex-basis: 0%` — est écrasé à largeur zéro. Ses enfants à largeur minimale (`DataTable` a `min-w-[720px]`) débordent alors hors du viewport. Symptôme : page blanche avec barre de défilement horizontale ; le contenu existe dans le DOM, il commence juste après la barre. Correctif : `@media (max-width: 1023px) { .app-shell { flex-direction: column } }`.

**2. Une barre horizontale résiduelle sur chaque écran.** `.apha-sidebar` porte `overflow: hidden` dans sa règle de base, mais le bloc `@media (max-width: 1023px)` le repasse à `visible`. Or la barre contient `.sidebar-glow`, halo décoratif en `right: -80px; width: 160px`, calibré pour le rail vertical de 212 px du bureau. Sans confinement, il déborde de 80 px. Correctif : `display: none` sur `.sidebar-glow` sous 1024.

**Aucun test ne peut voir ces régressions** — le projet n'a pas de runner qui monte des composants, Vitest est en environnement `node` pour la logique pure. Se vérifie au navigateur : `php artisan serve`, `/dev/login/officine`, puis une fenêtre à 1023 px et une à 1024 px. Un détecteur de barre de défilement par moyenne de pixels donne des faux positifs sur les bandeaux sombres — découper la bande du bas et la regarder.

## L'espace se dit une fois, dans la coquille — pas au-dessus de chaque titre
`ConsoleSpaceChip.vue` porte l'espace où la session est ouverte : le nom de l'officine et sa ville, ou « Espace administrateur · Réseau des officines ». `ConsoleTopBar` le monte à partir de lg, l'en-tête de l'écran le rend dans le bandeau vert en dessous (`.header-space`, `display: none` au-dessus de 1023.98px). Deux emplacements exclusifs, un composant — même règle que le menu de compte.

`ConsoleHeader` ne garde que son titre serif, plus l'`eyebrow` propre à l'écran (« MON COMPTE », « CE QUI VOUS ATTEND ») quand il y en a un. Ne pas y réintroduire l'espace : il a occupé trois lignes et trois polices — mono capitales, sans capitales, serif — au-dessus de chaque titre, pour dire une chose qui ne change pas d'un écran à l'autre. Les écrans réseau ne passent plus d'`eyebrow` depuis le 22/09/2026, pour la même raison : « RÉSEAU DES OFFICINES · BÉNIN » redisait la puce.

Le nom est en capitales par `text-transform`, jamais par la chaîne : la casse saisie doit survivre pour un lecteur d'écran et pour un copier-coller. 14,5px/700, comme le déclencheur du menu de compte à côté duquel la puce se pose.

Contrastes mesurés, à respecter si on retouche les opacités (AA = 4,5:1) : sur --cream, --ink à 55% ne donne que 3,84:1 — d'où 80% pour le nom et 62% (4,79:1) pour le complément ; sur --officine-dark, blanc 0.92 = 7,27:1 et 0.72 = 5,14:1.

Deux comportements de largeur, à ne pas uniformiser :
- **bandeau supérieur** — la puce est en flex, le nom porte une ellipse, et c'est elle qui rétrécit la première (`lg:shrink-0` sur le déclencheur de compte). Le nom d'une personne est court et stable, celui d'une officine est long.
- **bandeau vert** — la puce passe en `display: block` et coule comme du texte : pas de fond (deux surfaces emboîtées sur 343px font un timbre dans un cadre), et un nom long passe à la ligne au lieu d'être coupé. En flex, la ville restait accrochée à la première ligne pendant que le nom se poursuivait sous elle.
