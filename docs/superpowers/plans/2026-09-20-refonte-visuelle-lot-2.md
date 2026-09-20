# Refonte visuelle — lot 2 : la coquille

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner à la coquille ses icônes de navigation, un repli de barre
latérale mémorisé, le serif sur le titre d'écran, et le bandeau vert qui
remplace l'en-tête clair sous 1024 px.

**Architecture:** L'icône est **choisie côté PHP** et voyage dans `NavItem`
sous une clé ; une table explicite côté Vue la traduit en composant Lucide, et
un test Pest confronte les deux listes — c'est le seul endroit où l'oubli peut
se produire. Le repli vit dans un module TypeScript minuscule, testable sous
Vitest, plutôt que dans le composant. L'en-tête gagne un slot `#hero` et deux
formes selon la largeur ; **aucune page ne remplit ce slot dans ce lot** — le
tableau de bord l'adoptera au lot 3.

**Tech Stack:** Laravel 13 / PHP 8.4, Pest 4, Inertia 3, Vue 3 `<script setup>`,
Tailwind 4, `@lucide/vue` (**déjà installé**), Vitest (environnement node),
Chromium headless pour les captures.

**Spec:** `docs/superpowers/specs/2026-09-20-refonte-visuelle-design.md`

## Global Constraints

- **Aucune dépendance ajoutée ou modifiée.** `@lucide/vue` est déjà dans
  `package.json` (règle projet, `CLAUDE.md`).
- **Aucune règle métier touchée.** Les 590 tests actuels restent verts.
- **Le projet est clair uniquement** : pas de variante sombre.
- **La couleur vient de `:root`.** `PaletteSourceTest` interdit toute
  redéclaration locale, dans les deux familles de noms. Un nouveau style écrit
  ici cite un token, jamais un hexadécimal.
- **Vitest tourne en environnement node** (`vitest.config.ts`) : il teste de la
  logique pure, il ne monte aucun composant. Tout ce qu'on veut couvrir par un
  test doit donc vivre dans un `.ts`, pas dans un `.vue`.
- Après toute modification PHP : `vendor/bin/pint --dirty --format agent`.
- Le seuil responsive est **1024 px**, celui où `ConsoleLayout` bascule déjà en
  colonne (`ConsoleLayout.vue`, `@media (max-width: 1023px)`).

### Écart assumé avec la spec

La spec écrit « Déployée : 196 px ». La barre mesure **212 px** aujourd'hui, et
la navigation passe de ~13 à 14,5 px dans ce lot : la rétrécir ferait passer
« Exporter mes données » à la ligne. **La largeur déployée reste 212 px.** Le
rail replié vaut bien 62 px comme prévu.

## File Structure

| Fichier | Rôle |
|---|---|
| `app/Support/ConsoleNavigation.php` | Émet une clé `icon` par entrée de navigation. Le type `@phpstan-type NavItem` gagne `icon: string`. |
| `resources/js/types/console.ts` | `ConsoleNavItem` gagne `icon: string`. |
| `resources/js/lib/navIcons.ts` | **Créé.** Table clé → composant Lucide. Une clé inconnue rend `null`, jamais une icône par défaut. |
| `resources/js/lib/sidebarCollapsed.ts` | **Créé.** Lecture et écriture de l'état replié dans `localStorage`, enveloppées d'un `try/catch`. |
| `resources/js/layouts/console/ConsoleSidebar.vue` | Rend l'icône, le bouton de repli, le rail à 62 px, l'infobulle. Typographie grossie. |
| `resources/js/layouts/console/ConsoleHeader.vue` | Titre en serif, slot `#hero`, bandeau vert sous 1024 px. |
| `tests/Feature/Console/ConsoleShellTest.php` | Chaque entrée porte une `icon` non vide ; une entrée nommée porte la clé attendue. |
| `tests/Feature/Console/NavIconCoverageTest.php` | **Créé.** Toute clé que le PHP peut émettre existe dans `navIcons.ts`. |
| `resources/js/lib/sidebarCollapsed.test.ts` | **Créé.** Le module survit à un `localStorage` qui lève. |

### Les dix clés d'icône

Vérifiées présentes dans `@lucide/vue` par `node -e "require('@lucide/vue')"`.

| Entrée de navigation | Clé | Composant |
|---|---|---|
| Tableau de bord | `layout-dashboard` | `LayoutDashboard` |
| Déclarer ce mois | `file-plus-2` | `FilePlus2` |
| Historique | `history` | `History` |
| Mes assureurs | `building-2` | `Building2` |
| Exporter mes données | `download` | `Download` |
| Statistiques réseau | `chart-column` | `ChartColumn` |
| Évolution | `trending-up` | `TrendingUp` |
| Pharmacies inscrites | `store` | `Store` |
| Gestion des assureurs | `building-2` | `Building2` |
| Exports CSV | `download` | `Download` |

