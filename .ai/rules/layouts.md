---
paths:
  - 'resources/js/layouts/**'
---

# Layouts

## La barre console doit rester lg:h-screen lg:sticky
`ConsoleSidebar.vue` porte `lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto`. Ne pas les retirer.

Sans hauteur propre, l'`<aside>` est un enfant flex de `min-h-screen lg:flex-row` : il s'étire à la hauteur du **document**, et le `mt-auto` de `ConsoleAccountFooter` colle alors l'identité et « Se déconnecter » au bas de la page, pas au bas de la fenêtre. Sur le tableau de bord, le pied se retrouvait 600 px sous la ligne de flottaison — la déconnexion existait dans le DOM mais était introuvable.

Il n'y a pas de runner JS dans le projet : cette régression ne peut être vue qu'au navigateur. `php artisan serve` puis `/dev/login/officine` en local, et regarder le bas de la barre sur le tableau de bord.

## .app-shell doit passer en colonne sous 1024 px, et le halo doit y disparaître
Deux défauts distincts rendaient **toutes** les pages de la console inutilisables sous 1024 px. Vérifiés au navigateur : page blanche à 1023, parfaite à 1024.

**1. Le contenu partait hors écran.** `ConsoleLayout.app-shell` est `display: flex` sans jamais de `flex-direction`, donc en rangée à toute largeur. Sous 1024, `ConsoleSidebar.apha-sidebar` passe en `width: 100%` mais **garde le `flex-shrink: 0`** de sa règle de base : elle réclame toute la rangée sans céder, et `.app-content` — en `flex: 1`, donc `flex-basis: 0%` — est écrasé à largeur zéro. Ses enfants à largeur minimale (`DataTable` a `min-w-[720px]`) débordent alors hors du viewport. Symptôme : page blanche avec barre de défilement horizontale ; le contenu existe dans le DOM, il commence juste après la barre. Correctif : `@media (max-width: 1023px) { .app-shell { flex-direction: column } }`.

**2. Une barre horizontale résiduelle sur chaque écran.** `.apha-sidebar` porte `overflow: hidden` dans sa règle de base, mais le bloc `@media (max-width: 1023px)` le repasse à `visible`. Or la barre contient `.sidebar-glow`, halo décoratif en `right: -80px; width: 160px`, calibré pour le rail vertical de 212 px du bureau. Sans confinement, il déborde de 80 px. Correctif : `display: none` sur `.sidebar-glow` sous 1024.

**Aucun test ne peut voir ces régressions** — le projet n'a pas de runner qui monte des composants, Vitest est en environnement `node` pour la logique pure. Se vérifie au navigateur : `php artisan serve`, `/dev/login/officine`, puis une fenêtre à 1023 px et une à 1024 px. Un détecteur de barre de défilement par moyenne de pixels donne des faux positifs sur les bandeaux sombres — découper la bande du bas et la regarder.
