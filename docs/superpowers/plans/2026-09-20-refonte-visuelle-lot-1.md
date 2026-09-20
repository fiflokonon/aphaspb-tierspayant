# Refonte visuelle — lot 1 : socle de couleur

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire de `resources/css/app.css` la source unique de la couleur et de
l'échelle typographique, supprimer la palette turquoise dupliquée dans sept
pages, et retirer les 81 dégradés.

**Architecture:** Trois mouvements, dans cet ordre, chacun vérifiable seul.
D'abord on **ajoute** les tokens à `:root` sans rien retirer — les pages
surchargent encore localement, donc rien ne bouge à l'écran et le filet est en
place. Ensuite on **supprime** les sept copies locales : les pages tombent d'un
coup sur les tokens globaux, et le turquoise disparaît. Enfin on aplatit les
dégradés, fichier par fichier. Aucun balisage n'est touché de tout le lot.

**Tech Stack:** Tailwind 4 (`@theme inline`), CSS custom properties, Vue 3 SFC
`<style scoped>`, Pest 4, Chromium headless pour les captures.

**Spec:** `docs/superpowers/specs/2026-09-20-refonte-visuelle-design.md`

## Global Constraints

- **Aucun balisage ne change.** Pas un élément ajouté, déplacé ou supprimé.
  Toute modification est une valeur CSS. C'est ce qui rend la comparaison par
  capture lisible : ce qui bouge est une teinte, jamais une position.
- **Aucune dépendance ajoutée ou modifiée** (règle projet, `CLAUDE.md`).
- **Aucune règle métier touchée.** Les 586 tests existants doivent rester verts
  sans qu'une seule assertion soit modifiée. Un test qui rougit signale qu'on a
  touché autre chose que du style.
- **Le projet est clair uniquement.** Pas de variante sombre, pas de
  `prefers-color-scheme` (`resources/views/app.blade.php` le dit).
- **Écrire les valeurs exactes de la spec**, reproduites ci-dessous. Ne pas
  « approcher » une teinte.
- Après toute modification PHP : `vendor/bin/pint --dirty --format agent`.
  Aucun fichier PHP n'est touché dans ce lot, la règle est rappelée pour mémoire.

### Palette cible, valeurs exactes

| Token | Valeur |
|---|---|
| `--officine` | `#14764c` |
| `--officine-dark` | `#0e5a3a` |
| `--officine-soft` | `#e6f3ec` |
| `--gold` | `#f0c53f` |
| `--gold-mid` | `#e0a516` |
| `--gold-soft` | `#fdf7e6` |
| `--gold-dark` | `#b07c1a` (inchangé) |
| `--terracotta` | `#d13b22` |
| `--terracotta-dark` | `#ad2c16` |
| `--terracotta-soft` | `#fdf0ed` — consommé au lot 3, défini ici pour que la palette tienne en un bloc |
| `--ink` | `#141d18` |
| `--cream` | `#fdfbf7` (inchangé) |
| `--cream-header` | `#f7f5f0` (inchangé) |
| `--cream-state` | `#faf8f3` (inchangé) |

### Écart assumé avec la spec

La spec range « échelle typographique » dans le lot 1. Les tailles vivent sur
les éléments, pas dans les tokens : les appliquer reviendrait à toucher la
coquille (lot 2) et les vingt écrans (lots 3–4), ce qui viderait le découpage
de son sens. **Le lot 1 définit les tokens typographiques ; leur application
part avec les écrans qui les portent.** La spec a été corrigée en ce sens.

## File Structure

| Fichier | Rôle après le lot |
|---|---|
| `resources/css/app.css` | **Source unique** de la couleur et de l'échelle typographique. Gagne les valeurs accentuées, les alias `--apha-*` et les tokens `--text-*`. |
| `resources/js/pages/pharmacy/Insurers.vue` | Perd sa copie locale de palette (l. 247–258) et ses 13 dégradés. |
| `resources/js/pages/pharmacy/History.vue` | Perd sa copie locale (l. 376–388) et ses 11 dégradés. |
| `resources/js/pages/admin/Pharmacies.vue` | Perd sa copie locale (l. 267–278) et ses 6 dégradés. |
| `resources/js/pages/admin/Network.vue` | Perd sa copie locale (l. 469–480) et ses 7 dégradés. |
| `resources/js/pages/admin/Insurers.vue` | Perd sa copie locale (l. 610–621) et ses 9 dégradés. |
| `resources/js/pages/admin/Exports.vue` | Perd sa copie locale (l. 331–342) et ses 6 dégradés. |
| `resources/js/pages/admin/Trends.vue` | Perd sa copie locale (l. 574–585) et ses 7 dégradés. |
| `resources/js/pages/pharmacy/Dashboard.vue` | Perd ses 6 dégradés. Pas de copie locale. |
| `resources/js/pages/pharmacy/Declare.vue` | Perd ses 7 dégradés. Pas de copie locale. |
| `resources/js/layouts/console/ConsoleLayout.vue` | Perd ses 2 dégradés. |
| `resources/js/layouts/console/ConsoleSidebar.vue` | Perd ses 7 dégradés. |
| `tests/Feature/Design/PaletteSourceTest.php` | **Créé.** Interdit la réapparition d'une définition locale de `--apha-*`. |

