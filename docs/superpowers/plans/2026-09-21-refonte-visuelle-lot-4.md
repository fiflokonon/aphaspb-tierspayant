# Refonte visuelle — lot 4 : le socle sur tous les écrans

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Appliquer à tous les écrans ce que la spec appelle le socle — des
surfaces à ombre basse plutôt qu'à bordure, une échelle typographique avec du
serif sur les titres et les chiffres, et plus un seul littéral de couleur.

**Architecture:** Trois mouvements, du partagé vers le particulier. D'abord des
**jetons de surface** dans `app.css`, pour que « ombre basse, rayon 12 » soit
une valeur et non une recette recopiée quatre-vingts fois. Ensuite le retrait
des **sept panneaux d'introduction sans données** — le même patron que celui
supprimé du tableau de bord, répété sur six écrans de plus. Enfin l'application
écran par écran, groupée par espace. Le lot se referme sur une garde devenue
**globale** : la liste des fichiers assainis disparaît, puisqu'il ne reste plus
rien à assainir.

**Tech Stack:** Tailwind 4, CSS custom properties, Vue 3 SFC `<style scoped>`,
Pest 4, Chromium headless pour les captures.

**Spec:** `docs/superpowers/specs/2026-09-20-refonte-visuelle-design.md`

## Global Constraints

- **Aucune dépendance ajoutée ou modifiée** (règle projet, `CLAUDE.md`).
- **Aucune règle métier touchée.** Les 594 tests actuels restent verts sans
  qu'une assertion soit modifiée.
- **La couleur vient de `:root`.** `PaletteSourceTest` interdit déjà la
  redéclaration d'un token, la référence non résolue et le littéral dans les
  fichiers assainis.
- **Le projet est clair uniquement.** Le seuil responsive est **1024 px**, et
  s'écrit `max-width: 1023.98px` — la paire 1023/1024 laisse passer les
  largeurs fractionnaires, où deux états mutuellement exclusifs s'affichent
  ensemble.
- **Ne jamais viser une classe utilitaire Tailwind depuis `:deep()`.** Deux
  défauts de ce chantier en viennent. Un composant partagé expose une prop.
- **Ne jamais écrire `display: revert`** pour une propriété que la même feuille
  déclare : `revert` rend la valeur du navigateur, pas celle de l'auteur.
- Après toute modification PHP : `vendor/bin/pint --dirty --format agent`.

### Ce que la spec dit du socle, mot pour mot

> La bordure 1 px cède la place à une ombre basse :
> `0 1px 2px rgb(20 29 24 / .06), 0 6px 18px -10px rgb(20 29 24 / .16)`.
> Rayon 12 px pour une carte, 11 px pour une bande, 9 px pour un élément de
> navigation.

| Niveau | Police | Taille |
|---|---|---|
| Titre d'écran | Instrument Serif | 34 px (27 px sous 1024) |
| Chiffre clé | Instrument Serif | 29 px (21 px en bandeau) |
| Titre de section | Jakarta 700 | 17 px |
| Corps | Jakarta 400/500 | 14 px |
| Métadonnée | Jakarta 400 | 12,5 px |
| Étiquette capitale | Jakarta 700, `.14em` | 9,5 px |

### La distinction que la spec ne fait pas, et qu'il faut faire

Le dépôt porte **86 règles** avec `border: 1px solid`. Elles ne désignent pas
la même chose, et appliquer « la bordure cède la place à une ombre » à toutes
retirerait leur contour aux champs de saisie.

| Famille | Exemples relevés | Traitement |
|---|---|---|
| **Surface** — carte, panneau, section, tableau | `.export-card`, `.status-card`, `.configuration-card`, `.insurers-table-section`, `.trend-card`, `.declare-shell`, `.note-content`, `.catch-up`, `.convention`, `.message-error` | Bordure retirée, `box-shadow: var(--surface-shadow)`, `border-radius: var(--radius-card)` |
| **Contrôle** — champ, bouton, filtre | `.edit-input`, `.modern-input`, `.note-textarea`, `.number-input`, `.action-button`, `.secondary-action`, `.download-button-secondary`, `.search-box`, `.city-filter` | **Bordure conservée.** Un champ sans contour n'est plus un champ. |
| **Jeton** — puce, badge, compteur, avatar | `.column-tag`, `.status-badge`, `.intro-badge`, `.columns-count`, `.insurer-avatar`, `.declaration-count`, `.file-icon`, `.empty-icon` | **Bordure conservée**, rayon inchangé : ce sont des éléments de 20 à 40 px, une ombre n'y veut rien dire. |
| **Décor** — pseudo-éléments de fond | `.declare-page::before`, `.declare-page::after` | Examiner : la plupart ne peignent plus rien depuis le lot 1 et se suppriment. |

