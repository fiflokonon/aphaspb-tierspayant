---
paths:
  - config/joomla.php
---

# Config

## JOOMLA_PHARMACY_GROUPS doit viser un groupe dédié, jamais Registered (2)
L'accès au tiers-payant se donne côté Joomla en assignant l'adhérent à un groupe dédié « Officines ». Le claim `groups` est rempli par `getAuthorisedGroups()`, qui remonte toute la chaîne d'héritage : un compte du groupe Officines porte aussi Registered (2) et Public (1).

Conséquence : `JOOMLA_PHARMACY_GROUPS=2` ouvre le tiers-payant à **tout compte inscrit** sur le site Joomla. Ça a été la configuration réelle jusqu'au 2026-09-02. Toujours pointer un groupe feuille créé pour cet usage, et vérifier l'id dans Utilisateurs → Groupes de Joomla plutôt que le supposer.

## JOOMLA_ISSUER est l'origine nue, jamais l'URL laissée par le filtre de langue
Le plugin « Filtre de langue » de Joomla redirige le point de handoff en 301 vers `/fr/…`. Recopier cette URL depuis la barre d'adresse donne `JOOMLA_ISSUER=https://aphaspb.com/fr`, qui ne correspond plus au claim `iss` signé par Joomla. Diagnostiqué en prod le 2026-09-04.

`JOOMLA_ISSUER` et le champ `issuer` du plugin valent tous deux l'origine nue (`https://aphaspb.com`), à l'identique.

Le symptôme est un 401 nu sur `/auth/callback`, sans trace en log : `JoomlaTokenDecoder::decode()` renvoie `null` pour toute cause d'échec (signature, `iss`, `aud`, expiration) afin de ne rien divulguer. Pour distinguer ces causes, décoder le payload du ticket — il est public par construction — et le comparer à `php artisan config:show joomla` :

    echo "$TOKEN" | cut -d. -f2 | tr '_-' '/+' | base64 -d

Le contrôleur renvoie aussi 401 quand le cookie `state` manque (ligne 40) et quand `/api/me` échoue (ligne 54). Pour savoir lequel des trois a parlé sans toucher au code : si la réponse 401 porte un `Set-Cookie` supprimant `joomla_handoff_state` (expiration 5 ans dans le passé), le contrôle du `state` est passé et l'échec est plus bas.
