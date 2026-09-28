# Suivi des déclarations — qui a déclaré quel mois, pour relancer

Date : 28/09/2026
Statut : spec validée en séance, à relire avant plan d'implémentation

## 1. Demande

> Au niveau du réseau, on n'a pas la possibilité de savoir qui a fait sa
> déclaration de tel mois, et qui manque. Cette donnée pourrait être utilisée
> pour relancer les pharmacies qui n'ont pas effectué leur déclaration de tel
> ou tel mois. Comment mettre en place ce dispositif sans corrompre l'idée ?

« L'idée », c'est le principe du CDC que l'espace réseau n'atteint jamais « un
nom d'officine lié à une déclaration ». Ce lot y fait **une exception
délimitée**, décrite au §3.

## 2. Décisions prises en séance

| Question | Décision |
|---|---|
| Qui sait quelles officines n'ont pas déclaré ? | **L'admin réseau voit la liste des officines, par mois, sans assureur ni montant.** La relance se fait **hors système**, par WhatsApp. |
| Une officine qui n'a déclaré qu'une partie de ses assureurs ? | **Trois états** : complet / partiel / rien. Jamais quels assureurs manquent, ni combien. |
| Où vit le numéro WhatsApp ? | **Sur la fiche officine, saisi par l'officine**, facultatif. |
| Où vit la liste ? | Un **écran admin dédié** « Suivi des déclarations », séparé de « Pharmacies inscrites ». |

## 3. L'exception au CDC, et ses bornes

Le CDC V1.0 et la spec d'implémentation (§ « Ce que l'espace admin ne peut pas
atteindre ») interdisent « un nom d'officine lié à une déclaration ».
`RegisteredPharmaciesController` l'applique en refusant tout indicateur « a
déclaré ».

Ce lot **amende** cette règle, et seulement ainsi :

- **ce qui devient visible** : pour une officine nommée et un mois terminé,
  l'état complet / partiel / rien de ses déclarations ;
- **ce qui reste interdit** : quels assureurs sont déclarés ou manquent, le
  nombre d'assureurs, tout montant, statut, date ou délai, la note privée, et
  toute déclaration individuelle ;
- **un seul endroit** : le contrôleur de l'écran de suivi. Il ne lit que des
  **comptes** (assureurs attendus, assureurs déclarés par officine et par mois),
  jamais une ligne de `declarations` hydratée ni un montant ;
- « Pharmacies inscrites » reste l'écran sans aucune donnée de déclaration.

**Risque résiduel, nommé.** Savoir quelles officines ont déclaré un mois donné
ne dit ni à quels assureurs, ni combien. Un tiers qui connaîtrait par ailleurs
les assureurs d'une officine « complète » saurait qu'elle compte dans les
agrégats de ces assureurs ce mois-là. Les agrégats restent protégés par le
seuil d'anonymat (≥ 5 officines déclarantes) : l'appartenance à l'agrégat ne
révèle pas sa part.

**Fuite par combinaison, trouvée en revue et fermée à la source.** Cette
dernière phrase ne tenait pas : dans une ville de 5 officines où une seule a
déclaré au trimestre, le suivi la nomme (« Partielle », les autres « Sans
déclaration ») ; « Statistiques réseau » filtré sur cette ville affichait pour
deux assureurs « 1 officine déclarante » (compte exact sur des lignes
retenues), et « Évolution » filtré sur la ville publiait facturé, encaissé et
encours sans aucun seuil. L'admin apprenait ainsi quels assureurs cette
officine nommée avait déclarés et combien elle avait facturé. Correction, sur
toutes les sorties réseau (écrans, props, CSV/XLSX, PDF, notifications) :
(1) le compte exact d'une entrée retenue ne sort plus — on écrit « moins de N
officines déclarantes » ; (2) tout agrégat réseau repose sur au moins N
officines déclarantes distinctes à sa propre granularité, filtres compris, ou
il est retenu (conservé, vidé, expliqué) : résumé réseau et montants réseau
(`networkSummary()`, `aggregatedAmounts()`), chaque point de la courbe des
délais, chaque mois du total du journal des pénalités, avec ou sans filtre
ville. Test de non-régression : `tests/Feature/Admin/CombinedScreensLeakTest.php`.