**Règle d'arrêt : une règle qui ne rentre dans aucune des quatre familles se
signale, elle ne s'improvise pas.**

### Les sept panneaux d'introduction

Le tableau de bord en portait un — icône, remarque en capitales, un `<h1>` qui
répétait le titre, une phrase, une pastille de statut, et **aucune donnée**. Le
lot 3 l'a supprimé. Le même patron existe sur six écrans de plus :

`admin/Exports` (`.exports-intro`), `admin/Insurers` (`.insurers-intro`),
`admin/Network` (`.network-intro`), `admin/Pharmacies` (`.pharmacies-intro`),
`admin/Trends` (`.evolution-intro`), `pharmacy/History` (`.history-intro`),
`pharmacy/Insurers` (`.insurers-intro`).

**Attention, ils ne sont pas tous équivalents.** Sur `admin/Network`,
`ConsoleHeader` affiche la **période** comme titre (« juillet → septembre
2026 ») et le `<h1>` du panneau — « Performance du réseau » — est le seul
endroit où l'écran porte son nom. Sur le tableau de bord, le titre de l'en-tête
et le `<h1>` disaient la même chose, d'où la suppression pure.

Pour chaque panneau : **si son `<h1>` nomme l'écran et que l'en-tête ne le
nomme pas, promouvoir ce nom dans `ConsoleHeader` avant de supprimer le
panneau.** Sinon, supprimer. La phrase de prose suit le sort du panneau, sauf
lorsqu'elle explique une règle métier (l'anonymat, le rattrapage) : elle
devient alors une ligne de métadonnée, comme `.dashboard-source`.

### Le harnais de capture

`php artisan serve --port=8000` doit tourner. Si `public/hot` existe, vérifier
que le port qu'il annonce écoute réellement — sinon **toutes les captures
sortent uniformément crème** et ne prouvent rien. Sans `public/hot`,
l'application sert les assets construits : `npm run build` après chaque
modification.

```bash
shot() {  # shot <profil> <nom> <url> <largeur>
  chromium --headless --disable-gpu --user-data-dir="/tmp/apha-prof-$1" \
    --virtual-time-budget=8000 --screenshot="/tmp/apha-shots/$2-$4.png" \
    --window-size="$4,1400" "$3" 2>/dev/null
}
```

Seize écrans à trois largeurs font quarante-huit captures. Ne pas toutes les
regarder : comparer les **couleurs dominantes** avant/après par histogramme,
et n'ouvrir que celles dont la distribution a bougé autrement que prévu.

```bash
python3 - "$1" "$2" <<'PY'
import sys
from PIL import Image
from collections import Counter
for p in sys.argv[1:]:
    c = Counter(Image.open(p).convert('RGB').getdata())
    print(p, [('#%02x%02x%02x' % k, v) for k, v in c.most_common(4)])
PY
```

## File Structure

| Fichier | Rôle |
|---|---|
| `resources/css/app.css` | Gagne `--surface-shadow`, `--radius-card`, `--radius-band`, `--radius-nav`. |
| `resources/js/pages/pharmacy/Dashboard.vue` | Conteneurs et échelle typographique — le lot 3 ne l'a aligné que sur la couleur. |
| `resources/js/pages/pharmacy/{Declare,History,Insurers,Insurer,Exports,DeclareDone}.vue` | Socle appliqué, panneaux d'introduction retirés, littéraux remplacés. |
| `resources/js/pages/admin/{Network,Trends,Pharmacies,Insurers,Exports}.vue` | Idem. |
| `resources/js/pages/notifications/Index.vue`, `resources/js/components/aphaspb/PaymentInstalments.vue` | Littéraux et conteneurs. |
| `tests/Feature/Design/PaletteSourceTest.php` | La liste `$cleaned` disparaît : la garde devient globale. |

---

### Task 1 : Les jetons de surface

« Ombre basse, rayon 12 » doit être une valeur, pas une recette recopiée. Les
lots suivants citent ces jetons ; personne ne réécrit l'ombre.

**Files:**
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: les tokens de couleur du lot 1.
- Produces: `--surface-shadow`, `--surface-shadow-raised`, `--radius-card`,
  `--radius-band`, `--radius-nav`, consommés par les tâches 2 à 6.

- [ ] **Step 1 : Ajouter les jetons à `:root`**

