---
paths:
  - 'app/Services/Network/**'
---

# Network

## Deux indicateurs de ponctualité : par déclaration et par argent — ils divergent volontairement
Depuis les échéances multiples (08/09/2026), `NetworkStatsService::perInsurer()` expose deux mesures distinctes du respect du délai standard :

- `withinThresholdShare` — part des **déclarations** réglées dans les temps. Jugée sur `declarations.delay_days`, qui porte le délai du **dernier** versement : un solde tardif fait sortir tout le mois du délai.
- `recoveredWithinDelayShare` — part de l'**argent** arrivé dans les temps, sommée versement par versement sur `declaration_payments.delay_days`, rapportée au **facturé**. Un acompte payé vite compte, même si le solde traîne ; ce qui n'a jamais été payé pèse comme ce qui a été payé en retard.

L'écart entre les deux est le signal utile, pas un bug : ne pas « harmoniser » les dénominateurs.

`recoveredWithinDelay()` est une **requête séparée**, pas une jointure dans l'agrégat principal : joindre les versements multiplierait les lignes de déclaration et gonflerait tous les `COUNT(*)` et `SUM(amount_invoiced)` voisins. `perInsurer()` coûte donc 3 requêtes, pas 2 (test dédié).

`declaration_payments.delay_days` est stocké pour la même raison que celui des déclarations : comparer une date à un intervalle porté par une colonne demanderait de l'arithmétique de dates en SQL, que ce projet évite.

Colonne d'export : `recouvre_dans_delai_pct`, insérée après `part_sous_seuil_pct` — les index des colonnes suivantes ont bougé d'un cran.

## Le pire retard se calcule en SQL, la pénalité non
**Le délai le plus long ne demande aucune boucle.** Sa définition — par déclaration, l'âge de l'encours si elle est ouverte, sinon son `delay_days`, les rejetées ignorées — se scinde en deux agrégats purs regroupés par assureur :

```sql
MAX(CASE WHEN status != 'rejected' AND amount_invoiced <= amount_received THEN delay_days END)
MIN(CASE WHEN status != 'rejected' AND amount_invoiced >  amount_received
              AND invoice_deposited_on IS NOT NULL THEN invoice_deposited_on END)
```

L'âge se déduit du second par une soustraction, et le résultat est le max des deux termes non nuls. Prendre le max dans chaque groupe puis entre les groupes donne le max global. **Un test confronte l'agrégat SQL à `LongestDelay` déclaration par déclaration** : le garder, c'est lui qui rend l'équivalence vérifiable plutôt que supposée.

**La pénalité garde une boucle** — ses tranches sont séquentielles — mais restreinte aux assureurs sous convention (`whereNotNull('penalty_trigger_days')`). Deux assureurs sur huit sous clause = un quart des lignes lues.

`InsurerPenaltyAggregates` coûte **quatre requêtes quel que soit le volume** : les clauses, l'agrégat de délai, les versements (**joints**, jamais un `whereIn` sur 40 000 identifiants), le curseur des déclarations. Un test l'épingle. `cursor()` plutôt que `get()` ne change ni le résultat ni le compte de requêtes, seulement la mémoire — aucun test ne peut les séparer.

`DeclarationWindow` porte le filtre période + ville, partagé par les requêtes parties d'une autre table. Ne pas le recopier : deux copies du filtre qui décide quelles officines entrent dans un agrégat finiraient par diverger.

## L'anonymat des agrégats de pénalité se décide en amont, pas à la sortie
`InsurerPenaltyAggregates::forInsurers()` et `NetworkStatsService::monthlyByInsurer()` reçoivent **les identifiants d'assureurs déjà autorisés** par `NetworkStatsService::perInsurer()`. Le seuil n'est pas réévalué : il est en amont. Ne pas leur faire appeler `anonymityMinPharmacies()` — ce serait un second point de décision, donc un second endroit où se tromper.

