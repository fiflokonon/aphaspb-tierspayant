---
paths:
  - app/Models/Declaration.php
---

# Models

## delay_days est dérivé des deux dates, jamais saisi
Depuis le 27/08/2026, une déclaration porte `invoice_deposited_on` et `paid_on`. `Declaration::deriveDelayDays()` recalcule `delay_days` dans le hook `saving`, exactement comme `status` : la colonne reste (tous les agrégats réseau la somment en SQL) mais c'est un cache, jamais une source de vérité.

Conséquences : `SaveDeclarationRequest` n'accepte plus `delay_days` (un client peut l'envoyer, il est ignoré), et `DeclarationFactory::configure()` retraduit un `delay_days` demandé en paire de dates ancrée sur le mois déclaré — c'est ce qui permet aux tests de continuer à parler en délais.

Le seuil de paiement n'est plus global : il vit sur `insurers.standard_delay_days` (voir `NetworkStatsService::WITHIN_STANDARD_DELAY_SUM`).