Les numéros de ligne sont ceux du dépôt au 20/09/2026, avant toute
modification. Ils se décalent dès la première tâche : les retrouver par
`grep -n`, jamais les appliquer de mémoire.

### Les cinq motifs de dégradé, et leur remplacement

Ces cinq motifs couvrent les 81 occurrences du dépôt. Chaque tâche de
dégradé applique cette table, sans en inventer une sixième.

| # | Motif rencontré | Remplacement |
|---|---|---|
| 1 | `radial-gradient(circle …, rgba(0,143,131,.0XX), transparent NN%)` — halo décoratif sur un fond de page | **Supprimer la déclaration entière.** Le fond retombe sur le crème. |
| 2 | `linear-gradient(110deg, #ffffff 0%, #f9fcfb 100%)` — faux-blanc de carte | `background: #fff;` |
| 3 | `linear-gradient(135deg, var(--primary), var(--primary-dark))` — bouton ou pastille | `background: var(--primary);` |
| 4 | `linear-gradient(90deg, var(--primary), #35a799, var(--gold))` — liseré multicolore en tête de carte | `background: var(--primary);` |
| 5 | `linear-gradient(90deg, #d7a33d, #e6bf63)` — barre de progression | `background: var(--gold-mid);` |

Un dégradé déjà en commentaire est supprimé avec son commentaire : il ne
documente rien qu'on veuille garder.

### Le harnais de capture

Utilisé à plusieurs tâches. Le profil Chromium persiste la session, sinon
chaque capture repart déconnectée.

```bash
mkdir -p /tmp/apha-shots /tmp/apha-prof-admin /tmp/apha-prof-officine

# Ouvrir une session, une fois par profil
chromium --headless --disable-gpu --user-data-dir=/tmp/apha-prof-admin \
  --virtual-time-budget=5000 --screenshot=/tmp/apha-shots/_login.png \
  --window-size=1440,900 'http://localhost:8000/dev/login/admin'

chromium --headless --disable-gpu --user-data-dir=/tmp/apha-prof-officine \
  --virtual-time-budget=5000 --screenshot=/tmp/apha-shots/_login2.png \
  --window-size=1440,900 'http://localhost:8000/dev/login/officine'

# Capturer un écran
shot() {  # shot <profil> <nom> <url> <largeur>
  chromium --headless --disable-gpu --user-data-dir="/tmp/apha-prof-$1" \
    --virtual-time-budget=6000 --screenshot="/tmp/apha-shots/$2-$4.png" \
    --window-size="$4,1400" "$3"
}
```

Le serveur de développement doit tourner : `php artisan serve --port=8000`.

---

### Task 1 : Les tokens de couleur accentués, ajoutés sans rien retirer

Cette tâche **ne change rien à l'écran des sept pages** : elles surchargent
encore `--apha-*` localement, et une déclaration sur `.network-page` l'emporte
sur `:root`. La coquille, elle, bouge — elle lit déjà les tokens du thème.
C'est voulu : on installe la cible avant de retirer le filet.

**Files:**
- Modify: `resources/css/app.css` — bloc `:root`

**Interfaces:**
- Consumes: rien.
- Produces: les tokens `--officine`, `--officine-dark`, `--officine-soft`,
  `--gold`, `--gold-mid`, `--gold-soft`, `--terracotta`, `--terracotta-dark`,
  `--terracotta-soft`, `--ink` aux valeurs de la table ; et les alias
  `--apha-primary`, `--apha-primary-dark`, `--apha-primary-soft`,
  `--apha-gold`, `--apha-gold-soft`, `--apha-ink`, `--apha-muted`,
  `--apha-light`, `--apha-border`, consommés par les tâches 3 à 7.

