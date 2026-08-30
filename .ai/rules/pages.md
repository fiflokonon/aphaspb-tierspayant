---
paths:
  - 'resources/js/pages/**'
---

# Pages

## Les blocs `<style>` des pages doivent être scoped
Toujours écrire `<style scoped>`, jamais `<style>`, dans les pages et layouts.

Les pages portent chacune ~1000 lignes de CSS maison avec des noms de classe génériques (`.card-header`, `.intro-text`, `.message`, `.input-wrapper`). Sans `scoped`, ces règles sont globales et la dernière page visitée écrase le style des autres : `.card-header` valait `align-items: center` pour `admin/Insurers` et `flex-start` + bordure pour `pharmacy/Insurers`.

Rien ne détecte ça : ni eslint, ni prettier, ni vue-tsc, ni les tests. Le projet n'a pas de runner JS, le symptôme n'apparaît qu'au navigateur, et seulement en naviguant d'un écran à l'autre.

Le CSS des pages ne stylise que leur propre markup et le contenu de leurs slots (compilé dans le parent, donc porteur du même attribut de scope). Si un jour une règle doit viser l'intérieur d'un composant enfant, l'envelopper dans `:deep()`.
