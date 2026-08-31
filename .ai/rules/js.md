---
paths:
  - 'resources/js/**'
---

# Js

## Ne pas lancer `artisan wayfinder:generate` seul : il casse les variantes .form
`vite.config.ts` configure Wayfinder avec `formVariants: true`. La commande `php artisan wayfinder:generate` ne lit pas cette option — elle vit côté Vite — donc elle régénère sans les variantes et écrase la sortie du plugin. Résultat : cinq appels `.form()` dans les composants d'officines et de profil cessent de compiler, et `npm run types:check` échoue avec `Property 'form' does not exist`.

Après toute modification de routes, régénérer avec **`npm run build`** (ou `npm run dev`), pas avec la commande artisan. Si `types:check` signale `Property 'form' does not exist`, c'est ce symptôme : relancer `npm run build`.

Autre piège lié : les pages Inertia sont chargées par chemin (`resources/js/pages/{component}.vue`) et résolues via le manifeste Vite. Renommer un dossier de pages sans relancer `npm run build` fait échouer les tests avec `ViteException: Unable to locate file in Vite manifest`.

## Les icônes doivent être des SVG lucide, jamais des caractères Unicode
La police de l'app est `Plus Jakarta Sans` (`--font-sans`, resources/css/app.css), qui ne couvre pas le bloc **Geometric Shapes** (U+25A0–U+25FF). Le repli `ui-sans-serif, system-ui` ne le couvre pas partout non plus : le navigateur ne dessine alors **rien du tout** — pas même un carré tofu.

Constaté le 31/08/2026 : la cloche du `ConsoleTopBar` s'affichait comme un bouton vide avec `◔` (U+25D4). Le markup et les classes partaient pourtant intacts dans le bundle ; seul le caractère était introuvable dans la police.

Rien ne détecte ça : ni eslint, ni prettier, ni vue-tsc, ni Pest — le projet n'a pas de runner JS et le symptôme n'apparaît qu'au navigateur.

**Règle : pour une icône, importer un composant de `@lucide/vue`** (déjà une dépendance, utilisée dans `components/ui/`), jamais un caractère. Vérifier ensuite que le tracé part bien dans le build :

```
grep -l "<fragment du tracé>" public/build/assets/*.js
```

Des glyphes du même bloc subsistent dans du code plus ancien et sont probablement invisibles eux aussi — `◷` (7 emplacements), `◉`, `◆`, `◈`, `◌`, `◐`, `▾`. Les convertir quand on touche l'écran concerné. `●` (U+25CF) et les flèches `↗ ← ↓` sont, elles, largement couvertes par les replis système.

## Il y a désormais un runner JS (Vitest) — pour la logique pure, pas le rendu
**Ajouté le 31/08/2026.** Plusieurs règles plus anciennes affirment que « le projet n'a pas de runner JS » : c'est périmé.

`npm run test:js` (Vitest), inclus dans `composer ci:check`. Les tests vivent à côté du code, en `resources/js/**/*.test.ts`.

Motif : `resources/js/lib/chartPng.ts` a cassé deux fois en silence sur du calcul de géométrie — eslint, prettier, vue-tsc et Pest ne peuvent rien y voir. La suite couvre ce fichier et a été validée par contrôle négatif : réintroduire chaque bug fait tomber le test qui le vise.

Environnement `node`, **pas jsdom** : les tests bouchonnent eux-mêmes le peu de DOM nécessaire. Conséquence à connaître — ce runner couvre **la logique pure** (calculs, tri, mise en forme, géométrie). Il ne monte aucun composant Vue et ne voit **ni le CSS, ni les media queries, ni le rendu**. Une régression de mise en page reste invisible autrement qu'au navigateur : voir la règle sur `lg:h-screen lg:sticky` dans layouts.md, toujours valable.

`vitest.config.ts` est dans les `ignores` d'eslint, comme `vite.config.ts`.