- [ ] **Step 1 : Relever l'état de référence**

Le serveur tournant, capturer trois écrans avant toute modification.

```bash
shot officine dash-avant 'http://localhost:8000/' 1440
shot admin network-avant 'http://localhost:8000/admin/network' 1440
shot admin exports-avant 'http://localhost:8000/admin/csv-exports' 1440
```

- [ ] **Step 2 : Remplacer les valeurs de la palette dans `:root`**

Dans `resources/css/app.css`, le bloc `:root` commence par le commentaire
« Palette relevée sur les artboards du canvas APhaSPB. ». Remplacer les dix
premières déclarations par :

```css
:root {
    /*
      Source unique de la couleur. Sept pages portaient chacune une copie
      d'une palette turquoise concurrente ; elles ont été supprimées au
      profit de ce bloc. Ne pas redéclarer ces variables dans un
      <style scoped> : c'est la duplication qu'on vient de retirer.
    */
    --ink: #141d18;
    --officine: #14764c;
    --officine-dark: #0e5a3a;
    --officine-soft: #e6f3ec;
    --gold: #f0c53f;
    --gold-mid: #e0a516;
    --gold-soft: #fdf7e6;
    --gold-dark: #b07c1a;
    --terracotta: #d13b22;
    --terracotta-dark: #ad2c16;
    --terracotta-soft: #fdf0ed;
    --cream: #fdfbf7;
    --cream-header: #f7f5f0;
    --cream-state: #faf8f3;
```

Laisser intacte la suite du bloc (`--background`, `--foreground`, `--card`…),
qui pointe déjà sur ces variables.

- [ ] **Step 3 : Ajouter les alias `--apha-*` à la fin du même bloc `:root`**

Juste avant l'accolade fermante de `:root`, après les tokens `--chart-*` :

```css
    /*
      Alias de compatibilité. Environ 255 usages de var(--apha-*) vivent dans
      les <style scoped> des pages. Les renommer tous relèverait des lots 3 et
      4 ; en attendant, ces alias font que la page lit la palette du thème au
      lieu d'une copie locale. Ils pointent sur les tokens ci-dessus et n'ont
      aucune valeur propre.
    */
    --apha-primary: var(--officine);
    --apha-primary-dark: var(--officine-dark);
    --apha-primary-soft: var(--officine-soft);
    --apha-gold: var(--gold-mid);
    --apha-gold-soft: var(--gold-soft);
    --apha-ink: var(--ink);
    --apha-muted: rgb(20 29 24 / 0.55);
    --apha-light: rgb(20 29 24 / 0.38);
    --apha-border: rgb(20 29 24 / 0.11);
```

- [ ] **Step 3 bis : Définir `--primary-dark` et `--primary-soft`, aujourd'hui pendants**

Relevé à la lecture du plan contre le dépôt : `var(--primary-dark)` (7 usages)
et `var(--primary-soft)` (13 usages) pointent vers des variables **définies
nulle part**, sans valeur de repli. Ces vingt déclarations sont mortes : un
`background: var(--primary-soft)` ne peint rien. Les définir est la seule
réponse cohérente avec le but du lot — mais c'est un **changement visible**,
des survols vont se mettre à peindre. À reporter comme écart.

Après `--primary: var(--officine);` dans `:root` :

```css
    /*
      Ces deux-là étaient consommées par vingt déclarations sans avoir jamais
      été définies : les fonds concernés ne peignaient rien. Les définir fait
      apparaître des états de survol qui n'avaient jamais fonctionné.
    */
    --primary-dark: var(--officine-dark);
    --primary-soft: var(--officine-soft);
```

- [ ] **Step 4 : Mettre à jour les tokens dérivés qui portaient une valeur littérale**

Toujours dans `:root`, remplacer ces trois déclarations, qui portaient
l'ancienne encre en clair, pour qu'elles suivent la nouvelle :

```css
    --muted-foreground: rgb(20 29 24 / 0.5);
    --border: rgb(20 29 24 / 0.09);
    --input: rgb(20 29 24 / 0.14);
```

- [ ] **Step 5 : Construire et vérifier que rien n'est cassé**

```bash
npm run build
npx vue-tsc --noEmit
```

Attendu : build réussi, aucune erreur de type.

- [ ] **Step 6 : Vérifier à l'œil que seule la coquille a bougé**

