---
paths:
  - 'resources/js/components/aphaspb/**'
---

# Aphaspb

## AmountField : la valeur soumise part d'un input caché, jamais du champ visible
`AmountField.vue` affiche le montant formaté (« 50 000 » avec espace insécable fine) mais ne met **pas** le `name` sur cet input : c'est un `<input type="hidden">` qui porte le `name` et la valeur numérique brute du `v-model`.

Remettre le `name` sur le champ visible fait partir la chaîne formatée au serveur, que la règle `integer` rejette — l'écran de déclaration a vécu avec ce bug, avec un message d'erreur sous un champ que l'œil lit comme un nombre.

Même précaution pour tout futur champ à affichage formaté (dates, pourcentages).
