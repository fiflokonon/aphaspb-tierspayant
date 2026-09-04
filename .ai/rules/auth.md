---
paths:
  - 'app/Http/Controllers/Auth/**'
---

# Auth

## L'accès au tiers-payant se décide à /auth/callback, pas seulement sur les routes
Joomla authentifie tout le site de l'association ; seule une fraction de ses comptes a affaire au tiers-payant. `JoomlaCallbackController::mayEnter()` teste la gate `access-tierspayant` (admin ∪ pharmacie) sur les groupes du ticket signé, avant tout appel à `/api/me` et avant toute écriture : un compte refusé ne laisse ni shadow user ni session, et repart sur `route('auth.denied')`.

Ne pas retirer ce contrôle en se disant que les gates de routes suffisent : sans lui, un adhérent sans officine était connecté puis rejeté par un 403 nu sur `onboarding.profile`.

La page de refus est publique et sans layout (`resources/js/pages/auth/AccessDenied.vue`, `auth/` renvoie `null` dans le switch de `resources/js/app.ts` — le shell console n'aurait pas de compte à dessiner).

## La déconnexion sort vers Joomla, jamais vers la route login
`LogoutController` et le logout forcé de `VerifyJoomlaTokenVersion` redirigent vers `config('joomla.site_url')`. Ne pas « corriger » ça en `route('login')` ni en `/` : la racine renvoie un invité vers le handoff, et le handoff rouvre une session sans redemander de mot de passe tant que la session Joomla vit. Le bouton semblerait alors ne rien faire, et une session révoquée se rouvrirait aussitôt.

Le plugin `plg_system_aphaspbsso` n'expose aucune tâche de logout (il n'a que `handoff()`), et la déconnexion native de Joomla est protégée par un jeton CSRF que Laravel ne peut pas fabriquer. Donc quitter l'application est tout ce que « se déconnecter » peut faire aujourd'hui ; une vraie déconnexion unique demande d'ajouter une tâche au plugin, de le reconstruire et de le réinstaller en prod.

Verrouillé par `tests/Feature/Auth/LogoutTest.php` et `TokenVersionTest.php`.
