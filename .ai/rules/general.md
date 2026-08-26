---
paths:
  - package.json
---

# General

## npm install/uninstall est cassé : modifier package.json à la main
Toute commande npm qui reconstruit l'arbre (`install`, `uninstall`, même `--dry-run`) plante avec `TypeError: Invalid Version:` en dédupliquant `@rolldown/binding-openharmony-arm64`, dont le packument porte une version vide. Cause externe au projet.

Conséquence : pour ajouter ou retirer une dépendance, éditer `package.json` directement et laisser `package-lock.json` désynchronisé. `npm run build`, `types:check` et `lint:check` fonctionnent, eux, car ils n'installent rien.

Le dépôt contient un `pnpm-workspace.yaml` : pnpm est probablement le gestionnaire visé, mais il n'est pas installé sur la machine de dev actuelle.
