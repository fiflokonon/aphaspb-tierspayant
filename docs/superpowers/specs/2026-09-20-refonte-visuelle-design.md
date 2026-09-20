# Refonte visuelle — « rendre la plateforme chic »

Date : 20/09/2026
Statut : spec validée en séance, à relire avant plan d'implémentation

## Ce qu'on corrige, et pourquoi

Le client trouve la plateforme « pas chic ». Derrière ce mot, l'audit des
écrans et du code a trouvé cinq causes concrètes, dont une que personne
n'avait vue.

**Deux palettes coexistent.** Sept pages redéfinissent chacune, dans leur
propre bloc `<style>`, une copie **identique** d'une palette **turquoise**
(`--apha-primary: #008f83`). La coquille — barre latérale, en-tête, puces de
filtre — utilise le **vert officine** du thème (`#1f6f4a`). Sur l'écran
« Statistiques réseau », la pastille active de la barre latérale et le rond de
« Observatoire des paiements » sont donc deux verts différents à 200 px l'un de
l'autre. Le vert de la charte n'apparaît **dans aucune page**.

Chiffres relevés : **293 couleurs écrites en dur** dans `resources/js/pages`,
pour **71 valeurs distinctes** ; **7 copies** du bloc `--apha-*`, aujourd'hui
encore identiques.

Les quatre autres causes :

- **Tout est une boîte.** Sur le tableau de bord, neuf panneaux empilés
  partagent rayon, bordure, fond et largeur. Rien n'avance, rien ne recule.
- **Deux panneaux ne portent aucune donnée** (« Suivez vos paiements » et la
  note de bas de page) et occupent chacun une bande pleine.
- **La typographie est plate.** Le titre d'écran à 22 px dépasse à peine le
  corps à 13 px. `Instrument Serif`, déclaré dans le thème, ne sert que sur
  quatre écrans secondaires.
- **Les dégradés diffusent le propos** : halo radial de la barre latérale,
  liseré dégradé en tête des cartes, fonds de boutons dégradés.

## Décisions prises en séance

| Question | Décision | Qui a tranché |
|---|---|---|
| Latitude sur l'identité | Palette ajustable, vert de marque conservé | Client |
| Direction générale | Cartes sans bordure, ombre basse, liste d'alertes unifiée | Client |
| Forme sous 1024 px | Bandeau vert portant titre et chiffres clés | Client |
| Assureurs en retard | **Rouge plein** sur toutes les bandes de retard | Client, malgré l'avis contraire ci-dessous |
| Bande « dans le délai » | **Ambre dilué** — le plein reste le signal « en faute » | Client |
| Couleurs | Accentuées d'un cran | Client |
| Dégradés | Supprimés | Client |
| Barre latérale | Typographie grossie, icônes, repli | Client |
| Serif | Titres et chiffres clés, sur toutes les largeurs | Proposé, accepté |

**Réserve consignée, et écartée en connaissance de cause.** J'ai recommandé de
ne mettre en rouge plein que le pire payeur, les suivants restant dilués :
trois blocs rouges de force égale ne se distinguent plus les uns des autres, et
le but affiché — attirer l'attention — s'annule. Le client a maintenu le rouge
plein partout. C'est sa décision ; elle est appliquée telle quelle. Si l'écran
se révèle illisible en recette, ce paragraphe dit où revenir.

## Socle visuel

### Palette unique

Une seule définition, dans `resources/css/app.css` sous `:root`. Les sept
copies locales de `--apha-*` sont **supprimées**, pas mises à jour : leur
existence est la cause du problème.

| Rôle | Thème actuel | Pages actuelles | Nouveau |
|---|---|---|---|
| primary | `#1f6f4a` | `#008f83` | `#14764c` |
| primary-dark | `#17553a` | `#006f68` | `#0e5a3a` |
| primary-soft | — | `#e8f6f3` | `#e6f3ec` |
| gold | `#e8c25c` | — | `#f0c53f` |
| gold-mid | `#d9a325` | `#d7a33d` | `#e0a516` |
| gold-soft | — | `#fff8e9` | `#fdf7e6` |
| terracotta | `#c0472f` | — | `#d13b22` |
| terracotta-dark | `#a8391f` | — | `#ad2c16` |
| terracotta-soft | — | — | `#fdf0ed` |
| ink | `#17211c` | `#243333` | `#141d18` |
| muted | `rgb(23 33 28 / .5)` | `#788585` | `rgb(20 29 24 / .55)` |
| light | — | `#a2adad` | `rgb(20 29 24 / .38)` |
| border | — | `#e7eceb` | `rgb(20 29 24 / .11)` |
| cream | `#fdfbf7` | — | inchangé |

Le turquoise disparaît. Les noms `--apha-*` sont **conservés** comme alias des
tokens du thème, pour que les ~255 usages dans les pages continuent de
fonctionner sans être tous réécrits dans le même lot — mais ils sont définis
**une seule fois**, dans `app.css`.