**Le seuil par défaut vaut 5** (`SettingsRepository::DEFAULTS`), pas 2 : `ANONYMITY_FLOOR = 2` n'est que le plancher réglable. Un test qui crée moins de 5 officines verra son assureur retenu, avec toutes ses cellules vides — cause d'échec non évidente à la lecture.

**Vérifié par mutation, et le résultat surprend** : pour le **CSV**, passer *tous* les assureurs à l'agrégateur ne fait rougir aucune assertion de sortie — la branche `withheld()` de `NetworkExportRows` protège déjà le fichier. Le filtre en amont est donc de la **défense en profondeur**, pas la garde porteuse. Un test à espion (`$this->mock(InsurerPenaltyAggregates::class)`) l'épingle explicitement, pour qu'une colonne ajoutée un jour au chemin « retenu » ne puisse pas la faire fuiter. Pour le **PDF**, en revanche, la garde est structurelle : les pages itèrent `$rows`, qui ne contient que des assureurs autorisés.

## Le seuil d'anonymat vaut à chaque granularité publiée, pas une fois par période
**Correction d'une règle précédente qui surestimait la garantie.** `NetworkStatsService::perInsurer()` décide du seuil sur `COUNT(DISTINCT pharmacy_id)` **de toute la période**. Cette clairance ne vaut **que pour les agrégats de période**.

Dès qu'une sortie désagrège un assureur autorisé — par mois, par ville, par statut —, le seuil doit être réévalué à cette granularité. Un assureur déclaré par cinq officines sur l'année peut n'en avoir eu qu'une en mars : la ligne de mars rend alors la facture exacte d'une officine nommable.

Le cas s'est produit : `monthlyByInsurer()` + les pages par assureur du PDF réseau imprimaient ce mois-là en clair. `NetworkPdfExport::withheldMonths()` retient désormais les mois sous le seuil.

Conséquences pratiques :

- une méthode d'agrégat qui désagrège **expose son `declaringPharmacies`** et ne décide pas ; c'est l'appelant qui détient `SettingsRepository` qui retient ;
- la ligne retenue est **conservée et vidée**, jamais supprimée — une ligne absente se lit « rien déclaré », pas « chiffres retenus ». Même choix que `NetworkExportRows::withheld()` ;
- le seuil par défaut vaut **5** (`SettingsRepository::DEFAULTS`), pas 2 : `ANONYMITY_FLOOR = 2` n'est que le plancher réglable ;
- un test d'absence de fuite doit avoir un décor **discriminant** : si le total de période de l'assureur coïncide numériquement avec la valeur retenue, le test rougit sur un agrégat parfaitement légitime.

## Le résumé réseau n'a pas de seuil — sauf restreint à un assureur
`NetworkStatsService::networkSummary()` n'applique **aucun** seuil d'anonymat, et c'est légitime : il agrège tous les assureurs, donc aucune officine n'y est nommable.

Le filtre assureur de l'export (20/09/2026) change cette prémisse. Restreint à un assureur, ce même résumé devient *les chiffres de cet assureur* — une granularité de publication nouvelle, où une unique officine déclarante rend sa facture exacte lisible. `NetworkPdfExport::summary()` retient donc le résumé quand un assureur est choisi **et** qu'il est sous le seuil.

La condition porte sur `$withheld` non vide, pas sur un `$rows` vide : un assureur qui n'a rien déclaré sur la période mérite un résumé à zéro (« rien déclaré »), pas une rétention (« chiffres cachés »).

Le filtre lui-même vit dans `DeclarationWindow::apply()`, seul goulot des neuf agrégats. `InsurerPenaltyAggregates` ne le reçoit pas : son `whereIn` sur les assureurs autorisés le restreint déjà.

Deux tests le tiennent : un sur les données (`summary` vaut null), un sur le **rendu** — sans ce second, un `$summary['declarations']` resté dans le Blade ne rougirait qu'en production. Vérifié par mutation : la garde neutralisée, la vue rend un 500.