Après le bloc des alias `--apha-*`, avant l'accolade fermante :

```css
    /*
      Les surfaces du socle. La spec remplace la bordure 1 px par une ombre
      basse : une bordure dessine un rectangle, une ombre pose un plan. Les
      valeurs sont celles de la spec, au chiffre près.

      Trois rayons et pas un : une carte (12), une bande d'alerte (11), un
      élément de navigation (9). Un rayon unique sur des objets de tailles si
      différentes se lit comme une erreur de mesure.
    */
    --surface-shadow:
        0 1px 2px rgb(20 29 24 / 0.06), 0 6px 18px -10px rgb(20 29 24 / 0.16);
    --surface-shadow-raised:
        0 2px 4px rgb(20 29 24 / 0.07), 0 14px 30px -12px rgb(20 29 24 / 0.22);
    --radius-card: 12px;
    --radius-band: 11px;
    --radius-nav: 9px;
```

`--surface-shadow-raised` sert aux survols qui élevaient déjà la carte ; sans
lui, chaque écran réinventerait son ombre de survol.

- [ ] **Step 2 : Vérifier que la garde accepte ces valeurs**

`rgb(20 29 24 / …)` est de l'encre en clair, et la garde interdit les
littéraux — mais `:root` est dépouillé avant l'examen, donc ces déclarations
sont hors de portée. Le confirmer plutôt que le supposer :

```bash
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : 5 tests verts. Si le test rougit sur `app.css`, le dépouillement de
`:root` ne fonctionne pas et **il faut le réparer avant d'aller plus loin** —
sans lui, tout ce lot travaillera sans filet.

- [ ] **Step 3 : Construire**

```bash
npm run build
```

Attendu : build réussi. Aucun élément ne consomme encore ces jetons, donc
aucun changement visible.

- [ ] **Step 4 : Commit**

```bash
git add resources/css/app.css
git commit -m "style: poser les jetons de surface du socle

Ombre basse et trois rayons, aux valeurs de la spec. Une valeur plutôt
qu'une recette : quatre-vingts règles vont les citer, et un retouche future
ne doit pas être une chasse.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2 : Finir le tableau de bord

Les lots précédents l'ont aligné sur la couleur, pas sur la forme. C'est
l'écran que le client regarde, et c'est la cause que la spec nomme en premier :
*« Tout est une boîte. »* Il passe en premier pour que la suite ait un
étalon.

**Files:**
- Modify: `resources/js/pages/pharmacy/Dashboard.vue`
- Modify: `resources/js/components/aphaspb/KpiCard.vue`
- Modify: `resources/js/layouts/console/ConsoleSidebar.vue`

**Interfaces:**
- Consumes: les jetons de la tâche 1.
- Produces: rien de nommé. Sert d'étalon visuel aux tâches 4 et 5 — les deux
  suivantes s'y réfèrent pour juger « à quoi doit ressembler une surface ».

- [ ] **Step 1 : Relever l'état de référence**

```bash
for w in 1440 390; do shot officine dash-avant 'http://localhost:8000/' $w; done
```

- [ ] **Step 2 : Lister les règles à surface**

```bash
awk '/<style/,0' resources/js/pages/pharmacy/Dashboard.vue \
  | grep -n -B14 'border: 1px solid' | grep -E '^[0-9]+-\.[a-z-]+ \{'
```

Classer chacune selon la table des quatre familles. Les surfaces attendues :
`.catch-up`, `.dashboard-card`, `.overdue-section`. `.catch-up-month` est un
jeton — il garde sa bordure.

- [ ] **Step 3 : Appliquer le socle aux surfaces**

Pour chaque règle de famille **surface**, remplacer :

```css
    border: 1px solid var(--border);
    border-radius: 17px;
    box-shadow: 0 8px 30px color-mix(in srgb, var(--ink) 3.5%, transparent);
```

par :

```css
    border-radius: var(--radius-card);
    box-shadow: var(--surface-shadow);
```

et, là où un survol élevait la carte, `var(--surface-shadow-raised)`.

- [ ] **Step 3 bis : Citer les deux autres rayons là où ils s'appliquent**

Sinon `--radius-band` et `--radius-nav` sont des jetons que personne ne lit,
et la prochaine retouche les manquera. Le tableau de bord porte déjà 11 px
sur `.insurer-banner` et 10 px sur `.catch-up-month` : le premier cite
`var(--radius-band)`, le second `var(--radius-nav)` — c'est un élément
cliquable de taille de navigation, et 9 px au lieu de 10 est l'écart que la
spec assume.

