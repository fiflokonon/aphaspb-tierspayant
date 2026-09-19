---
paths:
  - app/Models/Declaration.php
  - app/Models/Insurer.php
---

# Models

## delay_days est dérivé des deux dates, jamais saisi
Depuis le 27/08/2026, une déclaration porte `invoice_deposited_on` et `paid_on`. `Declaration::deriveDelayDays()` recalcule `delay_days` dans le hook `saving`, exactement comme `status` : la colonne reste (tous les agrégats réseau la somment en SQL) mais c'est un cache, jamais une source de vérité.

Conséquences : `SaveDeclarationRequest` n'accepte plus `delay_days` (un client peut l'envoyer, il est ignoré), et `DeclarationFactory::configure()` retraduit un `delay_days` demandé en paire de dates ancrée sur le mois déclaré — c'est ce qui permet aux tests de continuer à parler en délais.

Le seuil de paiement n'est plus global : il vit sur `insurers.standard_delay_days` (voir `NetworkStatsService::WITHIN_STANDARD_DELAY_SUM`).

## Un règlement se lit dans declaration_payments ; amount_received et paid_on n'en sont que le cache
Depuis le 08/09/2026, un assureur peut régler un mois en plusieurs virements. Chaque versement est une ligne de `declaration_payments` (`amount`, `paid_on`, `delay_days`), et c'est la seule source de vérité.

`Declaration::syncFromInstalments()` recalcule ensuite `amount_received` (somme des lignes) et `paid_on` (**date la plus récente**, décision produit : un mois est jugé sur le moment où il a fini d'être réglé). Le hook `saving` en tire `status` et `delay_days` comme avant. Ne pas réintroduire `amount_received` ni `paid_on` comme champs de formulaire.

Écriture unique : `RecordPaymentInstalments`, appelé par `DeclarationController::store()` après l'`updateOrCreate`. Il **réécrit toutes les lignes** à chaque enregistrement — c'est ce qui garantit qu'une correction de `invoice_deposited_on` recalcule le délai de chaque versement.

`Declaration::$attributes` force `amount_received => 0` : une déclaration neuve est enregistrée avant ses versements, et le hook `saving` en dérive le statut.

`DeclarationFactory` crée automatiquement une ligne unique quand `amount_received > 0` et `paid_on` non nul, ce qui garde toute la suite existante cohérente ; l'état `instalments([...])` sert aux échéances multiples.

Verrouillé par `tests/Feature/Pharmacy/DeclarationTest.php`.

## Le taux de pénalité vit en points de base, et se lit par une méthode
`penalty_rate_bp` : 250 = 2,50 %. Une pénalité est de l'argent, et tout montant de ce projet est un entier FCFA — `intdiv($base * $rateBp, 10000)` reste exact là où `$base * 2.5 / 100` passe par un flottant. Le pourcentage n'est qu'une commodité de saisie, converti dans `InsurerManagementController`.

`penaltyRatePercent()` est une **méthode simple, pas un accesseur Eloquent**, contrairement à `Declaration::amountOutstanding()`. PHPStan traite le `TGet` d'`Attribute` comme invariant et refuse une union nullable en cette position, quelle que soit l'annotation (`float|null`, `?float`, closure explicite : toutes testées). Ne pas « corriger » en la retransformant en `Attribute`.

Le cast `(float)` dans cette méthode n'est pas décoratif : en PHP `200 / 100` rend l'entier `2` et `250 / 100` le flottant `2.5`. Sans lui le taux change de type selon sa valeur, jusque dans le JSON envoyé au client.

Les deux colonnes de clause sont nullables et se lisent **comme un tout** via `hasPenaltyClause()` : une demi-clause n'accumule rien. `SaveInsurerRequest` les valide ensemble avec `required_with` dans les deux sens, et **sans `sometimes`** — `sometimes` court-circuite la validation d'un champ absent, soit exactement ce que `required_with` doit refuser.