Les 293 couleurs en dur sont remplacées par des tokens au fil des lots 3 et 4,
écran par écran. Aucun lot ne se termine en laissant une couleur en dur dans un
écran qu'il a touché.

### Typographie

| Niveau | Police | Taille | Avant |
|---|---|---|---|
| Titre d'écran | Instrument Serif | 34 px / 1.06 (27 px sous 1024) | Jakarta 700, 22 px |
| Chiffre clé | Instrument Serif | 29 px (21 px en bandeau) | Jakarta 800, 26 px |
| Titre de section | Jakarta 700 | 17 px | 15 px |
| Corps | Jakarta 400/500 | 14 px | 13 px |
| Métadonnée | Jakarta 400 | 12,5 px | 11,5 px |
| Étiquette capitale | Jakarta 700, `.14em` | 9,5 px | 9,5 px, inchangé |
| Navigation latérale | Jakarta 600 | 14,5 px | ~13 px |
| Marque | Jakarta 800 | 17 px | 15 px |

Le serif sert au titre d'écran et aux chiffres clés, **sur toutes les
largeurs**. Il n'est pas réservé au mobile : une police d'affichage qui
n'apparaîtrait qu'au téléphone se lirait comme une incohérence, pas comme une
intention.

### Dégradés

Tous supprimés. Relevé par fichier : `pharmacy/Insurers.vue` (13),
`pharmacy/History.vue` (11), `admin/Insurers.vue` (9), `pharmacy/Declare.vue`
(7), `admin/Trends.vue` (7), `admin/Network.vue` (7),
`layouts/console/ConsoleSidebar.vue` (7), `pharmacy/Dashboard.vue` (6),
`admin/Pharmacies.vue` (6), `admin/Exports.vue` (6),
`layouts/console/ConsoleLayout.vue` (2).

Remplacements : un aplat pour un fond, un filet 1 px pour un liseré, rien du
tout pour un halo décoratif.

### Conteneurs

La bordure 1 px cède la place à une ombre basse :
`0 1px 2px rgb(20 29 24 / .06), 0 6px 18px -10px rgb(20 29 24 / .16)`.
Rayon 12 px pour une carte, 11 px pour une bande, 9 px pour un élément de
navigation.

## Coquille

### Barre latérale — icônes

Les icônes viennent de **`@lucide/vue`, déjà installé** : aucune dépendance
ajoutée, ce que la règle du projet exige.

| Entrée | Icône |
|---|---|
| Tableau de bord | `LayoutDashboard` |
| Déclarer ce mois | `FilePlus2` |
| Historique | `History` |
| Mes assureurs | `Building2` |
| Exporter mes données | `Download` |
| Statistiques réseau | `ChartColumn` |
| Évolution | `TrendingUp` |
| Pharmacies inscrites | `Store` |
| Gestion des assureurs | `Building2` |
| Exports CSV | `Download` |

**L'icône est choisie côté PHP**, dans `ConsoleNavigation`, et voyage dans
`NavItem` sous une clé `icon`. Une table de correspondance côté Vue, indexée
sur le libellé, casserait le jour où un libellé est reformulé — et les
libellés ont déjà bougé dans ce projet. Conséquences : le type
`@phpstan-type NavItem` gagne `icon: string`, et `ConsoleShellTest` doit
asserter la présence de la clé.

Le composant Vue traduit la clé en composant Lucide par une table explicite ;
une clé inconnue ne rend **aucune** icône plutôt qu'une icône par défaut, pour
qu'un oubli se voie.

### Barre latérale — repli

- Déployée : 196 px, icône + libellé, bouton « Replier » en pied de barre.
- Repliée : 62 px, icônes centrées, libellé en infobulle CSS au survol et au
  focus. Le nom accessible reste porté par `aria-label`.
- État mémorisé par navigateur sous la clé `apha.sidebar.collapsed`
  (`localStorage`), pour survivre au changement de page sans aller-retour
  serveur. La lecture est enveloppée d'un `try/catch` : en navigation privée,
  l'accès peut lever, et la barre doit alors s'ouvrir déployée.
- **Le repli n'existe pas sous 1024 px.** À cette largeur la barre est déjà une
  bande horizontale ; lui ajouter un second comportement compliquerait sans
  rien gagner.

### En-tête et bandeau

`ConsoleHeader` gagne un slot `#hero`.

- **≥ 1024 px** : en-tête clair. Filet supérieur, remarque en capitales, titre
  serif, filtres à droite. Le contenu du slot `#hero` se rend sous le titre,
  en grille claire.
- **< 1024 px** : remarque, titre et slot `#hero` sont enveloppés dans un
  **bandeau vert profond** (`primary-dark`), texte blanc, rayon 16 px. Les
  cartes du slot deviennent transparentes.

