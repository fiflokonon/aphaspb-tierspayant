---
paths:
  - app/Models/Declaration.php
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
