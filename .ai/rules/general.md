---
paths:
  - package.json
  - '**'
---

# General

## npm install/uninstall est cassé : modifier package.json à la main
Toute commande npm qui reconstruit l'arbre (`install`, `uninstall`, même `--dry-run`) plante avec `TypeError: Invalid Version:` en dédupliquant `@rolldown/binding-openharmony-arm64`, dont le packument porte une version vide. Cause externe au projet.

Conséquence : pour ajouter ou retirer une dépendance, éditer `package.json` directement et laisser `package-lock.json` désynchronisé. `npm run build`, `types:check` et `lint:check` fonctionnent, eux, car ils n'installent rien.

Le dépôt contient un `pnpm-workspace.yaml` : pnpm est probablement le gestionnaire visé, mais il n'est pas installé sur la machine de dev actuelle.

## npm install/uninstall est cassé : modifier package.json à la main
**RÉSOLU le 2026-08-26. Cette note est conservée comme trace du diagnostic ; npm fonctionne normalement à nouveau.**

Symptôme : toute commande npm reconstruisant l'arbre (`install`, `uninstall`, même `--dry-run`) plantait avec `TypeError: Invalid Version:` dans `Node.canDedupe`, en dédupliquant `@rolldown/binding-openharmony-arm64`.

Cause : `package-lock.json` contenait, depuis le commit d'import, une entrée moignon `{"optional": true}` pour `node_modules/rolldown/node_modules/@rolldown/binding-openharmony-arm64` — sans `version`, `resolved` ni `integrity`. `canDedupe` lisait cette version absente et la passait à `semver`. Ni le registre ni le cache HTTP n'étaient en cause : le paquet est publié normalement.

Correctif : retirer cette seule entrée du lockfile, puis `npm install`, qui le régénère proprement sans réintroduire le moignon.

Si le symptôme réapparaît, chercher les entrées sans version :
`python3 -c "import json;d=json.load(open('package-lock.json'));print([k for k,v in d['packages'].items() if k and not v.get('version') and not v.get('link')])"`

Ce bug cassait aussi la CI, qui lance `npm install` via `composer setup`.

## Vérifier avec `composer ci:check`, pas avec pint --dirty seul
La CI lance `composer ci:check`, qui enchaîne `npm run lint:check`, `npm run format:check`, `phpstan analyse`, `pint --parallel --test` et `php artisan test`. Deux de ces étapes échappent aux vérifications habituelles :

- **`npm run format:check`** (Prettier) n'est couvert ni par eslint ni par `types:check`. Un script de renommage ou une édition manuelle sur un fichier `.vue` casse facilement le formatage sans qu'aucun autre outil ne le signale.
- **`pint --parallel --test`** scanne tout le projet, alors que `pint --dirty` ne voit que les fichiers modifiés depuis git. En committant entre deux passes de `--dirty`, des fichiers restent durablement non formatés.

Avant de déclarer un travail terminé, lancer `composer ci:check` en entier, pas seulement `pint --dirty` et la suite de tests.