`Building2` et `Download` servent deux fois : « Mes assureurs » et « Gestion
des assureurs » désignent le même domaine, les deux exports aussi. Deux
entrées du même espace ne partagent jamais une icône.

### Le harnais de capture

`php artisan serve --port=8000` **et** `npm run dev` doivent tourner : les
pages passent par le serveur Vite (`public/hot`), et si celui-ci est mort
**toutes les captures sortent uniformément crème**. Vérifier avant de
conclure quoi que ce soit d'une capture blanche.

```bash
mkdir -p /tmp/apha-shots
shot() {  # shot <profil> <nom> <url> <largeur>
  curl -s -o /dev/null -m 2 http://localhost:5173/ || echo "ATTENTION: Vite est mort"
  chromium --headless --disable-gpu --user-data-dir="/tmp/apha-prof-$1" \
    --virtual-time-budget=7000 --screenshot="/tmp/apha-shots/$2-$4.png" \
    --window-size="$4,1400" "$3" 2>/dev/null
}
# Sessions : /dev/login/officine et /dev/login/admin, une fois par profil.
```

---

### Task 1 : L'icône est choisie côté PHP

Une table de correspondance côté Vue indexée sur le **libellé** casserait le
jour où un libellé est reformulé — et les libellés de ce projet ont déjà bougé
(deux entrées commentées le 31/08/2026 dans `ConsoleNavigation`). La clé
voyage donc avec l'entrée.

**Files:**
- Modify: `app/Support/ConsoleNavigation.php`
- Modify: `resources/js/types/console.ts`
- Test: `tests/Feature/Console/ConsoleShellTest.php`

**Interfaces:**
- Consumes: rien.
- Produces: chaque `NavItem` porte `icon: string`, valeur prise dans les dix
  clés du tableau ci-dessus. Consommé par les tâches 2 et 3.

- [ ] **Step 1 : Écrire les tests qui échouent**

À la fin de `tests/Feature/Console/ConsoleShellTest.php` :

```php
test('every navigation entry carries a non-empty icon key', function () {
    // L'icône vient du serveur pour que le front n'ait pas à deviner d'après
    // le libellé : les libellés de ce projet ont déjà été reformulés.
    foreach ([User::factory()->networkAdmin()->notOnboarded()->create(),
              User::factory()->create()] as $user) {
        $nav = app(ConsoleNavigation::class)->forUser($user, '/')['nav'];

        expect($nav)->not->toBeEmpty();

        foreach ($nav as $item) {
            expect($item)->toHaveKey('icon')
                ->and($item['icon'])->toBeString()
                ->and($item['icon'])->not->toBe('');
        }
    }
});

test('a named entry carries the icon it is supposed to', function () {
    // Épingle une paire précise : sans cela, renommer un libellé pourrait
    // déplacer silencieusement une icône sur une autre entrée.
    $nav = app(ConsoleNavigation::class)
        ->forUser(User::factory()->networkAdmin()->notOnboarded()->create(), '/')['nav'];

    $byLabel = collect($nav)->keyBy('label');

    expect($byLabel['Statistiques réseau']['icon'])->toBe('chart-column')
        ->and($byLabel['Exports CSV']['icon'])->toBe('download');
});
```

Ajouter `use App\Support\ConsoleNavigation;` en tête du fichier s'il n'y est
pas déjà.

- [ ] **Step 2 : Lancer, vérifier l'échec**

```bash
vendor/bin/pest tests/Feature/Console/ConsoleShellTest.php --filter='icon' --compact
```

Attendu : **ROUGE**, `toHaveKey('icon')` échoue — la clé n'existe pas encore.

- [ ] **Step 3 : Ajouter la clé au type et aux définitions**

Dans `app/Support/ConsoleNavigation.php`, le bloc de types :

```php
 * @phpstan-type NavItem array{label: string, href: string, active: bool, icon: string}
```

Les définitions de l'espace admin — l'icône est le **quatrième** élément, après
les paramètres de route, pour que les entrées sans paramètre restent courtes :

```php
            'nav' => $this->items($currentPath, [
                ['Statistiques réseau', 'admin.network', [], 'chart-column'],
                ['Évolution', 'admin.trends', [], 'trending-up'],
                ['Pharmacies inscrites', 'admin.pharmacies', [], 'store'],
                ['Gestion des assureurs', 'admin.insurers', [], 'building-2'],
                ['Exports CSV', 'admin.csv-exports', [], 'download'],
                // Retirée de la navigation le 31/08/2026. L'écran et sa route
                // existent toujours : seule l'entrée est masquée.
                // ['Profil & réglages', 'profile.edit'],
            ]),
```

Celles de l'espace officine :