`ConsoleSidebar` porte 11 px sur `.apha-nav-item` : il cite
`var(--radius-nav)` dans le même mouvement. Relever d'abord :

```bash
grep -n 'border-radius: 1[01]px' resources/js/pages/pharmacy/Dashboard.vue \
  resources/js/layouts/console/ConsoleSidebar.vue
```

Les rayons de 20 px (`.dashboard-card` interne, `.journey-card`) et de 50 %
(pastilles rondes) **ne sont pas** des rayons de socle : les laisser.

- [ ] **Step 4 : Appliquer l'échelle typographique**

Relever les tailles actuelles puis les porter à celles de la table :

```bash
awk '/<style/,0' resources/js/pages/pharmacy/Dashboard.vue | grep -n 'font-size'
```

Les correspondances de cet écran : `.card-header h2` → 17 px ;
`.card-header p` → 12,5 px ; `.owed-name`, `.recovery-*` → 14 px ;
`.ageing-label`, `.card-badge` → 9,5 px avec `letter-spacing: 0.14em`.

**Le chiffre clé passe en serif.** Dans `KpiCard`, la valeur porte
`text-[28px]/none font-extrabold` : elle devient
`font-serif text-[29px]/none` — et **sans `font-extrabold`**, Instrument
Serif n'ayant qu'une graisse, qu'une demande de gras rendrait synthétique et
baveuse. Dans le bandeau, 21 px au lieu de 19.

- [ ] **Step 5 : Construire et comparer**

```bash
npm run build
npx vue-tsc --noEmit
for w in 1440 768 390; do shot officine dash-apres 'http://localhost:8000/' $w; done
```

Attendu : plus une seule bordure de carte, les panneaux posés par leur ombre,
les chiffres en serif. Comparer `dash-apres-1440.png` à `dash-avant-1440.png`
à l'œil — c'est le seul écran de ce lot qu'on regarde entièrement.

- [ ] **Step 6 : Vérifier le contraste du serif**

Instrument Serif a des fûts plus fins que Jakarta ; à couleur égale il paraît
plus clair. Les chiffres portent `kpiToneClass` (bon, alerte, neutre) :

```bash
python3 - <<'PY'
def lum(c):
    def f(v):
        v /= 255
        return v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2])
def ratio(a, b):
    la, lb = sorted((lum(a), lum(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)
def hx(h): return tuple(int(h[i:i+2], 16) for i in (1, 3, 5))
for name, fg in (('bon', '#14764c'), ('alerte', '#e0a516'), ('mauvais', '#d13b22')):
    print(f"{name:8s} sur blanc : {ratio(hx(fg), hx('#ffffff')):.2f}:1")
PY
```

Attendu : le vert et le terracotta passent AA ; **l'or ne le passe pas**
(≈2,3:1). Il ne le passait pas davantage en Jakarta — c'est un défaut
antérieur, à signaler, pas à corriger en douce dans un lot de forme.

- [ ] **Step 7 : Lancer la suite**

```bash
php artisan test --compact
npx vitest run
```

- [ ] **Step 8 : Commit**

```bash
git add resources/js/pages/pharmacy/Dashboard.vue \
    resources/js/components/aphaspb/KpiCard.vue \
    resources/js/layouts/console/ConsoleSidebar.vue
git commit -m "style: appliquer le socle au tableau de bord

Les surfaces perdent leur bordure au profit de l'ombre basse et du rayon 12,
les chiffres clés passent en Instrument Serif, l'échelle typographique de la
spec remplace les tailles héritées. Les lots 1 à 3 n'avaient aligné cet
écran que sur la couleur, alors que la spec nomme la forme en premier.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3 : Retirer les six panneaux d'introduction restants

Même patron que celui supprimé du tableau de bord, répété six fois. C'est la
plus grosse contribution unique à *« neuf panneaux empilés »*.

**Files:**
- Modify: `resources/js/pages/admin/{Exports,Insurers,Network,Pharmacies,Trends}.vue`
- Modify: `resources/js/pages/pharmacy/{History,Insurers}.vue`

**Interfaces:**
- Consumes: rien.
- Produces: rien de nommé.

- [ ] **Step 1 : Relever, pour chaque écran, ce que le panneau porte**

```bash
for f in admin/Exports admin/Insurers admin/Network admin/Pharmacies \
         admin/Trends pharmacy/History pharmacy/Insurers; do
  echo "########## $f ##########"
  grep -n 'ConsoleHeader' "resources/js/pages/$f.vue" | head -2
  sed -n '/class="[a-z]*-intro"/,/<\/section>/p' "resources/js/pages/$f.vue" \
    | grep -E '<h1>|<p>|\{\{' | head -6
