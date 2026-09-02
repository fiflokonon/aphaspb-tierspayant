---
paths:
  - config/joomla.php
---

# Config

## JOOMLA_PHARMACY_GROUPS doit viser un groupe dédié, jamais Registered (2)
L'accès au tiers-payant se donne côté Joomla en assignant l'adhérent à un groupe dédié « Officines ». Le claim `groups` est rempli par `getAuthorisedGroups()`, qui remonte toute la chaîne d'héritage : un compte du groupe Officines porte aussi Registered (2) et Public (1).

Conséquence : `JOOMLA_PHARMACY_GROUPS=2` ouvre le tiers-payant à **tout compte inscrit** sur le site Joomla. Ça a été la configuration réelle jusqu'au 2026-09-02. Toujours pointer un groupe feuille créé pour cet usage, et vérifier l'id dans Utilisateurs → Groupes de Joomla plutôt que le supposer.