```bash
shot officine dash-t1 'http://localhost:8000/' 1440
shot admin network-t1 'http://localhost:8000/admin/network' 1440
```

Comparer `dash-t1-1440.png` à `dash-avant-1440.png`.

Attendu : un état **mixte**, et c'est le résultat correct. Les 34 usages de
`var(--primary)` dans les pages passent au vert accentué, puisque ce token
vient de `:root` ; les usages de `var(--apha-*)` restent turquoise, puisque la
copie locale de la page l'emporte encore. Des survols jusqu'ici inertes se
mettent à peindre (`--primary-soft`).

Le mélange est laid et transitoire : la tâche 3 le résout. **Ne pas tenter de
le corriger ici.** En revanche, si *plus rien* n'est turquoise, c'est qu'une
copie locale a sauté par erreur — revenir à l'étape 2.

- [ ] **Step 7 : Lancer la suite**

```bash
php artisan test --compact
```

Attendu : 586 tests verts. Aucune assertion ne porte sur la couleur ; un rouge
ici signale qu'autre chose a été touché.

- [ ] **Step 8 : Commit**

```bash
git add resources/css/app.css
git commit -m "style: accentuer la palette et centraliser les alias --apha-*

Les valeurs montent d'un cran et le bloc :root gagne les alias --apha-*,
jusqu'ici redéfinis dans sept <style scoped>. Rien n'est encore retiré des
pages : leur copie locale l'emporte, donc seul le vert de la coquille bouge.
Le filet est en place avant la suppression.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2 : Les tokens typographiques, définis et non appliqués

Le lot 1 pose l'échelle ; les lots 2 à 4 l'appliquent sur les éléments qui la
portent. Définir sans appliquer n'a **aucun effet visible** — c'est normal, et
c'est ce qui permet aux lots suivants de citer un token au lieu d'un nombre.

**Files:**
- Modify: `resources/css/app.css` — bloc `@theme inline`

**Interfaces:**
- Consumes: rien.
- Produces: les tokens `--text-display`, `--text-figure`, `--text-section`,
  `--text-body`, `--text-meta`, `--text-label`, `--text-nav`, `--text-brand`,
  et leurs hauteurs de ligne. Consommés par les lots 2 à 4, pas par ce lot.

- [ ] **Step 1 : Ajouter l'échelle au bloc `@theme inline`**

Dans `resources/css/app.css`, juste après les trois déclarations
`--font-sans` / `--font-mono` / `--font-serif` :

```css
    /*
      Échelle typographique de la refonte. Définie ici et appliquée par les
      lots 2 à 4, sur les éléments qui portent réellement une taille. Un
      écran qui écrit encore un nombre en dur n'a pas encore été repris.

      Le serif sert au titre d'écran et aux chiffres clés, sur TOUTES les
      largeurs : une police d'affichage qui n'apparaîtrait qu'au téléphone se
      lirait comme une incohérence, pas comme une intention.
    */
    --text-display: 34px;
    --text-display--line-height: 1.06;
    --text-display-compact: 27px;
    --text-display-compact--line-height: 1.08;
    --text-figure: 29px;
    --text-figure--line-height: 1;
    --text-figure-compact: 21px;
    --text-figure-compact--line-height: 1;
    --text-section: 17px;
    --text-section--line-height: 1.3;
    --text-body: 14px;
    --text-body--line-height: 1.55;
    --text-meta: 12.5px;
    --text-meta--line-height: 1.5;
    --text-label: 9.5px;
    --text-label--line-height: 1;
    --text-nav: 14.5px;
    --text-nav--line-height: 1.2;
    --text-brand: 17px;
    --text-brand--line-height: 1.1;
```

- [ ] **Step 2 : Construire**

```bash
npm run build
```

Attendu : build réussi. Tailwind expose désormais `text-display`, `text-body`,
etc. comme utilitaires.

- [ ] **Step 3 : Vérifier que rien n'a bougé à l'écran**

```bash
shot officine dash-t2 'http://localhost:8000/' 1440
```

Attendu : `dash-t2-1440.png` **identique** à `dash-t1-1440.png`. Un token défini et non
utilisé ne rend rien. Si quelque chose a changé, un nom de token entre en
collision avec un utilitaire Tailwind existant — le renommer.

- [ ] **Step 4 : Commit**

```bash
git add resources/css/app.css
git commit -m "style: définir l'échelle typographique de la refonte