done
```

Pour chacun, répondre à **une** question : le `<h1>` du panneau nomme-t-il
l'écran d'un nom que `ConsoleHeader` ne donne pas déjà ?

- [ ] **Step 2 : Promouvoir le nom là où c'est nécessaire**

Cas connu : `admin/Network` affiche la période comme titre d'en-tête, et son
panneau porte « Performance du réseau ». Le titre de l'en-tête devient donc le
nom de l'écran, et la période descend en remarque :

```html
        <ConsoleHeader
            eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
            title="Performance du réseau"
            class="network-header"
        >
```

La période est déjà lisible dans les filtres et dans le bandeau de portée —
elle n'a pas besoin d'être le titre.

**Vérifier écran par écran** : si l'en-tête nomme déjà l'écran, supprimer sans
promouvoir.

- [ ] **Step 3 : Supprimer les panneaux**

Retirer le `<section class="*-intro"> … </section>` en entier, puis ses règles
de style, **y compris dans les requêtes média**. Le lot 3 a montré qu'une
suppression au jugé emporte des accolades fermantes et des règles vivantes :

```bash
for c in intro-content intro-main intro-left intro-icon intro-text \
         intro-label intro-eyebrow intro-status intro-badge intro-glow \
         privacy-badge network-status; do
  echo "--- .$c ---"; grep -rn "\.$c\b" resources/js/pages --include='*.vue'
done
```

Ne supprimer une règle que si **aucun template ne la référence plus**, et
n'élaguer d'un sélecteur groupé que la part morte.

- [ ] **Step 4 : Conserver ce qui explique une règle métier**

Sur `admin/Network` et `admin/Exports`, la phrase du panneau explique
l'anonymat. Elle devient une ligne de métadonnée, sur le modèle du tableau de
bord :

```html
        <p class="page-source">
            Les indicateurs sont calculés à partir des déclarations transmises
            par les officines participantes. Les données individuelles ne sont
            jamais exposées.
        </p>
```

```css
.page-source {
    margin-top: 16px;

    color: var(--muted);

    font-size: 12.5px;
    line-height: 1.5;
}
```

- [ ] **Step 5 : Vérifier les accolades et le rendu**

```bash
npm run build
npx vue-tsc --noEmit
python3 - <<'PY'
import pathlib
for f in sorted(pathlib.Path('resources/js/pages').rglob('*.vue')):
    s = f.read_text()
    i = s.find('<style')
    if i < 0:
        continue
    body = s[i:]
    if body.count('{') != body.count('}'):
        print('DESEQUILIBRE :', f, body.count('{'), body.count('}'))
print('controle des accolades termine')
PY
```

- [ ] **Step 6 : Capturer les sept écrans**

```bash
shot admin net 'http://localhost:8000/admin/network' 1440
shot admin tre 'http://localhost:8000/admin/trends' 1440
shot admin pha 'http://localhost:8000/admin/pharmacies' 1440
shot admin ins 'http://localhost:8000/admin/insurers' 1440
shot admin exp 'http://localhost:8000/admin/csv-exports' 1440
shot officine his 'http://localhost:8000/pharmacy/history' 1440
shot officine ino 'http://localhost:8000/pharmacy/insurers' 1440
```

Attendu, sur chacun : le contenu utile commence directement sous l'en-tête,
et **chaque écran porte toujours son nom** quelque part.

- [ ] **Step 7 : Lancer la suite et commit**

```bash
php artisan test --compact
git add resources/js/pages
git commit -m "style: retirer les six panneaux d'introduction sans données

Même patron que celui supprimé du tableau de bord : une icône, un h1 qui
répète le titre, une phrase, une pastille, aucune donnée. C'est la plus
grosse contribution unique au « tout est une boîte » que la spec nomme en
premier.

Sur admin/Network le h1 était le seul endroit où l'écran portait son nom,
l'en-tête affichant la période : le nom est promu dans l'en-tête avant la
suppression. Les phrases qui expliquent l'anonymat survivent en ligne de
métadonnée.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4 : Le socle sur les écrans officine

**Files:**
- Modify: `resources/js/pages/pharmacy/{Declare,History,Insurers,Insurer,Exports,DeclareDone}.vue`

**Interfaces:**
- Consumes: les jetons de la tâche 1, l'étalon posé par la tâche 2.
- Produces: rien de nommé.

- [ ] **Step 1 : Classer les règles à bordure de ces six fichiers**