C'est **un seul bloc qui change de forme**, pas deux chartes : la liste
d'alertes, l'échelle typographique, le rythme d'espacement et le serif sont
identiques des deux côtés. Seul l'en-tête se replie, parce qu'à 390 px trois
cartes empilées repoussent les alertes hors de l'écran.

Le seuil est **1024 px**, celui où `ConsoleLayout` bascule déjà en colonne. Pas
un seuil inventé pour l'occasion.

## Tableau de bord officine

### Bandes d'assureurs

- **En retard** : fond `terracotta` plein, texte blanc, une bande par assureur.
- **Doit sans retard** : fond `gold-soft`, filet `gold-mid`, texte `ink`.
- **Soldés** : bande verte de récapitulatif, inchangée dans son principe.
- Un assureur dont le facturé vaut zéro reste exclu de toutes les bandes.

### Panneaux supprimés

- « Suivez vos paiements » — titre, icône, sous-titre, pastille verte, aucune
  donnée.
- La note de bas de page « Les indicateurs sont calculés à partir des
  déclarations transmises » — conservée, mais réduite à une ligne de
  métadonnée sous le dernier bloc, sans conteneur.

Même traitement pour les panneaux d'introduction équivalents de
`admin/Network.vue` et `admin/Exports.vue` au lot 4 : le texte explicatif
survit, démoté sous le titre ; le conteneur disparaît.

## Découpage en lots

| Lot | Contenu | Fichiers principaux |
|---|---|---|
| 1 | Palette unifiée et accentuée, alias `--apha-*` centralisés, suppression des 7 copies, dégradés retirés, **définition** de l'échelle typographique | `app.css`, les 7 pages porteuses de copies, les 11 fichiers à dégradés |
| 2 | Coquille : icônes, repli, serif dans l'en-tête, slot `#hero`, bandeau sous 1024 px | `ConsoleNavigation.php`, `ConsoleSidebar.vue`, `ConsoleHeader.vue`, `ConsoleLayout.vue` |
| 3 | Tableau de bord officine : bandes rouge plein, ambre dilué, panneaux vides, couleurs en dur remplacées | `pharmacy/Dashboard.vue`, `PaymentJourneyController.php` si les tons remontent du serveur |
| 4 | Les seize autres écrans, alignés sur le socle | le reste de `resources/js/pages` |

**L'échelle typographique est définie au lot 1, appliquée aux lots 2 à 4.**
Correction apportée à la rédaction du plan : les tailles vivent sur les
éléments, pas dans les tokens. Les appliquer au lot 1 reviendrait à toucher la
coquille et les vingt écrans, ce qui viderait le découpage de son sens. Le lot
1 pose donc les tokens `--text-*` ; chaque écran les adopte quand il est
repris.

Chaque lot est livrable et vérifiable seul.

Le lot 1 se voit — couleurs et tailles changent partout — mais il **ne touche
à aucun balisage** : il ne déplace, n'ajoute ni ne supprime un seul élément.
C'est ce qui le rend sûr, et ce qui permet de le comparer par capture sans
avoir à démêler ce qui a bougé de ce qui a changé de teinte.

## Vérification

**Aucun test ne monte de composant dans ce projet** : Vitest y tourne en
environnement node sur de la logique pure, et le style n'est couvert par
aucune assertion. La vérification repose donc sur quatre appuis :

1. **Les 586 tests existants restent verts.** Ils portent sur le contenu et le
   comportement, pas sur l'apparence : une refonte visuelle qui les casse a
   touché autre chose que le visuel, et c'est précisément le signal utile.
2. **`vue-tsc --noEmit` et `npm run build`** à chaque lot.
3. **Captures Chromium avant/après** à 1440, 768 et 390 px pour chaque écran
   touché, comparées à l'œil. Le harnais existe déjà (profil persistant +
   `dev/login/{profile}`).
4. **Mesure du débordement horizontal** à chaque largeur. Le projet a déjà
   connu deux défauts responsive qui ne se voyaient pas autrement.

Tests **ajoutés** — les seuls que ce chantier justifie, parce qu'ils portent
sur des données, pas sur du style :

- `ConsoleShellTest` : chaque entrée de navigation porte une clé `icon` non
  vide, dans les deux espaces.
- `ConsoleShellTest` : la clé `icon` d'une entrée donnée vaut ce qu'on attend,
  pour qu'un renommage de libellé ne déplace pas silencieusement une icône.

## Ce que cette refonte ne fait pas

- Elle ne touche à **aucune règle métier**, à aucun calcul, à aucun agrégat.
- Elle ne modifie **aucune dépendance** : Lucide est déjà là.
- Elle ne corrige pas le défaut responsive de la rangée de téléchargement de
  `admin/Exports.vue` à 768 px, **sauf au lot 4**, où cet écran est repris.
  Le défaut est antérieur à ce chantier, vérifié par capture comparative.
- Elle n'introduit **pas de mode sombre**. Le projet est clair uniquement, et
  `app.blade.php` le dit explicitement.
