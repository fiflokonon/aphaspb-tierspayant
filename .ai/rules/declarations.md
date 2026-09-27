---
paths:
  - 'app/Actions/Declarations/**'
---

# Declarations

## Chaque enregistrement laisse une révision, sauf s'il ne change rien
`declaration_revisions` garde un instantané par enregistrement (auteur, montants, dates, versements en JSON). Motif : `RecordPaymentInstalments` réécrit les versements en entier à chaque sauvegarde, donc sans trace rien ne dit ce qu'un chiffre valait avant.

`RecordDeclarationRevision` compare l'instantané au dernier enregistré et n'écrit **rien** s'ils sont identiques : rouvrir un mois pour vérifier un chiffre puis le renvoyer tel quel est le geste le plus courant, et le compter ferait de « modifiée 4 fois » un compteur de visites. La méthode `snapshot()` sert à la fois à écrire et à comparer — un champ ajouté à l'un l'est à l'autre.

Ordre obligatoire dans `DeclarationController::store()` : `updateOrCreate` → `RecordPaymentInstalments` → `RecordDeclarationRevision`. Une révision antérieure aux versements photographierait les totaux de l'enregistrement précédent.

La **note privée est volontairement absente** de la table : la trace porte sur les chiffres que le réseau lit, et en garder des copies élargirait la surface de fuite. Verrouillé par un test.

La première révision est l'état d'origine, pas une correction : partout dans l'UI, le nombre affiché est `revisions_count - 1`, compté sur `revisions` filtrées par le scope `aboutFigures()`. Une révision qui ne diffère de la précédente que par `penalty_settlement` / `penalty_settled_amount` porte `penalty_only = true` (posé par `RecordDeclarationRevision`, jamais sur la première) : c'est une trace de clôture, pas une correction, et l'historique du formulaire l'affiche comme telle.

## Une clôture de pénalité tombe d'elle-même
`ReconcilePenaltySettlement` est appelée à la fin de `RecordPaymentInstalments::handle()` (qui rend désormais `?PenaltyReopened`), jamais depuis le hook `saving` : celui-ci n'a ni les versements ni l'assureur.

Deux motifs de levée : cas A, le mois n'est plus couvert (`uncovered`) ; cas B, `PenaltyCalculator::for()` ≠ `penalty_settled_amount` (`amountChanged`) — ce cas couvre aussi un mois clos qui vient d'être rejeté.

Les quatre colonnes de clôture sont hors `#[Fillable]` : elles ne se posent et ne se lèvent que par `Declaration::settlePenalty()` / `clearPenaltySettlement()`. Ni `SettlePenalty` ni `ReconcilePenaltySettlement` n'écrivent de révision — c'est à l'appelant de le faire (`PenaltySettlementController`, `DeclarationController::store()`).

Trou connu : modifier la clause d'un assureur (`InsurerManagementController::update()`) ne réconcilie aucune déclaration close. Un mois clos peut donc garder un montant clos ≠ `for()` sans être levé ; `due()` continue d'y lire 0.