```bash
for f in Declare History Insurers Insurer Exports DeclareDone; do
  echo "########## $f ##########"
  awk '/<style/,0' "resources/js/pages/pharmacy/$f.vue" \
    | grep -B14 'border: 1px solid' | grep -E '^\.[a-z-]+[.: ]?[a-z-]* \{'
done
```

Appliquer la table des quatre familles du haut de plan : surface → ombre et
`--radius-card` ; contrôle et jeton → bordure conservée ; décor → examiner.
**Une règle hors des quatre familles se signale.**

- [ ] **Step 2 : Appliquer l'échelle typographique**

Les correspondances, identiques d'un écran à l'autre : titre de section
17 px ; corps 14 px ; métadonnée 12,5 px ; étiquette capitale 9,5 px avec
`letter-spacing: 0.14em`. Les tailles inférieures à 10 px qui ne sont pas des
étiquettes remontent à 12,5 px.

- [ ] **Step 3 : Remplacer les littéraux restants**

`Declare.vue` en porte 44, `Exports.vue` 25, `Insurers.vue` 20, `History.vue`
20, `Insurer.vue` 1. Les correspondances :

| Littéral | Remplacement |
|---|---|
| `rgba(0, 143, 131, N)` | `color-mix(in srgb, var(--officine) N%, transparent)` |
| `rgba(35, 70, 68, N)` ou `rgba(23, 33, 28, N)` | `color-mix(in srgb, var(--ink) N%, transparent)` |
| `rgba(215, 163, 61, N)` | `color-mix(in srgb, var(--gold-mid) N%, transparent)` |
| `rgba(192, 71, 47, N)` | `color-mix(in srgb, var(--terracotta) N%, transparent)` |
| `#788585`, `#9aa5a5` | `var(--muted)` |
| `#a2adad` | `var(--light)` |
| `#f5f8f7`, `#f7faf9`, `#f9fcfb` | `var(--cream-state)` |
| `#e8f6f3`, `#e6f6f2` | `var(--primary-soft)` |
| `#fff8e9`, `#fffdf7` | `var(--gold-soft)` |
| `#008f83`, `#35a799` | `var(--officine)` |
| `#006f68` | `var(--officine-dark)` |

**Un littéral hors de cette table se signale.** Inventer une correspondance
recrée la dérive que le lot 1 a passé six commits à effacer.

- [ ] **Step 4 : Vérifier**

```bash
npm run build
npx vue-tsc --noEmit
awk '/<style/,0' resources/js/pages/pharmacy/Declare.vue \
  | perl -0pe 's{/\*.*?\*/}{}gs' \
  | grep -nE '#[0-9a-fA-F]{3,8}\b|rgba?\([0-9]' \
  | grep -viE '#fff\b|#ffffff|rgb\(255 255 255' || echo "Declare : aucun littéral"
```

Répéter pour les six fichiers.

- [ ] **Step 5 : Capturer et comparer par histogramme**

```bash
for p in '' pharmacy/declare pharmacy/history pharmacy/insurers pharmacy/exports; do
  for w in 1440 390; do shot officine "off-${p//\//-}" "http://localhost:8000/$p" $w; done
done
```

Comparer les couleurs dominantes avant/après. N'ouvrir que les captures dont
la distribution a bougé autrement que par la disparition d'une bordure.

- [ ] **Step 6 : Lancer la suite et commit**

```bash
php artisan test --compact
git add resources/js/pages/pharmacy
git commit -m "style: appliquer le socle aux écrans officine

Surfaces posées par leur ombre, contrôles et jetons conservant leur
contour — un champ sans bordure n'est plus un champ, et la spec ne fait pas
cette distinction. Échelle typographique appliquée, 110 littéraux remplacés.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5 : Le socle sur les écrans réseau

**Files:**
- Modify: `resources/js/pages/admin/{Network,Trends,Pharmacies,Insurers,Exports}.vue`

**Interfaces:**
- Consumes: les jetons de la tâche 1, l'étalon de la tâche 2.
- Produces: rien de nommé.

- [ ] **Step 1 : Classer les règles à bordure**

```bash
for f in Network Trends Pharmacies Insurers Exports; do
  echo "########## $f ##########"
  awk '/<style/,0' "resources/js/pages/admin/$f.vue" \
    | grep -B14 'border: 1px solid' | grep -E '^\.[a-z-]+[.: ]?[a-z-]* \{'
