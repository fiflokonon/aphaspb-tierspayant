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

La première révision est l'état d'origine, pas une correction : partout dans l'UI, le nombre affiché est `revisions_count - 1`.
