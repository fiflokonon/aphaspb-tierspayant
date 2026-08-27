---
paths:
  - 'resources/js/**'
---

# Js

## Ne pas lancer `artisan wayfinder:generate` seul : il casse les variantes .form
`vite.config.ts` configure Wayfinder avec `formVariants: true`. La commande `php artisan wayfinder:generate` ne lit pas cette option — elle vit côté Vite — donc elle régénère sans les variantes et écrase la sortie du plugin. Résultat : cinq appels `.form()` dans les composants d'officines et de profil cessent de compiler, et `npm run types:check` échoue avec `Property 'form' does not exist`.

Après toute modification de routes, régénérer avec **`npm run build`** (ou `npm run dev`), pas avec la commande artisan. Si `types:check` signale `Property 'form' does not exist`, c'est ce symptôme : relancer `npm run build`.

Autre piège lié : les pages Inertia sont chargées par chemin (`resources/js/pages/{component}.vue`) et résolues via le manifeste Vite. Renommer un dossier de pages sans relancer `npm run build` fait échouer les tests avec `ViteException: Unable to locate file in Vite manifest`.
