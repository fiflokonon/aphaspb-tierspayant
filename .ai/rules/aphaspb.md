---
paths:
  - 'resources/js/components/aphaspb/**'
---

# Aphaspb

## AmountField : la valeur soumise part d'un input caché, jamais du champ visible
`AmountField.vue` affiche le montant formaté (« 50 000 » avec espace insécable fine) mais ne met **pas** le `name` sur cet input : c'est un `<input type="hidden">` qui porte le `name` et la valeur numérique brute du `v-model`.

Remettre le `name` sur le champ visible fait partir la chaîne formatée au serveur, que la règle `integer` rejette — l'écran de déclaration a vécu avec ce bug, avec un message d'erreur sous un champ que l'œil lit comme un nombre.

Même précaution pour tout futur champ à affichage formaté (dates, pourcentages).

## Étiquette + valeur dans une même puce : aligner les lignes de base, et re-centrer l'absolu
Deux textes de polices et de corps différents (mono 9,5 px capitales pour l'étiquette, sans 12 px pour la valeur) centrés dans la même boîte ne posent pas leurs lettres à la même hauteur : mesuré 1,5 px d'écart (taille par défaut) et 1,75 px (compact) dans `FilterSelect.vue`. Corriger par `items-baseline` sur le conteneur, jamais par un `translate-y` chiffré — l'écart dépend des métriques des polices et se refait au moindre changement de corps.

Piège qui va avec : un enfant en `absolute` sans `top` (ici le chevron) tire sa position verticale de sa position statique, donc de l'`align-items` du conteneur flex. Passer le conteneur en `items-baseline` le fait remonter au bord haut de la puce. Lui remettre `self-center`.

Rien ne le détecte : Vitest tourne en node sans DOM et ne voit pas le CSS. Vérification faite par capture Chrome headless (`--screenshot --force-device-scale-factor=4`) d'une page statique reprenant le markup et les woff2 de `public/build/assets`, puis mesure des bas de glyphes en pixels.