**Fuite par soustraction entre villes, fermée elle aussi.** Un second passage
de revue a reconstruit la même information autrement : les villes partagent
le réseau sans recouvrement, donc un chiffre sans filtre ville, moins les
chiffres publiés de chaque ville, rend ceux des villes retenues. Sur l'exemple
ci-dessus, « Évolution » sans filtre (6 officines, 10 062 000 FCFA facturés)
moins « Évolution » sur Cotonou (5 officines, 5 000 000) rendait les
5 062 000 FCFA de l'officine de Bohicon. Les officines sans ville, qu'aucun
filtre ne publie, se retrouvaient toujours de cette façon. Désormais, tout
chiffre réseau sans filtre ville est retenu lorsque la part qu'il cacherait
(les villes retenues et les officines sans ville) repose sur 1 à 4 officines
(seuil − 1). La règle vaut pour chaque niveau publié : synthèse, montants,
ligne par assureur, point mensuel de la courbe, mois du rapport PDF, mois du
journal des pénalités et découpage payée / annulée / due.

Un troisième passage a trouvé un détour en deux temps. Un assureur retenu par
cette règle reste compté dans la synthèse et dans le total du journal. La
synthèse, moins les assureurs publiés, rendait alors cet assureur ; moins sa
ligne publiée à Cotonou, elle rendait l'officine de Bohicon. Exemple : un
assureur A déclaré par 5 officines de Cotonou et une seule de Bohicon
(7 000 000 FCFA), un assureur B par 5 officines de Cotonou et 5 de Bohicon.
La synthèse (22 000 000) moins B (10 000 000) moins A à Cotonou (5 000 000)
donnait les 7 000 000 de l'officine de Bohicon. La synthèse, les montants et
le total du journal sont désormais retenus aussi lorsque les parts cachées de
ces assureurs reposent, ensemble, sur 1 à 4 officines distinctes.

**Risques résiduels acceptés.** L'APhaSPB accepte, en connaissance de cause,
les trois cas suivants. Chacun demande de croiser volontairement plusieurs
écrans, et le fermer rendrait l'outil largement inutilisable :

1. *Périodes qui se recouvrent.* En comparant deux périodes qui se chevauchent
   (par exemple « 12 derniers mois » et « année civile »), la différence porte
   sur les seuls mois qui les séparent. Si peu d'officines ont déclaré ces
   mois-là, leurs chiffres peuvent se déduire.
2. *Synthèse moins assureurs publiés.* La synthèse réseau inclut les assureurs
   masqués parce qu'ils comptent trop peu d'officines déclarantes. En lui
   retirant les lignes des assureurs publiés, on obtient la part cumulée de
   ces assureurs masqués. C'est la même décision que celle prise le
   27/09/2026 pour le journal des pénalités. Ce risque ne couvre plus les
   assureurs retenus par la règle de partition par ville : pour eux, la
   synthèse et le total du journal sont retenus dès que leurs parts cachées
   reposent, ensemble, sur 1 à 4 officines.
3. *Nombre d'officines conventionnées.* L'écran « Gestion des assureurs »
   affiche, pour chaque assureur, le nombre d'officines qui l'ont coché. Pour
   un assureur qui en compte 1 à 4, ce nombre signale que ses éventuels
   chiffres ne concernent que ces quelques officines.

Cet amendement est une décision du 28/09/2026. **Il doit être validé par écrit
côté APhaSPB avant la mise en production**, puisque le CDC est le document
signé.

## 4. Données

### 4.1 Numéro WhatsApp

- Colonne `pharmacies.whatsapp_phone` : `string(20)`, nullable, sans valeur
  par défaut.
- Saisie : au profil d'inscription (`onboarding/`, `SavePharmacyProfileRequest`)
  et dans la modification de l'officine (`settings/pharmacies/{pharmacy}`),
  réservée à qui peut déjà modifier l'officine. Le champ est facultatif.
- Normalisation à l'enregistrement : on retire espaces, points, tirets et
  parenthèses. Un `00` initial devient `+`. Sans indicatif, on préfixe `+229`.
  Le résultat doit être `+` suivi de 8 à 15 chiffres (E.164). Sinon, erreur de
  validation « Numéro WhatsApp invalide. ». La normalisation vit dans une
  classe dédiée (`App\Support\WhatsappNumber`), testée seule.
- Officines déjà inscrites : une invite discrète sur le tableau de bord, tant
  que le numéro est vide : « Ajoutez un numéro WhatsApp pour que le réseau
  puisse vous joindre », avec un lien vers la modification.