Définie seule, appliquée par les lots suivants sur les éléments qui portent
une taille. Sans effet visible à ce stade, et c'est le but : les lots 2 à 4
citeront un token au lieu d'un nombre.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3 : Supprimer les sept copies locales de la palette

C'est la tâche qui se voit. Les sept pages tombent d'un coup sur les tokens
globaux : le turquoise `#008f83` disparaît au profit du vert `#14764c`.

**Files:**
- Modify: `resources/js/pages/pharmacy/Insurers.vue` (bloc `.insurers-page`)
- Modify: `resources/js/pages/pharmacy/History.vue` (bloc `.history-page`)
- Modify: `resources/js/pages/admin/Pharmacies.vue` (bloc `.pharmacies-page`)
- Modify: `resources/js/pages/admin/Network.vue` (bloc `.network-page`)
- Modify: `resources/js/pages/admin/Insurers.vue` (bloc `.insurers-page`)
- Modify: `resources/js/pages/admin/Exports.vue` (bloc `.exports-page`)
- Modify: `resources/js/pages/admin/Trends.vue` (bloc `.network-evolution-page`)

**Interfaces:**
- Consumes: les alias `--apha-*` de la tâche 1.
- Produces: sept sélecteurs de page qui ne déclarent plus aucune variable.

- [ ] **Step 1 : Localiser les sept blocs**

```bash
grep -n -- '--apha-primary:' resources/js/pages/**/*.vue
```

Attendu : sept résultats, un par fichier.

- [ ] **Step 2 : Retirer les variables, garder la mise en page**

Dans chacun des sept blocs, supprimer **uniquement** les déclarations de
variables et le commentaire `/* --apha-background: … */`. Le bloc de
`admin/Network.vue` passe ainsi de :

```css
.network-page {
    --apha-primary: #008f83;
    --apha-primary-dark: #006f68;
    --apha-primary-soft: #e8f6f3;

    --apha-gold: #d7a33d;
    --apha-gold-soft: #fff8e9;

    --apha-ink: #243333;
    --apha-muted: #788585;
    --apha-light: #a2adad;

    --apha-border: #e7eceb;

    /* --apha-background: #F7F9F9; */

    position: relative;

    min-height: 100vh;

    /* background:
        radial-gradient(
            circle at 90% 0%,
            rgba(0, 143, 131, .045),
            transparent 30%
        ),

        var(--apha-background); */

    padding-bottom: 50px;
}
```

à :

```css
.network-page {
    /* La palette vient de :root — voir resources/css/app.css. */
    position: relative;

    min-height: 100vh;

    padding-bottom: 50px;
}
```