```php
        if ($pharmacy !== null) {
            $definitions[] = ['Tableau de bord', 'dashboard', ['current_pharmacy' => $pharmacy->slug], 'layout-dashboard'];
        }

        $definitions[] = ['Déclarer ce mois', 'pharmacy.declare', [], 'file-plus-2'];
        $definitions[] = ['Historique', 'pharmacy.history', [], 'history'];
        $definitions[] = ['Mes assureurs', 'pharmacy.insurers', [], 'building-2'];
        $definitions[] = ['Exporter mes données', 'pharmacy.data-exports', [], 'download'];
```

- [ ] **Step 4 : Faire porter la clé par `items()`**

```php
    protected function items(string $currentPath, array $definitions): array
    {
        $items = [];

        foreach ($definitions as $definition) {
            $href = route($definition[1], $definition[2] ?? [], absolute: false);

            $items[] = [
                'label' => $definition[0],
                'href' => $href,
                'active' => $currentPath === $href || str_starts_with($currentPath, $href.'/'),
                // Pas de repli : une entrée sans icône est une erreur de
                // définition, et un `?? ''` la rendrait invisible.
                'icon' => $definition[3],
            ];
        }

        return $items;
    }
```

- [ ] **Step 5 : Mettre à jour le type TypeScript**

Dans `resources/js/types/console.ts` :

```ts
export type ConsoleNavItem = {
    label: string;
    href: string;
    active: boolean;
    /** Clé d'icône, traduite en composant par `@/lib/navIcons`. */
    icon: string;
};
```

- [ ] **Step 6 : Lancer les tests**

```bash
vendor/bin/pest tests/Feature/Console/ConsoleShellTest.php --compact
npx vue-tsc --noEmit
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress
```

Attendu : tests verts, 0 erreur de type, PHPStan 0.

- [ ] **Step 7 : Commit**

```bash
git add app/Support/ConsoleNavigation.php resources/js/types/console.ts tests/Feature/Console/ConsoleShellTest.php
git commit -m "feat: faire porter une clé d'icône par chaque entrée de navigation

Choisie côté serveur plutôt que déduite du libellé côté Vue : les libellés
de ce projet ont déjà été reformulés, et une table indexée dessus aurait
déplacé les icônes en silence.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2 : La table de correspondance, et le test qui la confronte au PHP

Le risque réel n'est pas qu'une icône soit laide, c'est que le PHP émette une
clé que le front ne connaît pas. Aucun test de ce projet ne monte de
composant ; la couverture se fait donc en lisant les deux sources.

**Files:**
- Create: `resources/js/lib/navIcons.ts`
- Create: `tests/Feature/Console/NavIconCoverageTest.php`
- Modify: `resources/js/layouts/console/ConsoleSidebar.vue`

**Interfaces:**
- Consumes: la clé `icon` de la tâche 1.
- Produces: `navIcon(key: string): Component | null`, importable depuis
  `@/lib/navIcons`. Consommé par la tâche 3.

- [ ] **Step 1 : Écrire le test de couverture**

`tests/Feature/Console/NavIconCoverageTest.php` :

```php
<?php

use App\Models\User;
use App\Support\ConsoleNavigation;
use Illuminate\Support\Facades\File;

/**
 * Le PHP choisit la clé, le Vue la traduit : ce test est la seule chose qui
 * relie les deux listes.
 *
 * Il lit la source plutôt que de monter un composant — Vitest tourne ici en
 * environnement node et ne monte rien. Une clé émise sans entrée dans la
 * table ne rendrait aucune icône, en silence, sur un écran de production.
 */
