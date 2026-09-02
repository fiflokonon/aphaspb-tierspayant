---
paths:
  - 'app/Http/Controllers/Auth/**'
---

# Auth

## L'accès au tiers-payant se décide à /auth/callback, pas seulement sur les routes
Joomla authentifie tout le site de l'association ; seule une fraction de ses comptes a affaire au tiers-payant. `JoomlaCallbackController::mayEnter()` teste la gate `access-tierspayant` (admin ∪ pharmacie) sur les groupes du ticket signé, avant tout appel à `/api/me` et avant toute écriture : un compte refusé ne laisse ni shadow user ni session, et repart sur `route('auth.denied')`.

Ne pas retirer ce contrôle en se disant que les gates de routes suffisent : sans lui, un adhérent sans officine était connecté puis rejeté par un 403 nu sur `onboarding.profile`.

La page de refus est publique et sans layout (`resources/js/pages/auth/AccessDenied.vue`, `auth/` renvoie `null` dans le switch de `resources/js/app.ts` — le shell console n'aurait pas de compte à dessiner).