Les propriétés de mise en page (`position`, `min-height`, `padding-bottom`, et
tout ce que porte le bloc d'un autre fichier) sont **conservées telles
quelles**. Seules les variables partent, avec le fond commenté qui les citait.

- [ ] **Step 3 : Vérifier qu'il n'en reste aucune**

```bash
grep -rn -- '--apha-[a-z-]*:' resources/js --include=*.vue
```

Attendu : **aucun résultat**. Toute ligne restante est une copie oubliée.

- [ ] **Step 4 : Vérifier que les usages, eux, sont intacts**

```bash
grep -rc 'var(--apha-' resources/js --include=*.vue | grep -v ':0$' | wc -l
```

Attendu : un nombre non nul — les usages restent, seules les définitions
partent. S'il tombe à zéro, on a supprimé des `var(--apha-…)` par erreur.

- [ ] **Step 5 : Construire**

```bash
npm run build
npx vue-tsc --noEmit
```

- [ ] **Step 6 : Comparer les sept écrans à l'œil**

```bash
shot admin network-t3 'http://localhost:8000/admin/network' 1440
shot admin trends-t3 'http://localhost:8000/admin/trends' 1440
shot admin pharmacies-t3 'http://localhost:8000/admin/pharmacies' 1440
shot admin ins-adm-t3 'http://localhost:8000/admin/insurers' 1440
shot admin exports-t3 'http://localhost:8000/admin/csv-exports' 1440
shot officine history-t3 'http://localhost:8000/pharmacy/history' 1440
shot officine ins-off-t3 'http://localhost:8000/pharmacy/insurers' 1440
```

Attendu, sur chacune : plus aucun turquoise. Le vert de la barre latérale et
le vert du corps de page sont désormais **le même**. Comparer
`network-t3-1440.png` à `network-avant-1440.png` — c'est la démonstration du lot.

- [ ] **Step 7 : Lancer la suite**

```bash
php artisan test --compact
```

Attendu : 586 tests verts.

- [ ] **Step 8 : Commit**

```bash
git add resources/js/pages
git commit -m "style: supprimer les sept copies locales de la palette

Chacune des sept pages portait une copie identique d'une palette turquoise
(#008f83) sans rapport avec le vert officine du thème, absent de toutes les
pages. Sur « Statistiques réseau », la pastille active de la barre latérale
et le rond du titre étaient deux verts différents à 200 px l'un de l'autre.

Les sélecteurs de page ne déclarent plus de variables ; les usages
var(--apha-*) tombent sur les alias de :root.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4 : Interdire la réapparition d'une palette locale

Le lot vient de supprimer sept copies d'un même bloc. Rien n'empêche la
huitième. Ce test est la seule chose qui rende l'invariant durable — et il
porte sur la structure des sources, pas sur une apparence, donc il est
testable.

**Files:**
- Create: `tests/Feature/Design/PaletteSourceTest.php`

**Interfaces:**
- Consumes: l'état laissé par la tâche 3.
- Produces: rien que du code consomme.

- [ ] **Step 1 : Écrire le test**

```php
<?php

use Illuminate\Support\Facades\File;

/**
 * La couleur a une seule source, et ce test est ce qui l'y maintient.
 *
 * Sept pages ont porté chacune une copie identique d'une palette turquoise
 * concurrente du thème. Les copies n'avaient pas encore divergé — c'est un
 * coup de chance, pas une garantie, et la huitième copie serait aussi facile
 * à écrire que les sept premières.
 *
 * Le test lit les sources plutôt que le rendu : aucun test de ce projet ne
 * monte de composant, et l'invariant visé est structurel, pas visuel.
 */
test('no Vue file redefines the palette that app.css owns', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        if (preg_match('/^\s*--apha-[a-z-]+\s*:/m', $file->getContents()) === 1) {
            $offenders[] = str_replace(resource_path('js').'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

test('app.css is the file that does define the palette', function () {
    // Le premier test passerait aussi si les alias disparaissaient de partout,
    // y compris de leur source — les pages tomberaient alors sans couleur.
    $css = File::get(resource_path('css/app.css'));

    expect($css)->toContain('--apha-primary: var(--officine)')
        ->and($css)->toContain('--officine: #14764c');
});
```

- [ ] **Step 2 : Lancer le test, il doit passer**

```bash
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : 2 tests verts. Ils passent immédiatement, puisque la tâche 3 a déjà
nettoyé — c'est un filet, pas un moteur.

- [ ] **Step 3 : Vérifier que le filet mord**

Réintroduire temporairement une définition pour s'assurer que le test la voit.

```bash
sed -i 's/^\.network-page {$/.network-page {\n    --apha-primary: #008f83;/' resources/js/pages/admin/Network.vue
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : **ROUGE**, le premier test citant `pages/admin/Network.vue`. Puis
annuler :

```bash
git checkout resources/js/pages/admin/Network.vue
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : vert. Un test qui ne rougit jamais ne protège rien.

- [ ] **Step 4 : Commit**

```bash
git add tests/Feature/Design/PaletteSourceTest.php
git commit -m "test: interdire la redéfinition locale de la palette

Sept copies viennent d'être supprimées ; rien n'empêchait la huitième. Le
test lit les sources — l'invariant est structurel, pas visuel, et aucun test
de ce projet ne monte de composant. Vérifié en réintroduisant une définition :
le test rougit en la nommant.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5 : Aplatir les dégradés de la coquille

On commence par la coquille : elle est visible sur tous les écrans, donc une
erreur s'y voit immédiatement plutôt que sur une page qu'on n'ouvre pas.

**Files:**
- Modify: `resources/js/layouts/console/ConsoleLayout.vue` (2 occurrences)
- Modify: `resources/js/layouts/console/ConsoleSidebar.vue` (7 occurrences)

**Interfaces:**
- Consumes: les tokens de la tâche 1.
- Produces: rien de nommé.

- [ ] **Step 1 : Lister les occurrences**

```bash
grep -n -B2 -A6 'gradient' resources/js/layouts/console/ConsoleLayout.vue \
  resources/js/layouts/console/ConsoleSidebar.vue
```

- [ ] **Step 2 : Appliquer la table des cinq motifs**

Reprendre la table « Les cinq motifs de dégradé » ci-dessus et l'appliquer à
chaque occurrence. Le halo de la barre latérale relève du motif 1 : la
déclaration `background` entière disparaît, et le fond retombe sur la couleur
unie déjà posée par la règle voisine. Si aucune règle voisine ne pose de fond,
écrire `background: var(--cream-header);`.

Aucune sixième règle : si une occurrence ne rentre dans aucun des cinq motifs,
**arrêter et le signaler** plutôt que d'improviser.

- [ ] **Step 3 : Vérifier qu'il n'en reste aucun**

```bash
grep -c 'gradient' resources/js/layouts/console/ConsoleLayout.vue \
  resources/js/layouts/console/ConsoleSidebar.vue
```

Attendu : `0` pour les deux fichiers.

- [ ] **Step 4 : Construire et capturer aux trois largeurs**

```bash
npm run build
shot officine shell-t5 'http://localhost:8000/' 1440
shot officine shell-t5 'http://localhost:8000/' 768
shot officine shell-t5 'http://localhost:8000/' 390
```

Attendu : la barre latérale est un aplat, sans halo. Aucun débordement
horizontal à 768 ni à 390 — le projet a déjà connu deux défauts responsive qui
ne se voyaient pas autrement.

- [ ] **Step 5 : Lancer la suite**

```bash
php artisan test --compact
```

- [ ] **Step 6 : Commit**

```bash
git add resources/js/layouts/console
git commit -m "style: aplatir les dégradés de la coquille

Halo radial de la barre latérale et fonds dégradés du gabarit remplacés par
des aplats. Un dégradé diffuse le propos là où un aplat le pose.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6 : Aplatir les dégradés des écrans officine

**Files:**
- Modify: `resources/js/pages/pharmacy/Dashboard.vue` (6 occurrences)
- Modify: `resources/js/pages/pharmacy/Declare.vue` (7 occurrences)
- Modify: `resources/js/pages/pharmacy/History.vue` (11 occurrences)
- Modify: `resources/js/pages/pharmacy/Insurers.vue` (13 occurrences)

**Interfaces:**
- Consumes: les tokens de la tâche 1, la table des cinq motifs.
- Produces: rien de nommé.

- [ ] **Step 1 : Lister les occurrences, fichier par fichier**

```bash
for f in Dashboard Declare History Insurers; do
  echo "=== $f ==="
  grep -n -A6 'gradient' "resources/js/pages/pharmacy/$f.vue"
done
```

- [ ] **Step 2 : Appliquer la table des cinq motifs**

Pour chaque occurrence, appliquer la table « Les cinq motifs de dégradé » du
haut de ce plan : un halo décoratif disparaît, un faux-blanc devient `#fff`,
un fond de bouton devient `var(--primary)`, un liseré multicolore devient
`var(--primary)`, une barre de progression devient `var(--gold-mid)`. Un
dégradé déjà en commentaire part avec son commentaire.

**Règle d'arrêt : une occurrence qui ne rentre dans aucun des cinq motifs se
signale, elle ne s'improvise pas.**

Cas concrets déjà repérés dans `Dashboard.vue`, à traiter exactement ainsi :

- l. ~895 `radial-gradient(circle at 95% 0%, rgba(0,143,131,0.045), transparent 28%)` → motif 1, déclaration supprimée.
- l. ~1004 `linear-gradient(110deg, #ffffff 0%, #f9fcfb 100%)` → `background: #fff;`
- l. ~1033 `radial-gradient(circle, rgba(0,143,131,0.09), transparent 68%)` → motif 1, déclaration supprimée.
- l. ~1068 `linear-gradient(135deg, var(--primary), var(--primary-dark))` → `background: var(--primary);`
- l. ~1294 `linear-gradient(90deg, var(--primary), #35a799, var(--gold))` → `background: var(--primary);`
- l. ~1461 `linear-gradient(90deg, #d7a33d, #e6bf63)` → `background: var(--gold-mid);`

- [ ] **Step 3 : Vérifier**

```bash
grep -c 'gradient' resources/js/pages/pharmacy/*.vue
```

Attendu : `0` partout.

- [ ] **Step 4 : Construire et capturer aux trois largeurs**

```bash
npm run build
for w in 1440 768 390; do
  shot officine dash-t6 'http://localhost:8000/' $w
  shot officine declare-t6 'http://localhost:8000/pharmacy/declare' $w
  shot officine history-t6 'http://localhost:8000/pharmacy/history' $w
  shot officine insoff-t6 'http://localhost:8000/pharmacy/insurers' $w
done
```

Attendu : aucun halo, aucune carte au blanc dégradé, aucun débordement
horizontal.

- [ ] **Step 5 : Lancer la suite**

```bash
php artisan test --compact
```

- [ ] **Step 6 : Commit**

```bash
git add resources/js/pages/pharmacy
git commit -m "style: aplatir les dégradés des écrans officine

37 dégradés remplacés par des aplats, un filet ou rien, selon les cinq motifs
recensés dans le plan du lot 1.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7 : Aplatir les dégradés des écrans admin

**Files:**
- Modify: `resources/js/pages/admin/Network.vue` (7 occurrences)
- Modify: `resources/js/pages/admin/Trends.vue` (7 occurrences)
- Modify: `resources/js/pages/admin/Pharmacies.vue` (6 occurrences)
- Modify: `resources/js/pages/admin/Insurers.vue` (9 occurrences)
- Modify: `resources/js/pages/admin/Exports.vue` (6 occurrences)

**Interfaces:**
- Consumes: les tokens de la tâche 1, la table des cinq motifs.
- Produces: rien de nommé. Dernière tâche du lot.

- [ ] **Step 1 : Lister les occurrences, fichier par fichier**

```bash
for f in Network Trends Pharmacies Insurers Exports; do
  echo "=== $f ==="
  grep -n -A6 'gradient' "resources/js/pages/admin/$f.vue"
done
```

- [ ] **Step 2 : Appliquer la table des cinq motifs**

Pour chaque occurrence, appliquer la table « Les cinq motifs de dégradé » du
haut de ce plan : un halo décoratif disparaît, un faux-blanc devient `#fff`,
un fond de bouton devient `var(--primary)`, un liseré multicolore devient
`var(--primary)`, une barre de progression devient `var(--gold-mid)`. Un
dégradé déjà en commentaire part avec son commentaire.

**Règle d'arrêt : une occurrence qui ne rentre dans aucun des cinq motifs se
signale, elle ne s'improvise pas.**

- [ ] **Step 3 : Vérifier qu'il ne reste aucun dégradé dans tout le front**

```bash
grep -rc 'gradient' resources/js --include=*.vue | grep -v ':0$' || echo "aucun dégradé restant"
```

Attendu : `aucun dégradé restant`.

- [ ] **Step 4 : Construire et capturer aux trois largeurs**

```bash
npm run build
npx vue-tsc --noEmit
for w in 1440 768 390; do
  shot admin network-t7 'http://localhost:8000/admin/network' $w
  shot admin trends-t7 'http://localhost:8000/admin/trends' $w
  shot admin pharm-t7 'http://localhost:8000/admin/pharmacies' $w
  shot admin insadm-t7 'http://localhost:8000/admin/insurers' $w
  shot admin exports-t7 'http://localhost:8000/admin/csv-exports' $w
done
```

Attendu : aucun dégradé visible, aucun débordement horizontal.

**Défaut connu, à ne pas corriger ici :** à 768 px, la rangée de
téléchargement de `admin/Exports.vue` écrase son libellé et les trois boutons
se chevauchent. Ce défaut est **antérieur** à la refonte, vérifié par capture
comparative le 20/09/2026, et il est traité au lot 4. Ne pas l'inclure dans ce
lot : ce serait une modification de balisage, que la contrainte globale
interdit.

- [ ] **Step 5 : Lancer la suite complète**

```bash
php artisan test --compact
npx vue-tsc --noEmit
npm run build
```

Attendu : 588 tests verts (586 existants + les 2 de la tâche 4), aucune erreur
de type, build réussi.

- [ ] **Step 6 : Commit**

```bash
git add resources/js/pages/admin
git commit -m "style: aplatir les dégradés des écrans admin

35 dégradés remplacés selon les cinq motifs du plan. Plus aucun dégradé dans
resources/js.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 7 : Consigner la règle pour la suite**

Le socle de couleur est désormais un invariant qui déborde ce lot. L'enregistrer
avec l'outil Boost `record-rule` :

- glob : `resources/css/**, resources/js/**`
- titre : `La couleur a une source unique : app.css`
- note : rappeler que sept pages ont porté une palette turquoise concurrente,
  que `PaletteSourceTest` interdit la huitième copie, que les alias `--apha-*`
  sont transitoires et disparaîtront quand les lots 3 et 4 auront renommé leurs
  usages, et qu'aucun dégradé ne subsiste — les cinq motifs et leurs
  remplacements sont dans ce plan.
