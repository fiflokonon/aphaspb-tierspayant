---
paths:
  - 'resources/js/layouts/**'
---

# Layouts

## La barre console doit rester lg:h-screen lg:sticky
`ConsoleSidebar.vue` porte `lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto`. Ne pas les retirer.

Sans hauteur propre, l'`<aside>` est un enfant flex de `min-h-screen lg:flex-row` : il s'étire à la hauteur du **document**, et le `mt-auto` de `ConsoleAccountFooter` colle alors l'identité et « Se déconnecter » au bas de la page, pas au bas de la fenêtre. Sur le tableau de bord, le pied se retrouvait 600 px sous la ligne de flottaison — la déconnexion existait dans le DOM mais était introuvable.

Il n'y a pas de runner JS dans le projet : cette régression ne peut être vue qu'au navigateur. `php artisan serve` puis `/dev/login/officine` en local, et regarder le bas de la barre sur le tableau de bord.