### 4.2 État d'une officine pour un mois

Même règle que `DeclarationCalendar::months()` côté officine, appliquée à
tout le réseau :

- **attendus** : le nombre d'assureurs actuellement cochés par l'officine
  (`insurer_pharmacy`) ;
- **déclarés** : le nombre d'assureurs distincts de cette liste pour lesquels
  une déclaration existe ce mois-là ;
- **complet** si déclarés ≥ attendus, **partiel** si 0 < déclarés < attendus,
  **rien** si déclarés = 0 ;
- une officine **sans assureur coché** n'apparaît pas : elle n'a pas fini son
  inscription, et `DeclarationCalendar::outstanding()` ne lui réclame rien non
  plus ;
- une officine **supprimée** (soft delete) n'apparaît pas ;
- une officine inscrite **après la fin du mois** n'apparaît pas pour ce mois :
  on ne relance pas pour un mois où elle n'existait pas sur la plateforme.

Une déclaration **rejetée** compte comme déclarée : l'officine a fait sa
déclaration, c'est l'assureur qui l'a refusée.

### 4.3 Mois suivis

Seulement les mois **terminés**, sur la fenêtre de rattrapage
(`Declaration::EARLIEST_MONTHS_BACK` = 12 mois). Le mois en cours n'est pas
proposé. Par défaut, c'est le mois précédent.

## 5. Écran « Suivi des déclarations »

- Route `GET /admin/declarations-followup`, nom
  `admin.declarations-followup` (dernier segment sans mot réservé JS, cf.
  routes.md), garde `can:manage-network`.
- Contrôleur `App\Http\Controllers\Admin\DeclarationFollowUpController`,
  lecteur `App\Services\Network\DeclarationCompleteness`.
- Entrée de navigation admin « Suivi des déclarations », icône Lucide dédiée
  (clé ajoutée à `navIcons.ts`).

Contenu :

- **En tête**, la complétude du mois : « 118 complètes · 5 partielles · 3
  sans déclaration, sur 126 officines ».
- **Filtres** : mois (liste des mois suivis), ville, état. L'état vaut par
  défaut « à relancer », soit partiel + rien. Les autres choix sont « toutes »,
  « complètes », « partielles », « sans déclaration ».
- **Liste paginée**, triée par nom : nom, ville, état (puce), et
  - s'il y a un numéro : un lien « Relancer sur WhatsApp » vers
    `https://wa.me/<numéro sans +>?text=<message encodé>`, ouvert dans un
    nouvel onglet ;
  - sinon : « pas de numéro ».