done
```

Appliquer la table des quatre familles : surface → ombre et `--radius-card` ;
contrôle et jeton → bordure conservée ; décor → examiner. **Une règle hors
des quatre familles se signale.**

- [ ] **Step 2 : Appliquer l'échelle typographique**

Mêmes correspondances qu'à la tâche 4 : section 17 px, corps 14 px,
métadonnée 12,5 px, étiquette capitale 9,5 px avec `letter-spacing: 0.14em`.

Ces écrans portent des **tableaux** : leurs en-têtes de colonne sont des
étiquettes capitales (9,5 px), leurs cellules du corps (14 px). Ne pas
grossir une cellule numérique au point de casser l'alignement des colonnes —
vérifier la largeur du tableau après coup.

- [ ] **Step 3 : Remplacer les littéraux**

`Insurers.vue` en porte 43, `Exports.vue` 36, `Trends.vue` 23,
`Pharmacies.vue` 19, `Network.vue` 18. Appliquer **la même table qu'à la
tâche 4, étape 3** — elle est reproduite ici pour que cette tâche se lise
seule :

| Littéral | Remplacement |
|---|---|
| `rgba(0, 143, 131, N)` | `color-mix(in srgb, var(--officine) N%, transparent)` |
| `rgba(35, 70, 68, N)` ou `rgba(23, 33, 28, N)` | `color-mix(in srgb, var(--ink) N%, transparent)` |
| `rgba(215, 163, 61, N)` | `color-mix(in srgb, var(--gold-mid) N%, transparent)` |
| `rgba(192, 71, 47, N)` | `color-mix(in srgb, var(--terracotta) N%, transparent)` |
| `#788585`, `#9aa5a5` | `var(--muted)` |
| `#a2adad` | `var(--light)` |
| `#f5f8f7`, `#f7faf9`, `#f9fcfb` | `var(--cream-state)` |
| `#e8f6f3`, `#e6f6f2` | `var(--primary-soft)` |
| `#fff8e9`, `#fffdf7` | `var(--gold-soft)` |
| `#008f83`, `#35a799` | `var(--officine)` |
| `#006f68` | `var(--officine-dark)` |

- [ ] **Step 4 : Vérifier**

```bash
npm run build
npx vue-tsc --noEmit
for f in Network Trends Pharmacies Insurers Exports; do
  n=$(awk '/<style/,0' "resources/js/pages/admin/$f.vue" \
      | perl -0pe 's{/\*.*?\*/}{}gs' \
      | grep -oE '#[0-9a-fA-F]{3,8}\b|rgba?\([0-9]' \
      | grep -viE '^#fff$|^#ffffff$' | wc -l)
  echo "$f : $n littéral(aux) restant(s)"
done
```

Attendu : zéro partout.

- [ ] **Step 5 : Capturer, en vérifiant les tableaux à 1440 et 768**

```bash
for p in network trends pharmacies insurers csv-exports; do
  for w in 1440 768; do shot admin "adm-$p" "http://localhost:8000/admin/$p" $w; done
done
```

Attendu : aucune colonne tronquée, aucun débordement horizontal. Le corps
passant de ~11 à 14 px, une colonne serrée peut céder — l'élargir plutôt que
rapetisser le texte.

- [ ] **Step 6 : Lancer la suite et commit**

```bash
php artisan test --compact
git add resources/js/pages/admin
git commit -m "style: appliquer le socle aux écrans réseau

Surfaces, échelle typographique et 139 littéraux. Les cellules de tableau
passent de ~11 à 14 px : les largeurs de colonne ont été vérifiées à 1440 et
768, aucune ne cède.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6 : Les derniers fichiers, et la garde devient globale

Le lot se referme sur la disparition de la liste `$cleaned`. Tant qu'elle
existe, elle dit « le reste n'est pas garanti ».

**Files:**
- Modify: `resources/js/pages/notifications/Index.vue`
- Modify: `resources/js/components/aphaspb/PaymentInstalments.vue`
- Modify: `tests/Feature/Design/PaletteSourceTest.php`

**Interfaces:**
- Consumes: l'état laissé par les tâches 2 à 5.
- Produces: une garde sans liste, qui couvre `resources/js` en entier.

- [ ] **Step 1 : Traiter les deux derniers fichiers**

`notifications/Index.vue` porte 11 littéraux, `PaymentInstalments.vue` 12, et
chacun une règle à bordure. Appliquer la table des quatre familles et la table
des littéraux de la tâche 4.

- [ ] **Step 2 : Vérifier qu'il ne reste rien, partout**

```bash
python3 - <<'PY'
import pathlib, re
reste = []
for f in sorted(pathlib.Path('resources/js').rglob('*.vue')):
    s = f.read_text()
    i = s.find('<style')
    if i < 0:
        continue
    body = re.sub(r'/\*.*?\*/', '', s[i:], flags=re.S)
    for lit in re.findall(r'#[0-9a-f]{3,8}\b|rgba?\([^)]*\)', body, re.I):
        canon = re.sub(r'[\s,]+', ' ', lit.lower())
        if re.match(r'^#(fff|ffffff|000|000000)$', canon) or \
           re.match(r'^rgba?\( ?(255 255 255|0 0 0)\b', canon):
            continue
        reste.append(f"{f.relative_to('resources/js')} → {lit}")
