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

- `WhatsappNumber` : `97 00 00 00` → `+22997000000` ; `0022997000000` →
  `+22997000000` ; `+33 6 12 34 56 78` → `+33612345678` ; `abc` et `123` →
  invalide.
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