- **Message pré-rempli**, construit côté serveur et encodé en URL :
  « Bonjour {nom de l'officine}, votre déclaration de {mars 2026} sur la
  plateforme APhaSPB est {incomplète | à faire}. Vous pouvez la compléter ici :
  {URL absolue de pharmacy.declare pour ce mois}. Merci ! »
- Rien n'est tracé : la relance est hors système, et la plateforme ne sait pas
  si elle a eu lieu.

Props de page : `month`, `months`, `city`, `cities`, `state`, `summary`
(`complete`, `partial`, `none`, `total`) et `pharmacies` (paginé : `id`,
`name`, `city`, `state`, `whatsappUrl` nullable). **Aucune** clé d'assureur,
de compte d'assureurs ni de montant.

## 6. Performance

Un nombre fixe de requêtes quel que soit le nombre d'officines :

- les officines éligibles (non supprimées, inscrites avant la fin du mois,
  avec au moins un assureur, ville filtrée), avec leur nombre d'assureurs
  attendus (`withCount`) ;
- les assureurs déclarés par officine pour le mois, en une requête groupée
  `COUNT(DISTINCT declarations.insurer_id)`, jointe à `insurer_pharmacy` pour
  ne compter que les assureurs encore cochés.

Le filtre d'état et la pagination s'appliquent sur ce résultat. À l'échelle
visée (≈ 400 officines), un tri et un découpage en PHP sont acceptables. Le
résumé porte sur toutes les officines éligibles, avant filtre d'état.

## 7. Tests (Pest)

- `WhatsappNumber` (plan béninois à 10 chiffres depuis fin 2024, voir §9) :
  `01 97 00 00 00` → `+2290197000000` ; l'ancien `97 00 00 00` →
  `+2290197000000` ; `22997000000` → `+2290197000000` ; `+33 6 12 34 56 78` →
  `+33612345678` ; `abc`, `123`, `1234567` et `9700000000` → invalide.
- Saisie : le profil d'inscription et la modification de l'officine
  enregistrent le numéro normalisé, et refusent un numéro invalide avec le
  message.
- États : complet, partiel et rien sur un même mois ; une officine sans
  assureur absente ; une officine inscrite après le mois absente ; une
  déclaration rejetée compte ; une déclaration pour un assureur décoché ne
  compte pas.
- Écran :
  - accès 403 pour une officine ;
  - le résumé est juste ;
  - le filtre d'état par défaut vaut « à relancer » ;
  - le filtre ville fonctionne ;
  - le lien WhatsApp est correct (numéro, message encodé, URL de déclaration du
    bon mois) ;
  - « pas de numéro » s'affiche sans numéro ;
  - le mois en cours et les mois hors fenêtre ne sont pas proposés.
- Confidentialité : sur un décor avec assureurs nommés, montants et note
  privée, les props de l'écran (`inertiaPropsJson`) ne contiennent ni nom
  d'assureur, ni montant, ni la note, ni un compte d'assureurs.
- Nombre de requêtes constant entre 1 et 20 officines.
- Navigation : l'entrée admin existe, et `ConsoleShellTest` compte une entrée de
  plus.

Vérification finale : `composer ci:check`.

## 8. Hors lot

- Envoi automatique de messages (WhatsApp, SMS, e-mail) par la plateforme.
- Trace des relances effectuées.
- Export de la liste.
- Relance par assureur.

## 9. Écarts avec la version validée

- **Plan de numérotation béninois.** Depuis fin 2024, un mobile béninois a 10
  chiffres et commence par `01`. La normalisation (§4.1) ajoute `01` à un
  ancien numéro à 8 chiffres, lit `229…` sans `+` comme l'indicatif, et
  refuse un numéro national qui n'a ni 8 chiffres ni la forme `01` + 8.
  Un numéro étranger doit porter son indicatif (`+` ou `00`).
- **Même règle que le calendrier officine, pour de vrai.** `DeclarationCalendar`
  ignore désormais, lui aussi, les déclarations d'un assureur décoché depuis.
  Sans cela, le réseau relançait une officine dont le tableau de bord ne
  réclamait rien.
- **Normalisation partagée** par le trait
  `App\Http\Requests\Concerns\ValidatesWhatsappPhone`, plutôt que recopiée
  dans les deux requêtes.
- **Pas de lien de relance pour une officine complète** : rien à lui rappeler.
- **Le `DISTINCT`** du compte d'assureurs déclarés est une précaution : l'index
  unique `decl_pharmacy_insurer_period_unique` rend déjà impossible un doublon.
- **Résumés réseau et total non filtré du journal sous seuil, comptes exacts
  jamais montrés sous le seuil** (après revue, 28/09/2026). Remplace deux
  décisions antérieures : « le résumé réseau n'a pas de seuil, sauf restreint à
  un assureur » et « le total non filtré du journal est publié même sous le
  seuil » (27/09/2026). Les assureurs masqués restent comptés dans un total
  publié (risque « par différence » accepté le 27/09/2026, inchangé). La ligne
  réseau de la courbe ne moyenne plus que les points assureur × mois publiés.
  Détail : §3, « Fuite par combinaison ».
- **Règle de partition par ville** (second tour de revue, 28/09/2026) : un
  chiffre réseau sans filtre ville est retenu quand les villes retenues et
  les officines sans ville y pèsent 1 à seuil − 1 officines. L'agrégat par
  assureur coûte une requête de plus (4 au lieu de 3). La fabrique de test
  `PharmacyFactory` range désormais toute officine à Cotonou par défaut, sans
  quoi la règle rendait les tests aléatoires. Risques résiduels acceptés
  (périodes qui se recouvrent, synthèse moins assureurs publiés, nombre
  d'officines conventionnées) : §3.
- **Assureurs retenus par la partition et totaux** (troisième tour de revue,
  28/09/2026) : la synthèse, les montants réseau et le total du journal sont
  aussi retenus quand les parts cachées de ces assureurs reposent, ensemble,
  sur 1 à seuil − 1 officines. Le risque accepté « synthèse moins assureurs
  publiés » est restreint en conséquence (§3).
