---
paths:
  - app/Support/DayNumber.php
  - app/Support/ConsoleNavigation.php
---

# Support

## Le calcul de pénalité compte en numéros de jour, pas en Carbon
Mesuré sur 40 000 déclarations : **4 323 ms et 273 Mo** avec des objets `CarbonImmutable`, **42 ms et 34 Mo** avec des entiers. Le goulot n'est pas l'arithmétique, c'est l'allocation d'objets. La conversion de 160 000 dates coûte 99 ms par `strtotime` contre 380 par Carbon.

Trois précautions, chacune verrouillée par un test et **chacune vérifiée par mutation** :

- **`fromCarbon()` passe par `format('Y-m-d')`**, jamais par `getTimestamp()`. `app.timezone` vaut UTC aujourd'hui ; s'il changeait, un `getTimestamp() / 86400` décalerait toutes les dates d'un jour une partie de l'année, en silence.
- **`today()` dérive de `CarbonImmutable::now()`**, pas de `time()`, sinon `travelTo()` cesse de piloter les tests et toute la suite « la pénalité croît avec le temps » devient fausse sans rougir.
- **`accruedInDays()` prend un `$today` optionnel** : le laisser se recalculer par ligne coûtait 406 ms sur 40 000 déclarations contre 52 une fois hissé hors de la boucle. Tout appelant qui boucle doit le passer.

`floor()` plutôt qu'`intdiv()` est une **précaution, pas une nécessité** : sur une date seule, minuit UTC tombe sur un multiple exact de 86 400 et les deux rendent le même entier. Aucun test ne peut les distinguer — ne pas en écrire un qui prétendrait le faire.

## L'espace de la session se lit dans `console.account.administrator`, pas dans `console.space`
`console.space` vaut « ESPACE ADMIN » ou null : c'est une **étiquette d'affichage** de la barre latérale, pas un drapeau. Ne pas s'en servir côté front pour décider quoi que ce soit — elle changerait au premier remaniement de libellé.

Le drapeau est `console.account.administrator` (bool), posé par `ConsoleNavigation::account()` depuis la Gate `manage-network`. `ConsoleHeader.vue` s'en sert pour annoncer « Vous êtes dans l'espace / ADMINISTRATEUR » là où une officine annonce son nom.

Couvert par `tests/Feature/Console/ConsoleShellTest.php` (« the shell says which space the session is in »), sur les deux espaces.