test('every icon key the server can emit exists in navIcons.ts', function () {
    $map = File::get(resource_path('js/lib/navIcons.ts'));

    $emitted = [];

    foreach ([
        User::factory()->networkAdmin()->notOnboarded()->create(),
        User::factory()->create(),
    ] as $user) {
        foreach (app(ConsoleNavigation::class)->forUser($user, '/')['nav'] as $item) {
            $emitted[] = $item['icon'];
        }
    }

    $emitted = array_values(array_unique($emitted));

    expect($emitted)->not->toBeEmpty();

    $missing = array_values(array_filter(
        $emitted,
        fn (string $key): bool => ! str_contains($map, "'".$key."':"),
    ));

    expect($missing)->toBe([]);
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

```bash
vendor/bin/pest tests/Feature/Console/NavIconCoverageTest.php --compact
```

Attendu : **ROUGE** — `navIcons.ts` n'existe pas, `File::get` lève.

- [ ] **Step 3 : Écrire la table**

`resources/js/lib/navIcons.ts` :

```ts
import {
    Building2,
    ChartColumn,
    Download,
    FilePlus2,
    History,
    LayoutDashboard,
    Store,
    TrendingUp,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Traduit la clé posée par `ConsoleNavigation` en composant Lucide.
 *
 * Table explicite, et non un import dynamique sur le nom : un import
 * dynamique embarquerait tout Lucide dans le bundle et ne dirait rien le jour
 * où une clé n'existe plus.
 *
 * `Building2` et `Download` servent deux entrées chacun — « Mes assureurs » et
 * « Gestion des assureurs » désignent le même domaine, les deux exports
 * aussi. Deux entrées d'un même espace ne partagent jamais une icône.
 */
const ICONS: Record<string, Component> = {
    'layout-dashboard': LayoutDashboard,
    'file-plus-2': FilePlus2,
    history: History,
    'building-2': Building2,
    download: Download,
    'chart-column': ChartColumn,
    'trending-up': TrendingUp,
    store: Store,
};

/**
 * Le composant d'une clé, ou `null` si elle est inconnue.
 *
 * `null` plutôt qu'une icône par défaut : une icône générique masquerait
 * l'oubli, un trou le montre. `NavIconCoverageTest` le rattrape avant.
 */
export function navIcon(key: string): Component | null {
    return ICONS[key] ?? null;
}
```

**Attention au format :** le test cherche `'<clé>':` dans la source. Les clés
`history`, `download` et `store` sont des identifiants valides et Prettier les
écrira **sans guillemets**. Les entourer de guillemets ferait échouer
`prettier --check`. Le test doit donc accepter les deux formes — corrigé à
l'étape suivante.

- [ ] **Step 4 : Rendre le test indifférent aux guillemets**

Dans `NavIconCoverageTest.php`, remplacer la clôture `$missing` par :

```php
    $missing = array_values(array_filter(
        $emitted,
        // Prettier retire les guillemets des clés qui sont des identifiants
        // valides : `history:` et `'building-2':` coexistent légitimement.
        fn (string $key): bool => ! preg_match("/(^|\s|\{)'?".preg_quote($key, '/')."'?\s*:/m", $map),
    ));
```

- [ ] **Step 5 : Lancer le test**

```bash
npx prettier --write resources/js/lib/navIcons.ts
vendor/bin/pest tests/Feature/Console/NavIconCoverageTest.php --compact
```

Attendu : vert.

- [ ] **Step 6 : Vérifier que le test mord**

Retirer une entrée de la table, relancer, puis la remettre. **Utiliser une
copie de sauvegarde, pas `git checkout`** : le fichier porte du travail non
indexé, et `git checkout` l'écraserait.

```bash
cp resources/js/lib/navIcons.ts /tmp/navIcons.bak
sed -i "/'chart-column': ChartColumn,/d" resources/js/lib/navIcons.ts
vendor/bin/pest tests/Feature/Console/NavIconCoverageTest.php --compact
```

Attendu : **ROUGE**, citant `chart-column`.

```bash
cp /tmp/navIcons.bak resources/js/lib/navIcons.ts
vendor/bin/pest tests/Feature/Console/NavIconCoverageTest.php --compact
```

Attendu : vert.

- [ ] **Step 7 : Rendre l'icône dans la barre latérale**

Dans `resources/js/layouts/console/ConsoleSidebar.vue`, ajouter à l'import :

```ts
import { navIcon } from '@/lib/navIcons';
```

Puis, dans le template, remplacer le contenu de `.apha-nav-icon` :

```html
                    <span class="apha-nav-icon">
                        <component
                            :is="navIcon(item.icon)"
                            v-if="navIcon(item.icon)"
                            class="apha-nav-glyph"
                        />
                        <span v-else class="apha-nav-dot"></span>
                    </span>
```

Le point reste comme repli visuel d'une clé inconnue : le test empêche ce cas,
et si jamais il survenait, une puce vaut mieux qu'un trou.

Et la règle de style, à ajouter près de `.apha-nav-icon` :

```css
.apha-nav-glyph {
    width: 18px;
    height: 18px;

    stroke-width: 1.9;
}
```

- [ ] **Step 8 : Construire et regarder**

```bash
npm run build
npx vue-tsc --noEmit
shot officine nav-icones 'http://localhost:8000/' 1440
shot admin nav-icones-adm 'http://localhost:8000/admin/network' 1440
```

Attendu : cinq icônes distinctes dans chaque espace, alignées avec leur
libellé, pas de puce résiduelle.

- [ ] **Step 9 : Commit**

```bash
git add resources/js/lib/navIcons.ts resources/js/layouts/console/ConsoleSidebar.vue tests/Feature/Console/NavIconCoverageTest.php
git commit -m "feat: afficher les icônes de navigation

Table explicite clé -> composant Lucide, et un test qui confronte les clés
que le PHP peut émettre à celles que la table connaît. Une clé inconnue rend
null plutôt qu'une icône générique : un trou se voit, un repli masque.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3 : Le repli de la barre latérale

**Files:**
- Create: `resources/js/lib/sidebarCollapsed.ts`
- Create: `resources/js/lib/sidebarCollapsed.test.ts`
- Modify: `resources/js/layouts/console/ConsoleSidebar.vue`

**Interfaces:**
- Consumes: le template de navigation de la tâche 2 — le rail réutilise les
  mêmes `<Link>`, il n'en crée pas d'autres.
- Produces: `readCollapsed(): boolean` et `writeCollapsed(v: boolean): void`,
  importables depuis `@/lib/sidebarCollapsed`.

- [ ] **Step 1 : Écrire le test Vitest**

`resources/js/lib/sidebarCollapsed.test.ts` :

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { readCollapsed, writeCollapsed } from './sidebarCollapsed';

function stubStorage(impl: Partial<Storage>) {
    vi.stubGlobal('localStorage', impl as Storage);
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('sidebarCollapsed', () => {
    it('reads false when nothing was ever stored', () => {
        stubStorage({ getItem: () => null });

        expect(readCollapsed()).toBe(false);
    });

    it('reads true only for the exact stored marker', () => {
        stubStorage({ getItem: () => '1' });
        expect(readCollapsed()).toBe(true);

        stubStorage({ getItem: () => '0' });
        expect(readCollapsed()).toBe(false);

        stubStorage({ getItem: () => 'true' });
        expect(readCollapsed()).toBe(false);
    });

    it('opens expanded when the accessor throws', () => {
        // Navigation privée, données de site bloquées : l'accès lève. La barre
        // doit s'ouvrir déployée, pas planter la page.
        stubStorage({
            getItem: () => {
                throw new DOMException('denied');
            },
        });

        expect(readCollapsed()).toBe(false);
    });

    it('swallows a write that throws', () => {
        stubStorage({
            setItem: () => {
                throw new DOMException('quota');
            },
        });

        expect(() => writeCollapsed(true)).not.toThrow();
    });

    it('writes the marker the reader recognises', () => {
        const seen: Record<string, string> = {};
        stubStorage({
            setItem: (k: string, v: string) => {
                seen[k] = v;
            },
            getItem: (k: string) => seen[k] ?? null,
        });

        writeCollapsed(true);
        expect(readCollapsed()).toBe(true);

        writeCollapsed(false);
        expect(readCollapsed()).toBe(false);
    });
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

```bash
npx vitest run resources/js/lib/sidebarCollapsed.test.ts
```

Attendu : **ROUGE**, le module n'existe pas.

- [ ] **Step 3 : Écrire le module**

`resources/js/lib/sidebarCollapsed.ts` :

```ts
/**
 * L'état replié de la barre latérale, mémorisé par navigateur.
 *
 * Dans `localStorage` et non côté serveur : c'est une préférence d'affichage
 * propre au poste, et la faire transiter imposerait un aller-retour à chaque
 * bascule pour un réglage que personne ne veut voir synchronisé.
 *
 * Chaque accès est enveloppé : en navigation privée, ou données de site
 * bloquées, `localStorage` lève au lieu de rendre null. Une barre qui refuse
 * de s'afficher parce qu'elle ne peut pas lire sa préférence serait un bien
 * mauvais échange.
 */
const KEY = 'apha.sidebar.collapsed';

/** Replié seulement sur le marqueur exact : tout le reste ouvre déployé. */
export function readCollapsed(): boolean {
    try {
        return localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
}

export function writeCollapsed(collapsed: boolean): void {
    try {
        localStorage.setItem(KEY, collapsed ? '1' : '0');
    } catch {
        // Préférence perdue, rien de plus : il n'y a rien à rattraper ici.
    }
}
```

- [ ] **Step 4 : Lancer le test**

```bash
npx vitest run resources/js/lib/sidebarCollapsed.test.ts
```

Attendu : 5 tests verts.

- [ ] **Step 5 : Câbler le repli dans la barre latérale**

Dans le `<script setup>` de `ConsoleSidebar.vue` :

```ts
import { ref, watch } from 'vue';
import { readCollapsed, writeCollapsed } from '@/lib/sidebarCollapsed';

const collapsed = ref(readCollapsed());

watch(collapsed, writeCollapsed);
```

Dans le template, la balise racine et le bouton, en pied de la barre, juste
avant `.apha-footer-line` :

```html
    <aside class="apha-sidebar" :class="{ collapsed }">
```

```html
            <button
                type="button"
                class="apha-collapse"
                :aria-label="collapsed ? 'Déployer la navigation' : 'Replier la navigation'"
                :aria-expanded="!collapsed"
                @click="collapsed = !collapsed"
            >
                <component
                    :is="collapsed ? PanelLeftOpen : PanelLeftClose"
                    class="apha-collapse-glyph"
                />
                <span class="apha-collapse-label">Replier</span>
            </button>
```

avec, dans les imports :

```ts
import { PanelLeftClose, PanelLeftOpen } from '@lucide/vue';
```

- [ ] **Step 6 : Écrire le style du rail**

À la fin du `<style scoped>`, **avant** la requête média `max-width: 1023px`
existante :

```css
.apha-collapse {
    display: flex;
    align-items: center;
    gap: 9px;

    width: 100%;
    padding: 9px 10px;

    border: 1px solid var(--border);
    border-radius: 9px;

    background: #fff;

    color: var(--muted);

    font-size: 12.5px;
    font-weight: 600;

    cursor: pointer;
}

.apha-collapse-glyph {
    width: 17px;
    height: 17px;

    flex: none;

    stroke-width: 1.9;
}

/* ---- rail replié ---- */

.apha-sidebar.collapsed {
    width: 62px;
    min-width: 62px;

    padding-left: 9px;
    padding-right: 9px;
}

.apha-sidebar.collapsed .apha-brand-content,
.apha-sidebar.collapsed .apha-nav-label,
.apha-sidebar.collapsed .apha-nav-arrow,
.apha-sidebar.collapsed .apha-space,
.apha-sidebar.collapsed .apha-notices,
.apha-sidebar.collapsed .apha-footer-status,
.apha-sidebar.collapsed .apha-collapse-label {
    display: none;
}

.apha-sidebar.collapsed .apha-nav-item,
.apha-sidebar.collapsed .apha-collapse {
    justify-content: center;

    width: 44px;
    padding-left: 0;
    padding-right: 0;

    gap: 0;
}

/* L'infobulle remplace le libellé disparu. */
.apha-sidebar.collapsed .apha-nav-item {
    position: relative;
}

.apha-sidebar.collapsed .apha-nav-item::after {
    content: attr(data-label);

    position: absolute;
    left: 52px;
    top: 50%;
    transform: translateY(-50%);

    z-index: 40;

    padding: 5px 9px;

    border-radius: 7px;

    background: var(--ink);

    color: #fff;

    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;

    opacity: 0;
    pointer-events: none;

    transition: opacity 0.15s ease;
}

.apha-sidebar.collapsed .apha-nav-item:hover::after,
.apha-sidebar.collapsed .apha-nav-item:focus-visible::after {
    opacity: 1;
}
```

L'infobulle lit `data-label`, donc l'ajouter au `<Link>` :

```html
                    :data-label="item.label"
```

- [ ] **Step 7 : Neutraliser le repli sous 1024 px**

**Sans cela, un utilisateur qui replie sur son ordinateur retrouve une bande
horizontale de 62 px de large sur son téléphone** — la barre y est déjà
horizontale, et le rail n'a aucun sens. À l'intérieur de la requête média
`@media (max-width: 1023px)` existante de `ConsoleSidebar.vue` :

```css
    /*
      Le repli n'existe pas ici : la barre est déjà une bande horizontale, et
      lui superposer un rail de 62 px la réduirait à un timbre-poste.
    */
    .apha-sidebar.collapsed {
        width: 100%;
        min-width: 0;
    }

    .apha-sidebar.collapsed .apha-nav-label,
    .apha-sidebar.collapsed .apha-brand-content {
        display: revert;
    }

    .apha-sidebar.collapsed .apha-nav-item {
        width: auto;
        justify-content: flex-start;
        gap: 9px;
    }

    .apha-sidebar.collapsed .apha-nav-item::after {
        content: none;
    }

    .apha-collapse {
        display: none;
    }
```

- [ ] **Step 8 : Vérifier aux trois largeurs, dans les deux états**

```bash
npm run build
npx vue-tsc --noEmit
shot officine repli-1440 'http://localhost:8000/' 1440
shot officine repli-768 'http://localhost:8000/' 768
shot officine repli-390 'http://localhost:8000/' 390
```

Attendu, état déployé : inchangé à 1440, 768 et 390 ; aucun débordement
horizontal ; le bouton « Replier » absent sous 1024.

Le clic n'est pas possible en headless, et il n'existe aucun paramètre d'URL
pour forcer l'état — en inventer un mettrait du code de test en production.
Le rail se capture donc en basculant temporairement la valeur par défaut :

```bash
cp resources/js/layouts/console/ConsoleSidebar.vue /tmp/sidebar.bak
sed -i 's/const collapsed = ref(readCollapsed());/const collapsed = ref(true);/' \
  resources/js/layouts/console/ConsoleSidebar.vue
npm run build
shot officine rail-1440 'http://localhost:8000/' 1440
shot officine rail-768 'http://localhost:8000/' 768
cp /tmp/sidebar.bak resources/js/layouts/console/ConsoleSidebar.vue
npm run build
```

**Copie de sauvegarde et non `git checkout`** : le fichier porte du travail
non indexé, que `git checkout` écraserait.

Attendu, état replié : rail de 62 px à 1440, icônes centrées, aucun libellé ;
à 768, la barre reste une bande horizontale complète avec ses libellés — le
repli y est neutralisé.

L'infobulle au survol ne se capture pas en headless : **la vérifier à la main**
dans un vrai navigateur, et le dire dans le rapport plutôt que de la compter
comme couverte.

- [ ] **Step 9 : Commit**

```bash
git add resources/js/lib/sidebarCollapsed.ts resources/js/lib/sidebarCollapsed.test.ts resources/js/layouts/console/ConsoleSidebar.vue
git commit -m "feat: replier la barre latérale en rail de 62 px

État mémorisé par navigateur sous apha.sidebar.collapsed, chaque accès
enveloppé : en navigation privée localStorage lève, et la barre doit alors
s'ouvrir déployée plutôt que planter la page. Cinq tests Vitest couvrent le
module, dont les deux cas qui lèvent.

Le repli est neutralisé sous 1024 px, où la barre est déjà horizontale.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4 : Le serif et la typographie de la coquille

**Files:**
- Modify: `resources/js/layouts/console/ConsoleHeader.vue`
- Modify: `resources/js/layouts/console/ConsoleSidebar.vue`

**Interfaces:**
- Consumes: les tokens `--text-*` posés au lot 1.
- Produces: rien de nommé.

- [ ] **Step 1 : Passer le titre d'écran en serif**

Dans `ConsoleHeader.vue`, la ligne du titre :

```html
            <div
                class="mt-2 font-serif text-[34px]/[1.06] text-ink lg:text-[34px]"
            >
                <slot name="title">{{ title }}</slot>
            </div>
```

Le titre passe de `text-[22px] font-bold` sans serif à `font-serif` à 34 px.
`Instrument Serif` n'a qu'une graisse : **ne pas lui appliquer `font-bold`**,
qui déclencherait une graisse synthétique baveuse.

- [ ] **Step 2 : Grossir la navigation latérale**

Dans `ConsoleSidebar.vue`, `.apha-nav-item` : `font-size` de sa valeur
actuelle à `14.5px`. `.apha-brand-name` : à `17px`.

```bash
grep -n -A6 '^\.apha-nav-item {' resources/js/layouts/console/ConsoleSidebar.vue
grep -n -A6 '^\.apha-brand-name {' resources/js/layouts/console/ConsoleSidebar.vue
```

- [ ] **Step 3 : Vérifier que rien ne déborde**

La barre reste à 212 px et les libellés grandissent : « Exporter mes données »
est le plus long, c'est lui qui décide.

```bash
npm run build
shot officine typo-1440 'http://localhost:8000/' 1440
shot officine typo-390 'http://localhost:8000/' 390
```

Attendu : aucun libellé sur deux lignes, aucun débordement. Si « Exporter mes
données » passe à la ligne, **élargir la barre à 224 px** plutôt que réduire la
police : la demande était de grossir les caractères.

- [ ] **Step 4 : Lancer la suite**

```bash
php artisan test --compact
npx vue-tsc --noEmit
```

- [ ] **Step 5 : Commit**

```bash
git add resources/js/layouts/console
git commit -m "style: serif sur le titre d'écran, navigation latérale grossie

Le titre passe de Jakarta 22 px à Instrument Serif 34 px — la police
d'affichage était déclarée dans le thème et ne servait sur aucun écran
quotidien. Pas de font-bold : Instrument Serif n'a qu'une graisse, et la
demander en produirait une synthétique.

Navigation de ~13 à 14,5 px, marque de 15 à 17 px.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5 : Le slot `#hero` et le bandeau vert sous 1024 px

**Aucune page ne remplit `#hero` dans ce lot.** Le slot est bâti ici pour que
le tableau de bord y pose sa rangée de chiffres au lot 3 ; d'ici là, le
bandeau porte la remarque et le titre, ce qui est déjà l'essentiel du gain.

**Files:**
- Modify: `resources/js/layouts/console/ConsoleHeader.vue`

**Interfaces:**
- Consumes: les tokens de couleur et l'`identity` calculée dans le composant.
- Produces: un slot nommé `hero` sur `ConsoleHeader`, rendu sous le titre
  au-dessus de 1024 px et **dans** le bandeau en dessous. Consommé au lot 3.

- [ ] **Step 1 : Réécrire le template**

`ConsoleHeader.vue`, la partie `<template>` seule — le `<script setup>` ne
change pas :

```html
<template>
    <div class="console-header">
        <div class="header-band">
            <div class="header-text">
                <div class="header-eyebrow">{{ identity }}</div>

                <div class="header-title">
                    <slot name="title">{{ title }}</slot>
                </div>
            </div>

            <div class="header-hero"><slot name="hero" /></div>
        </div>

        <div class="header-actions">
            <slot name="filters" />
            <slot name="action" />
        </div>
    </div>
</template>
```

- [ ] **Step 2 : Écrire les deux formes**

Ajouter un `<style scoped>` au composant :

```css
.console-header {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.header-eyebrow {
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;

    color: rgb(20 29 24 / 0.72);
}

.header-title {
    margin-top: 8px;

    font-family: 'Instrument Serif', ui-serif, Georgia, serif;
    font-size: 34px;
    line-height: 1.06;

    color: var(--ink);
}

.header-hero:empty {
    display: none;
}

.header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

/* À partir de 1024 px : en-tête clair, actions à droite du titre. */
@media (min-width: 1024px) {
    .console-header {
        flex-direction: row;
        align-items: flex-end;
        justify-content: space-between;
    }

    .header-band {
        min-width: 0;
    }
}

/*
  Sous 1024 px : le même bloc se replie en bandeau vert. À 390 px, trois
  cartes claires empilées repoussent le contenu utile hors de l'écran ; un
  bandeau tient titre et chiffres en un seul pavé.
*/
@media (max-width: 1023px) {
    .header-band {
        padding: 17px 18px 18px;

        border-radius: 16px;

        background: var(--officine-dark);
    }

    .header-eyebrow {
        color: rgb(255 255 255 / 0.55);
    }

    .header-title {
        font-size: 27px;
        line-height: 1.08;

        color: #fff;
    }

    .header-hero:not(:empty) {
        margin-top: 16px;
    }
}
```

- [ ] **Step 3 : Vérifier que les écrans existants n'ont pas bougé de structure**

`ConsoleHeader` est monté par une douzaine d'écrans, tous avec `title` et
souvent `#filters`. Le slot `#hero` étant vide partout, `:empty` le masque.

**Risque à vérifier en premier :** plusieurs pages passent une classe au
composant (`class="exports-header"`, par exemple), qui atterrit sur la racine.
La racine passe ici de `flex flex-col gap-4 sm:flex-row …` en utilitaires
Tailwind à `.console-header` en CSS scopé. Une page dont la règle
`.exports-header` supposait la disposition Tailwind précédente peut se
décaler. Avant de capturer, relever qui passe une classe :

```bash
grep -rn 'ConsoleHeader' resources/js/pages --include='*.vue' -A3 | grep -n 'class='
```

Chaque page trouvée est à regarder à 1440 et 390.

```bash
npm run build
npx vue-tsc --noEmit
for w in 1440 768 390; do
  shot officine hero-dash "http://localhost:8000/" $w
  shot officine hero-hist "http://localhost:8000/pharmacy/history" $w
  shot admin hero-net "http://localhost:8000/admin/network" $w
done
```

Attendu : à 1440, en-tête clair, titre serif, filtres à droite. À 768 et 390,
bandeau vert profond portant remarque et titre en blanc, filtres en dessous.
Aucun débordement horizontal.

- [ ] **Step 4 : Lancer la suite complète**

```bash
php artisan test --compact
npx vue-tsc --noEmit
npm run build
npx prettier --check resources/js resources/css
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --dirty --format agent
```

Attendu : 593 tests verts (590 + 2 tâche 1 + 1 tâche 2), 5 tests Vitest,
0 erreur partout.

- [ ] **Step 5 : Commit**

```bash
git add resources/js/layouts/console/ConsoleHeader.vue
git commit -m "feat: bandeau vert sous 1024 px et slot hero dans l'en-tête

Un seul bloc qui change de forme, pas deux chartes : la liste d'alertes,
l'échelle typographique et le serif sont identiques des deux côtés. Seul
l'en-tête se replie, parce qu'à 390 px trois cartes empilées repoussent les
alertes hors de l'écran.

Le slot hero n'est rempli par aucune page ici ; le tableau de bord y posera
sa rangée de chiffres au lot 3.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 6 : Consigner la règle**

Enregistrer avec l'outil Boost `record-rule` :

- glob : `resources/js/layouts/**, app/Support/ConsoleNavigation.php`
- titre : `L'icône de navigation vient du serveur, et deux tests la relient au front`
- note : la clé `icon` est posée par `ConsoleNavigation` et traduite par
  `resources/js/lib/navIcons.ts` ; `NavIconCoverageTest` confronte les deux
  listes en lisant les sources, seul moyen de couverture puisque Vitest tourne
  en environnement node et ne monte aucun composant ; une clé inconnue rend
  `null`, jamais une icône par défaut ; le repli de la barre vit dans
  `sidebarCollapsed.ts` avec `try/catch`, et **n'existe pas sous 1024 px** ;
  `ConsoleHeader` a deux formes autour de 1024 px et un slot `#hero` que seul
  le tableau de bord remplira.