print('\n'.join(reste) if reste else 'aucun littéral dans resources/js')
PY
```

Attendu : `aucun littéral dans resources/js`. Tout ce qui reste doit être
traité ici, pas ajouté à une exception.

- [ ] **Step 3 : Retirer la liste de la garde**

Dans `tests/Feature/Design/PaletteSourceTest.php`, remplacer le parcours de
`$cleaned` par un parcours de tout `resources/js`, en conservant `app.css` :

```php
    $files = ['css/app.css'];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() === 'vue') {
            $files[] = 'js/'.str_replace(resource_path('js').'/', '', $file->getPathname());
        }
    }
```

et remplacer le commentaire d'ouverture :

```php
    // La dérive est revenue par là : les tokens étaient propres, et 293
    // hexadécimaux vivaient dans les pages. Interdire le littéral est le seul
    // moyen de rendre le nettoyage durable — les autres cas n'interdisent que
    // de *redéclarer* un token, pas d'en réécrire la valeur.
    //
    // La liste des fichiers couverts a disparu au lot 4 : elle s'allongeait à
    // chaque lot, et tant qu'elle existait elle disait « le reste n'est pas
    // garanti ». La garde couvre désormais resources/js en entier.
```

- [ ] **Step 4 : Lancer, et vérifier que la garde mord partout**

```bash
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : vert. Puis la mutation, sur un fichier que la liste **ne couvrait
pas** auparavant — c'est le point de la tâche :

```bash
cp resources/js/pages/admin/Trends.vue /tmp/trends.bak
python3 -c "
import pathlib
p = pathlib.Path('resources/js/pages/admin/Trends.vue')
s = p.read_text()
i = s.index('<style')
j = s.index('>', i) + 1
p.write_text(s[:j] + '\n.essai-de-derive {\n    color: #008f83;\n}\n' + s[j:])
"
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : **ROUGE**, citant `pages/admin/Trends.vue → #008f83`.

```bash
cp /tmp/trends.bak resources/js/pages/admin/Trends.vue
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : vert. **Copie de sauvegarde, jamais `git checkout`** : au lot 1, un
`git checkout` sur un fichier portant du travail non indexé a écrasé une
suppression qu'il a fallu refaire.

- [ ] **Step 5 : Vérification complète**

```bash
php artisan test --compact
npx vitest run
npx vue-tsc --noEmit
npm run build
npx prettier --check resources/js resources/css
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --dirty --format agent
```

Attendu : 594 tests Pest, 14 Vitest, zéro erreur partout. **Lire la sortie de
`vue-tsc`, ne pas l'enchaîner derrière un `echo` de succès** — une erreur de
type est passée plusieurs commits au lot 3 par ce biais.

- [ ] **Step 6 : Commit**

```bash
git add resources/js tests/Feature/Design/PaletteSourceTest.php
git commit -m "test: la garde des littéraux couvre désormais tout resources/js

La liste des fichiers assainis a disparu : elle s'allongeait à chaque lot, et
tant qu'elle existait elle disait « le reste n'est pas garanti ». Vérifié par
mutation sur un fichier qu'elle ne couvrait pas.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 7 : Consigner la règle**

Avec l'outil Boost `record-rule` :

- glob : `resources/css/**, resources/js/**`
- titre : `Le socle visuel : surfaces, échelle, et une garde sans liste`
- note : les quatre familles de bordure — surface, contrôle, jeton, décor —
  et le fait que seule la première perd son contour ; les jetons
  `--surface-shadow`, `--radius-card` / `--radius-band` / `--radius-nav` et
  l'interdiction de réécrire l'ombre à la main ; l'échelle typographique et le
  serif sans `font-bold` ; le seuil responsive écrit `1023.98px` ; et le fait
  que `PaletteSourceTest` couvre désormais tout `resources/js`, sans liste
  d'exceptions — un littéral nouveau le fait rougir où qu'il soit.
