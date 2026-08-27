# Thème APhaSPB, coquille console et écran 2a (incrément 3A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Habiller l'application de la palette et des fontes du canvas, construire la coquille console partagée par les deux profils, et livrer `2a` — le tableau des indicateurs par assureur — comme premier écran réel.

**Architecture:** La palette passe par les variables CSS que shadcn-vue consomme déjà, donc les 22 primitives installées héritent du thème sans être touchées. La coquille est un unique `ConsoleLayout` que deux layouts de profil remplissent : le canvas montre le même châssis pour l'officine et pour l'admin, seuls le contenu du menu et les cartes de rappel changent. `2a` est choisi comme premier écran parce qu'il n'exige aucun graphique et lit des données que `NetworkStatsService` produit déjà.

**Tech Stack:** Laravel 13.29, Inertia 3.3, Vue 3.5, Tailwind 4, shadcn-vue (new-york-v4), Pest 5.

**Spec:** `docs/superpowers/specs/2026-08-26-aphaspb-design-implementation-design.md` (§7, incrément 3 de §13)

## Global Constraints

- Nommage du code, des routes et des colonnes **en anglais** ; le français reste dans les libellés d'interface.
- Aucun identifiant de groupe Joomla hors de `config/joomla.php` — passer par une Gate nommée.
- **Aucun montant rattachable à une officine, aucune note privée, aucune déclaration individuelle** dans l'espace admin. La lecture passe par `NetworkStatsService`, jamais par une requête directe.
- `<script setup>` et Composition API partout. Un composant Vue a une seule racine.
- `<Link>` d'Inertia, jamais de `<a>` pour la navigation interne. `prefetch` sur la navigation principale.
- URLs via Wayfinder, jamais en dur.
- Régénérer les routes avec **`npm run build`**, jamais `php artisan wayfinder:generate` seul (cf. `.ai/rules/js.md`).
- Vérifier avec **`composer ci:check`** en entier, pas seulement `pint --dirty` et les tests (cf. `.ai/rules/general.md`). Prettier et `pint --parallel --test` échappent aux vérifications partielles.
- Tailwind v4 : configuration CSS-first via `@theme`, pas de `tailwind.config.js`. Pas d'utilitaires v3 dépréciés (`bg-opacity-*`, `flex-shrink-*`…).
- Espacement entre frères par `gap`, pas par marges.

## Décision assumée : le thème sombre disparaît

Le canvas ne fournit aucune palette sombre, et en inventer une sortirait du périmètre validé. Les pages console sont donc claires uniquement, et toute la mécanique d'apparence est retirée plutôt que laissée à moitié branchée sur un thème qui n'existe plus.

## Palette du canvas, relevée sur les artboards

| Rôle | Valeur | Usage relevé |
|---|---|---|
| Encre | `#17211c` | fond de barre latérale, texte principal |
| Vert officine | `#1f6f4a` | primaire, boutons, valeurs favorables |
| Vert foncé | `#17553a` | survol du primaire |
| Or | `#e8c25c` | accent, titre de rappel, cartouche admin |
| Or moyen | `#d9a325` | remplissage de jauge intermédiaire |
| Or foncé | `#b07c1a` | valeur en alerte modérée |
| Terre cuite | `#c0472f` | destructif, jauge défavorable |
| Terre cuite foncée | `#a8391f` | valeur défavorable |
| Crème de fond | `#fdfbf7` | fond de la zone de contenu |
| Crème d'en-tête | `#f7f5f0` | ligne d'en-tête de tableau |
| Crème d'état | `#faf8f3` | ligne « données insuffisantes » |
| Blanc | `#fff` | cartes, tableaux |

Opacités relevées, à conserver telles quelles : bordures de carte `rgba(23,33,28,.09)`, bordures de champ `rgba(23,33,28,.14)`, séparateurs de ligne `rgba(23,33,28,.06)`, en-tête de tableau `rgba(23,33,28,.08)`, texte secondaire `rgba(23,33,28,.5)`, étiquettes `rgba(23,33,28,.45)`, jauges vides `rgba(23,33,28,.08)`. Côté barre latérale : élément actif `rgba(255,255,255,.12)`, texte inactif `rgba(255,255,255,.62)`, carte neutre `rgba(255,255,255,.07)` sur bordure `rgba(255,255,255,.14)`, carte or `rgba(232,194,92,.14)` sur bordure `rgba(232,194,92,.3)`.

---

### Task 1: Palette, fontes, logo, et retrait du thème sombre

**Files:**
- Modify: `resources/css/app.css`
- Modify: `vite.config.ts`
- Modify: `resources/views/app.blade.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/settings.php`
- Create: `public/logo-aphaspb.webp`
- Delete: `app/Http/Middleware/HandleAppearance.php`, `resources/js/composables/useAppearance.ts`, `resources/js/components/AppearanceTabs.vue`, `resources/js/pages/settings/Appearance.vue`
- Test: `tests/Feature/ThemeTest.php`

**Interfaces:**
- Consumes: rien
- Produces:
  - Jetons Tailwind : `--color-ink`, `--color-officine`, `--color-officine-dark`, `--color-gold`, `--color-gold-mid`, `--color-gold-dark`, `--color-terracotta`, `--color-terracotta-dark`, `--color-cream`, `--color-cream-header`, `--color-cream-state`
  - Jetons shadcn repeints : `--background`, `--card`, `--primary`, `--accent`, `--destructive`, `--border`, `--input`, `--sidebar-background`, `--sidebar-foreground`
  - `--font-sans` Plus Jakarta Sans, `--font-mono` JetBrains Mono, `--font-serif` Instrument Serif
  - `/logo-aphaspb.webp`

- [ ] **Step 1: Écrire le test qui échoue**

Le thème est du CSS, donc non testable par Pest. Ce qui l'est : que la mécanique d'apparence a bien disparu, et que le logo est servi.

Create `tests/Feature/ThemeTest.php`:

```php
<?php

test('the appearance switcher is gone', function () {
    expect(fn () => route('appearance.edit'))
        ->toThrow(Symfony\Component\Routing\Exception\RouteNotFoundException::class);
});

test('the appearance middleware is no longer registered', function () {
    expect(class_exists(App\Http\Middleware\HandleAppearance::class))->toBeFalse();
});

test('the root view no longer switches on a dark class', function () {
    $blade = file_get_contents(resource_path('views/app.blade.php'));

    expect($blade)->not->toContain('dark')
        ->and($blade)->not->toContain('prefers-color-scheme');
});

test('the logo is published', function () {
    expect(file_exists(public_path('logo-aphaspb.webp')))->toBeTrue();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/ThemeTest.php`
Expected: FAIL — les quatre tests échouent, la route et la classe existent encore.

- [ ] **Step 3: Publier le logo**

```bash
cp "/home/fifonsi/Téléchargements/APHASB/APhaSPB sans fond_-8ea887e5.webp" public/logo-aphaspb.webp
```

- [ ] **Step 4: Repeindre les jetons**

Dans `resources/css/app.css`, remplacer le bloc `:root` par la palette du canvas et **supprimer intégralement le bloc `.dark`**, ainsi que la ligne `@custom-variant dark (&:is(.dark *));` en haut du fichier :

```css
:root {
    /* Palette relevée sur les artboards du canvas APhaSPB. */
    --ink: #17211c;
    --officine: #1f6f4a;
    --officine-dark: #17553a;
    --gold: #e8c25c;
    --gold-mid: #d9a325;
    --gold-dark: #b07c1a;
    --terracotta: #c0472f;
    --terracotta-dark: #a8391f;
    --cream: #fdfbf7;
    --cream-header: #f7f5f0;
    --cream-state: #faf8f3;

    --background: var(--cream);
    --foreground: var(--ink);
    --card: #fff;
    --card-foreground: var(--ink);
    --popover: #fff;
    --popover-foreground: var(--ink);
    --primary: var(--officine);
    --primary-foreground: #fff;
    --secondary: var(--cream-header);
    --secondary-foreground: var(--ink);
    --muted: var(--cream-state);
    --muted-foreground: rgb(23 33 28 / 0.5);
    --accent: var(--gold);
    --accent-foreground: var(--ink);
    --destructive: var(--terracotta);
    --destructive-foreground: #fff;
    --border: rgb(23 33 28 / 0.09);
    --input: rgb(23 33 28 / 0.14);
    --ring: var(--officine);
    --radius: 0.625rem;

    /* La barre latérale du canvas est encre, jamais claire. */
    --sidebar: var(--ink);
    --sidebar-background: var(--ink);
    --sidebar-foreground: #fff;
    --sidebar-primary: var(--gold);
    --sidebar-primary-foreground: var(--ink);
    --sidebar-accent: rgb(255 255 255 / 0.12);
    --sidebar-accent-foreground: #fff;
    --sidebar-border: rgb(255 255 255 / 0.14);
    --sidebar-ring: var(--gold);

    /* Séries de graphiques, pour l'incrément 3C. */
    --chart-1: var(--officine);
    --chart-2: var(--gold-mid);
    --chart-3: var(--terracotta);
    --chart-4: var(--officine-dark);
    --chart-5: var(--gold-dark);
}
```

Puis exposer les couleurs brutes en utilitaires Tailwind, en ajoutant au bloc `@theme inline` existant :

```css
    --color-ink: var(--ink);
    --color-officine: var(--officine);
    --color-officine-dark: var(--officine-dark);
    --color-gold: var(--gold);
    --color-gold-mid: var(--gold-mid);
    --color-gold-dark: var(--gold-dark);
    --color-terracotta: var(--terracotta);
    --color-terracotta-dark: var(--terracotta-dark);
    --color-cream: var(--cream);
    --color-cream-header: var(--cream-header);
    --color-cream-state: var(--cream-state);
```

Et remplacer la déclaration `--font-sans` du bloc `@theme inline` ainsi que le bloc `@layer utilities` qui la redéfinit sur `body, html` :

```css
    --font-sans: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
    --font-mono: 'JetBrains Mono', ui-monospace, monospace;
    --font-serif: 'Instrument Serif', ui-serif, Georgia, serif;
```

Enfin, la bordure par défaut de la couche `base` passe du gris Tailwind à la bordure du canvas :

```css
@layer base {
    *,
    ::after,
    ::before,
    ::backdrop,
    ::file-selector-button {
        border-color: var(--border);
    }
}
```

- [ ] **Step 5: Charger les trois fontes**

Dans `vite.config.ts`, remplacer le bloc `fonts` du plugin `laravel` :

```ts
            fonts: [
                bunny('Plus Jakarta Sans', {
                    variable: '--font-sans',
                    weights: [400, 500, 600, 700, 800],
                }),
                bunny('JetBrains Mono', {
                    variable: '--font-mono',
                    weights: [400, 600, 700],
                }),
                bunny('Instrument Serif', {
                    variable: '--font-serif',
                    weights: [400],
                }),
            ],
```

Les graisses viennent du canvas : 800 pour les valeurs de KPI, 700 pour les titres, 600 pour les libellés capitales, 500 pour le texte inactif, 400 pour le corps. JetBrains Mono ne sert qu'aux chiffres, aux étiquettes capitales et aux libellés d'axes ; Instrument Serif aux titres éditoriaux de `3c`.

- [ ] **Step 6: Nettoyer la vue racine**

Replace `resources/views/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Le canvas APhaSPB est clair uniquement : pas de bascule d'apparence. --}}
        <style>
            html {
                background-color: #fdfbf7;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
```

- [ ] **Step 7: Retirer la mécanique d'apparence**

```bash
git rm app/Http/Middleware/HandleAppearance.php \
       resources/js/composables/useAppearance.ts \
       resources/js/components/AppearanceTabs.vue \
       resources/js/pages/settings/Appearance.vue
```

Puis :
- `bootstrap/app.php` : retirer l'import et l'entrée `HandleAppearance::class` de la pile `web`, et retirer `'appearance'` de la liste `encryptCookies(except: [...])`.
- `routes/settings.php` : retirer la route `appearance.edit`.
- `resources/js/app.ts` : retirer l'import `initializeTheme` et son appel.
- `resources/js/layouts/settings/Layout.vue` : retirer l'entrée de menu « Appearance » et son import de route.

Localiser les restes :

```bash
grep -rn 'appearance\|Appearance\|initializeTheme\|dark:' resources/js app routes bootstrap --include=*.php --include=*.vue --include=*.ts | head -40
```

Les variantes `dark:` restantes dans les composants `ui/` sont inertes une fois le `@custom-variant` retiré, mais elles mentent sur l'intention. Les retirer des composants que la coquille utilise réellement ; laisser les autres, pour ne pas réécrire 22 primitives dans une tâche de thème.

- [ ] **Step 8: Lancer le test et vérifier le rendu**

```bash
vendor/bin/pest tests/Feature/ThemeTest.php
npm run build
```

Expected: PASS, 4 tests ; build réussi et les trois fontes présentes dans le manifeste de fontes.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --format agent
npm run format
git add -A
git commit -m "feat: palette, fontes et logo APhaSPB, sans thème sombre"
```

---

### Task 2: Coquille console partagée

**Files:**
- Create: `resources/js/layouts/console/ConsoleLayout.vue`
- Create: `resources/js/layouts/console/ConsoleSidebar.vue`
- Create: `resources/js/layouts/console/ConsoleSidebarNotice.vue`
- Create: `resources/js/layouts/console/ConsoleHeader.vue`
- Create: `resources/js/layouts/AdminLayout.vue`
- Create: `resources/js/layouts/PharmacyLayout.vue`
- Create: `resources/js/types/console.ts`
- Modify: `resources/js/app.ts`

**Interfaces:**
- Consumes: les jetons de la tâche 1
- Produces:
  - `ConsoleNavItem` : `{ label: string; href: string; active: boolean }`
  - `ConsoleNotice` : `{ tone: 'gold' | 'neutral'; title: string; body: string }`
  - `ConsoleLayout` props : `space: string` (libellé sous la marque, ex. « ESPACE ADMIN », ou `null` côté officine), `nav: ConsoleNavItem[]`, `notices: ConsoleNotice[]`
  - `ConsoleHeader` props : `eyebrow: string`, `title: string` — plus deux emplacements nommés, `filters` et `action`
  - `AdminLayout` et `PharmacyLayout`, résolus par nom de page dans `app.ts`

- [ ] **Step 1: Déclarer les types**

Create `resources/js/types/console.ts`:

```ts
export type ConsoleNavItem = {
    label: string;
    href: string;
    active: boolean;
};

export type ConsoleNoticeTone = 'gold' | 'neutral';

export type ConsoleNotice = {
    tone: ConsoleNoticeTone;
    title: string;
    body: string;
};
```

- [ ] **Step 2: Écrire la carte de rappel**

Create `resources/js/layouts/console/ConsoleSidebarNotice.vue`:

```vue
<script setup lang="ts">
import type { ConsoleNoticeTone } from '@/types/console';

const props = defineProps<{
    tone: ConsoleNoticeTone;
    title: string;
    body: string;
}>();

const isGold = props.tone === 'gold';
</script>

<template>
    <div
        class="rounded-[10px] border p-[13px]"
        :class="
            isGold
                ? 'border-gold/30 bg-gold/[0.14]'
                : 'border-white/[0.14] bg-white/[0.07]'
        "
    >
        <div
            class="text-[11px]/[1.3] font-bold"
            :class="isGold ? 'text-gold' : 'text-white'"
        >
            {{ title }}
        </div>
        <div class="mt-[5px] text-[11px]/[1.45] text-white/[0.7]">
            {{ body }}
        </div>
    </div>
</template>
```

- [ ] **Step 3: Écrire la barre latérale**

Create `resources/js/layouts/console/ConsoleSidebar.vue`:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ConsoleSidebarNotice from './ConsoleSidebarNotice.vue';
import type { ConsoleNavItem, ConsoleNotice } from '@/types/console';

defineProps<{
    space: string | null;
    nav: ConsoleNavItem[];
    notices: ConsoleNotice[];
}>();
</script>

<template>
    <aside
        class="flex w-[212px] shrink-0 flex-col bg-ink px-[14px] py-[18px] text-white"
    >
        <div class="flex items-center gap-[9px]">
            <img
                src="/logo-aphaspb.webp"
                alt=""
                class="size-6 rounded-full bg-white p-[2px]"
            />
            <div class="text-xs font-bold">APhaSPB</div>
        </div>

        <div
            v-if="space"
            class="mt-[6px] font-mono text-[9.5px] font-semibold tracking-[0.06em] text-gold"
        >
            {{ space }}
        </div>

        <nav class="mt-5 flex flex-col gap-[3px]">
            <Link
                v-for="item in nav"
                :key="item.href"
                :href="item.href"
                prefetch
                class="rounded-lg px-[11px] py-[10px] text-[12.5px] transition-colors"
                :class="
                    item.active
                        ? 'bg-white/[0.12] font-semibold text-white'
                        : 'font-medium text-white/[0.62] hover:bg-white/[0.06]'
                "
            >
                {{ item.label }}
            </Link>
        </nav>

        <div class="mt-7 flex flex-col gap-[14px]">
            <ConsoleSidebarNotice
                v-for="notice in notices"
                :key="notice.title"
                v-bind="notice"
            />
        </div>

        <div class="mt-auto" />
    </aside>
</template>
```

- [ ] **Step 4: Écrire le bandeau de titre**

Create `resources/js/layouts/console/ConsoleHeader.vue`:

```vue
<script setup lang="ts">
defineProps<{
    eyebrow: string;
    title: string;
}>();
</script>

<template>
    <div class="flex items-end justify-between gap-4">
        <div>
            <div
                class="font-mono text-[10.5px]/none font-semibold tracking-[0.06em] text-ink/[0.45]"
            >
                {{ eyebrow }}
            </div>
            <div class="mt-2 text-[22px]/[1.2] font-bold text-ink">
                <slot name="title">{{ title }}</slot>
            </div>
        </div>
        <div class="flex gap-2">
            <slot name="filters" />
            <slot name="action" />
        </div>
    </div>
</template>
```

- [ ] **Step 5: Écrire la coquille**

Create `resources/js/layouts/console/ConsoleLayout.vue`:

```vue
<script setup lang="ts">
import ConsoleSidebar from './ConsoleSidebar.vue';
import type { ConsoleNavItem, ConsoleNotice } from '@/types/console';

defineProps<{
    space?: string | null;
    nav: ConsoleNavItem[];
    notices?: ConsoleNotice[];
}>();
</script>

<template>
    <div class="flex min-h-screen bg-cream">
        <ConsoleSidebar
            :space="space ?? null"
            :nav="nav"
            :notices="notices ?? []"
        />
        <main class="flex-1 px-[26px] pt-6 pb-7">
            <slot />
        </main>
    </div>
</template>
```

- [ ] **Step 6: Écrire les deux layouts de profil**

`AdminLayout.vue` et `PharmacyLayout.vue` calculent leur navigation et leurs rappels depuis les props partagées, puis remplissent `ConsoleLayout`. La navigation vient des artboards `2a` et `1c` :

- Admin : Statistiques réseau, Pharmacies inscrites, Gestion des assureurs, Exports CSV, Profil & réglages. Espace : `ESPACE ADMIN`. Rappels : « Vue anonymisée » (neutre) et « Seuil d'affichage » (or).
- Officine : Tableau de bord, Déclarer ce mois, Historique, Mes assureurs, Profil & réglages. Pas d'espace. Rappel : « Rappel du 25 » (or).

Create `resources/js/layouts/AdminLayout.vue`:

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConsoleLayout from './console/ConsoleLayout.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import network from '@/routes/admin/network';
import type { ConsoleNavItem, ConsoleNotice } from '@/types/console';

const page = usePage();
const { currentUrl } = useCurrentUrl();

const nav = computed<ConsoleNavItem[]>(() => [
    { label: 'Statistiques réseau', href: network.url(), active: currentUrl.value.startsWith(network.url()) },
    { label: 'Pharmacies inscrites', href: '#', active: false },
    { label: 'Gestion des assureurs', href: '#', active: false },
    { label: 'Exports CSV', href: '#', active: false },
    { label: 'Profil & réglages', href: '#', active: false },
]);

const notices = computed<ConsoleNotice[]>(() => [
    {
        tone: 'neutral',
        title: 'Vue anonymisée',
        body: "Aucun montant, aucune note privée, aucune déclaration individuelle n'est accessible depuis cet espace.",
    },
    {
        tone: 'gold',
        title: "Seuil d'affichage",
        body: page.props.anonymityNotice as string,
    },
]);
</script>

<template>
    <ConsoleLayout space="ESPACE ADMIN" :nav="nav" :notices="notices">
        <slot />
    </ConsoleLayout>
</template>
```

`PharmacyLayout.vue` suit la même forme, sans `space`, avec l'unique rappel or dont le corps vient d'une prop partagée `monthlyReminder`. Les entrées de menu hors périmètre pointent sur `'#'` jusqu'à la tâche 5, qui leur donne de vraies pages d'attente.

Le composable `useCurrentUrl` existe déjà dans `resources/js/composables/` — le réutiliser plutôt qu'en écrire un.

- [ ] **Step 7: Router les layouts par nom de page**

Dans `resources/js/app.ts`, remplacer le `switch` de résolution de layout :

```ts
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('pharmacy/'):
                return PharmacyLayout;
            case name.startsWith('settings/'):
            case name.startsWith('pharmacies/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
```

`AppLayout` et `SettingsLayout` restent pour les pages de réglages et d'officines héritées du starter kit : les remplacer sort du périmètre de cet incrément.

- [ ] **Step 8: Vérifier**

```bash
npm run build
npm run types:check
npm run lint:check
```

Expected: build réussi, aucune erreur de types, lint propre. Les layouts ne sont pas encore rendus par une page : la tâche 4 les branche.

- [ ] **Step 9: Commit**

```bash
npm run format
git add -A
git commit -m "feat: coquille console partagée par les deux profils"
```

---

### Task 3: Composants métier

**Files:**
- Create: `resources/js/components/aphaspb/KpiCard.vue`
- Create: `resources/js/components/aphaspb/KpiRow.vue`
- Create: `resources/js/components/aphaspb/ProgressMiniBar.vue`
- Create: `resources/js/components/aphaspb/FilterChip.vue`
- Create: `resources/js/components/aphaspb/PrimaryAction.vue`
- Create: `resources/js/components/aphaspb/StatusChip.vue`
- Create: `resources/js/components/aphaspb/DataTable.vue`
- Create: `resources/js/components/aphaspb/DataTableRow.vue`
- Create: `resources/js/components/aphaspb/InsufficientDataRow.vue`
- Create: `resources/js/types/aphaspb.ts`

**Interfaces:**
- Consumes: les jetons de la tâche 1
- Produces:
  - `KpiTone` : `'neutral' | 'good' | 'warn' | 'bad'` — mappe sur `#17211c`, `#1f6f4a`, `#b07c1a`, `#a8391f`
  - `KpiCard` props : `label: string`, `value: string`, `unit?: string`, `hint?: string`, `tone?: KpiTone`, `progress?: number` (0 à 100)
  - `KpiRow` props : `columns?: number` (défaut 3)
  - `ProgressMiniBar` props : `share: number`, `tone: KpiTone`
  - `StatusChip` props : `status: 'paid' | 'partial' | 'unpaid' | 'rejected'`
  - `DataTable` props : `title: string`, `columns: string[]`, `template: string` (valeur `grid-template-columns`), `footer?: string` — emplacements `filters` et par défaut
  - `DataTableRow` props : `template: string`, `tone?: 'default' | 'alert' | 'muted'`
  - `InsufficientDataRow` props : `template: string`, `label: string`, `explanation: string`, `span: number`

- [ ] **Step 1: Déclarer les types**

Create `resources/js/types/aphaspb.ts`:

```ts
export type KpiTone = 'neutral' | 'good' | 'warn' | 'bad';

export const kpiToneClass: Record<KpiTone, string> = {
    neutral: 'text-ink',
    good: 'text-officine',
    warn: 'text-gold-dark',
    bad: 'text-terracotta-dark',
};

export const kpiToneFill: Record<KpiTone, string> = {
    neutral: 'bg-ink',
    good: 'bg-officine',
    warn: 'bg-gold-mid',
    bad: 'bg-terracotta',
};
```

- [ ] **Step 2: Écrire la carte de KPI**

Create `resources/js/components/aphaspb/KpiCard.vue`:

```vue
<script setup lang="ts">
import { kpiToneClass, kpiToneFill, type KpiTone } from '@/types/aphaspb';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string;
        unit?: string;
        hint?: string;
        tone?: KpiTone;
        progress?: number;
    }>(),
    { tone: 'neutral' },
);
</script>

<template>
    <div class="rounded-[11px] border border-border bg-card px-4 py-[15px]">
        <div
            class="font-mono text-[10.5px]/none font-semibold text-ink/[0.45]"
        >
            {{ label }}
        </div>
        <div class="mt-[9px] flex items-baseline gap-[6px]">
            <div
                class="text-[28px]/none font-extrabold"
                :class="kpiToneClass[props.tone]"
            >
                {{ value }}
            </div>
            <div v-if="unit" class="text-xs font-medium text-ink/50">
                {{ unit }}
            </div>
        </div>
        <div
            v-if="progress !== undefined"
            class="mt-[11px] h-[5px] rounded-full bg-ink/[0.08]"
        >
            <div
                class="h-full rounded-full"
                :class="kpiToneFill[props.tone]"
                :style="{ width: `${Math.min(100, Math.max(0, progress))}%` }"
            />
        </div>
        <div
            v-else-if="hint"
            class="mt-[11px] text-[11px]/[1.4] text-ink/50"
        >
            <slot name="hint">{{ hint }}</slot>
        </div>
    </div>
</template>
```

Les valeurs `28px`, `10.5px`, `9px`, `11px` et `5px` viennent des artboards. Elles sortent de l'échelle Tailwind à dessein : les reproduire au pixel est ce qui distingue la coquille du canvas d'une carte shadcn générique.

- [ ] **Step 3: Écrire les composants restants**

Chacun reprend les mesures relevées en tête de plan :

- `KpiRow.vue` : `grid gap-3`, colonnes pilotées par la prop, `mt-5` par rapport au bandeau.
- `ProgressMiniBar.vue` : `flex items-center gap-[7px]`, barre `max-w-[52px] h-[5px] rounded-full bg-ink/[0.08]`, remplissage par `kpiToneFill`, pourcentage à droite coloré par `kpiToneClass`.
- `FilterChip.vue` : `h-[42px] px-[13px] rounded-[10px] bg-card border border-input text-xs font-medium text-ink/70`, en `<button>`. Variante compacte `h-[32px] px-[11px] rounded-lg text-[11.5px] text-ink/[0.65]` via une prop `size: 'default' | 'compact'`, pour les filtres internes au tableau.
- `PrimaryAction.vue` : `h-[42px] px-4 rounded-[10px] bg-primary text-primary-foreground text-[12.5px] font-bold`, rendu en `<Link>` si `href` est fourni, en `<button>` sinon.
- `StatusChip.vue` : quatre statuts, `font-mono text-[10.5px] font-semibold px-[7px] py-[5px] rounded-[5px]` — payé `text-officine bg-officine/[0.12]`, partiel `text-gold-dark bg-gold/[0.18]`, non payé `text-ink/60 bg-ink/[0.07]`, rejeté `text-terracotta-dark bg-terracotta/[0.12]`. Le libellé vient de l'énumération PHP, passé en prop plutôt que redupliqué en TypeScript.
- `DataTable.vue` : carte `rounded-[11px] border border-border bg-card overflow-hidden` ; en-tête `px-4 py-[13px] border-b border-ink/[0.08]` avec titre `text-[12.5px] font-bold` et emplacement `filters` aligné à droite ; ligne de colonnes `px-4 py-[9px] bg-cream-header font-mono text-[10px] font-semibold text-ink/50 tracking-[0.04em]` ; pied optionnel `px-4 py-[11px] border-t border-ink/[0.06] text-[11px] text-ink/[0.45]`. La table scrolle horizontalement dans son propre conteneur `overflow-x-auto` : le corps de page ne doit jamais défiler latéralement.
- `DataTableRow.vue` : `grid gap-[14px] px-4 py-3 border-t border-ink/[0.06] items-center text-xs font-semibold text-ink`, fond `bg-terracotta/[0.04]` en tonalité `alert`, `bg-cream-state` en `muted`.
- `InsufficientDataRow.vue` : ligne `muted` dont la première cellule porte le nom de l'assureur en `text-ink/50`, et dont la seconde s'étend sur `span` colonnes avec le cartouche `DONNÉES INSUFFISANTES` (`font-mono text-[10px] font-semibold text-ink/[0.55] bg-ink/[0.07] px-2 py-[5px] rounded-[5px]`) suivi de l'explication en `text-[11px] text-ink/50`.

- [ ] **Step 4: Vérifier**

```bash
npm run build
npm run types:check
npm run lint:check
```

Expected: build réussi, types et lint propres.

- [ ] **Step 5: Commit**

```bash
npm run format
git add -A
git commit -m "feat: composants de la coquille APhaSPB"
```

---

### Task 4: Écran 2a — indicateurs par assureur

**Files:**
- Create: `app/Http/Controllers/Admin/NetworkStatsController.php`
- Create: `app/Http/Resources/InsurerIndicatorsResource.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/pages/admin/Network.vue`
- Test: `tests/Feature/Admin/NetworkStatsTest.php`

**Interfaces:**
- Consumes: `NetworkStatsService::perInsurer()`, `SettingsRepository`, la Gate `manage-network`, les composants des tâches 2 et 3
- Produces:
  - Route `GET /admin/network`, nommée `admin.network`, sous `auth` + `can:manage-network`
  - Prop partagée `anonymityNotice: string` — « 5 pharmacies minimum · 2 assureurs masqués ce trimestre. »
  - Page `admin/Network`

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Admin/NetworkStatsTest.php`:

```php
<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

/**
 * Give an insurer declarations from $count distinct pharmacies this month.
 */
function declareThisMonth(Insurer $insurer, int $count, array $attributes = []): void
{
    Pharmacy::factory()->count($count)->create()->each(
        fn (Pharmacy $pharmacy) => Declaration::factory()->paid()->create([
            ...$attributes,
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
        ]),
    );
}

test('a network admin sees the per-insurer indicators', function () {
    $insurer = Insurer::factory()->create(['name' => 'NSIA Assurances']);
    declareThisMonth($insurer, 5, ['delay_days' => 29]);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Network')
            ->has('indicators', 1)
            ->where('indicators.0.insurerName', 'NSIA Assurances')
            ->where('indicators.0.declaringPharmacies', 5),
        );
});

test('an insurer under the threshold is rendered as an explained state', function () {
    $insurer = Insurer::factory()->create(['name' => 'Atlantique Assurances']);
    declareThisMonth($insurer, 3);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('indicators.0.sufficient', false)
            ->where('indicators.0.declaringPharmacies', 3)
            ->where('indicators.0.required', 5),
        );
});

test('a pharmacy account cannot reach the network screen', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.network'))
        ->assertForbidden();
});

test('a guest is sent to Joomla to log in', function () {
    $this->get(route('admin.network'))->assertRedirect(route('login'));
});

test('the network screen never exposes a private note or a pharmacy identity', function () {
    $insurer = Insurer::factory()->create();
    $pharmacies = Pharmacy::factory()->count(5)->create();

    foreach ($pharmacies as $pharmacy) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $pharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'private_note' => 'note privee a ne jamais divulguer',
        ]);
    }

    $response = $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'));

    $body = $response->getContent();

    expect($body)->not->toContain('note privee')
        ->and($body)->not->toContain('private_note')
        ->and($body)->not->toContain('pharmacy_id');

    foreach ($pharmacies as $pharmacy) {
        expect($body)->not->toContain($pharmacy->name);
    }
});
```

Le dernier test est celui que le plan 2B avait explicitement laissé dû : la même garantie, vérifiée cette fois au niveau de la route.

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Admin/NetworkStatsTest.php`
Expected: FAIL — `Route [admin.network] not defined.`

- [ ] **Step 3: Écrire la ressource de sérialisation**

Une seule forme sérialisée pour les deux cas, avec un drapeau `sufficient` que la page lit pour choisir entre une ligne de données et une ligne d'état. Cela évite au front de deviner le type par la présence d'un champ.

Create `app/Http/Resources/InsurerIndicatorsResource.php` — une méthode statique `fromEntry(int $insurerId, InsurerIndicators|InsufficientData $entry, string $insurerName)` qui renvoie :

```php
[
    'insurerId' => $insurerId,
    'insurerName' => $insurerName,
    'sufficient' => $entry instanceof InsurerIndicators,
    'declaringPharmacies' => $entry->declaringPharmacies,
    'required' => $entry instanceof InsufficientData ? $entry->required : null,
    'averageDelayDays' => $entry instanceof InsurerIndicators ? $entry->averageDelayDays : null,
    'withinThresholdShare' => ...,
    'rejectionRate' => ...,
    'unpaidRate' => ...,
]
```

**Aucun montant** dans cette ressource : `2a` est la vue « délais et taux ». Les montants apparaîtront en `3c`, avec leur propre ressource.

`InsufficientData` ne porte pas le nom de l'assureur — le contrôleur le résout depuis `Insurer::pluck('name', 'id')`, en une requête, pas une par ligne.

- [ ] **Step 4: Écrire le contrôleur**

`NetworkStatsController::__invoke()` : autorise via `Gate::authorize('manage-network')`, lit la période depuis la requête (trimestre courant par défaut, validé), appelle `perInsurer()`, trie par délai moyen croissant en plaçant les lignes insuffisantes en fin, et rend `admin/Network` avec `indicators`, `period`, `cities` (villes distinctes des officines, pour le filtre) et `threshold`.

- [ ] **Step 5: Partager le rappel du seuil**

Dans `HandleInertiaRequests::share()`, ajouter une prop paresseuse `anonymityNotice`, calculée seulement pour un compte admin :

```php
'anonymityNotice' => fn () => $user?->hasAnyJoomlaGroup(config('joomla.groups.admin'))
    ? $this->anonymityNotice()
    : null,
```

Le corps compte les assureurs masqués sur la période courante et rend « 5 pharmacies minimum · 2 assureurs masqués ce trimestre. » Il passe par `NetworkStatsService`, pas par une requête directe.

- [ ] **Step 6: Déclarer la route**

Dans `routes/web.php` :

```php
Route::middleware(['auth', 'can:manage-network'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('network', NetworkStatsController::class)->name('network');
    });
```

- [ ] **Step 7: Écrire la page**

`resources/js/pages/admin/Network.vue` assemble `ConsoleHeader` (eyebrow « RÉSEAU DES OFFICINES · BÉNIN », titre de période), `KpiRow` à trois cartes (officines déclarantes avec jauge, délai moyen réseau en tonalité `warn`, payé ≤ seuil en tonalité `good` avec le lien « modifier »), puis `DataTable` avec les six colonnes de l'artboard et le gabarit `1.7fr 1fr .9fr 1fr .8fr .9fr`. Chaque ligne est un `DataTableRow` en tonalité `alert` quand le délai dépasse le double du seuil, ou un `InsufficientDataRow` quand `sufficient` est faux. Le pied reprend « N assureurs actifs · N déclarations agrégées ».

Filtres de période et de ville en `FilterChip`, appliqués par **rechargement partiel** — `router.reload({ only: ['indicators'] })` — jamais par rechargement complet des props.

- [ ] **Step 8: Lancer les tests et construire**

```bash
vendor/bin/pest tests/Feature/Admin/NetworkStatsTest.php
npm run build
```

Expected: PASS, 5 tests ; build réussi.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --format agent
npm run format
git add -A
git commit -m "feat: écran admin des indicateurs par assureur"
```

---

### Task 5: Pages d'attente des entrées hors périmètre

Les quatre entrées de menu admin et les quatre entrées officine qui ne sont pas dans le périmètre doivent mener quelque part d'explicite, pas sur `'#'`.

**Files:**
- Create: `resources/js/components/aphaspb/ComingSoon.vue`
- Create: `resources/js/pages/admin/ComingSoon.vue`, `resources/js/pages/pharmacy/ComingSoon.vue`
- Modify: `routes/web.php`, `resources/js/layouts/AdminLayout.vue`, `resources/js/layouts/PharmacyLayout.vue`
- Test: `tests/Feature/Admin/ComingSoonTest.php`

**Interfaces:**
- Consumes: la coquille de la tâche 2
- Produces: routes nommées `admin.pharmacies`, `admin.insurers`, `admin.exports`, et côté officine `pharmacy.declare`, `pharmacy.history`, `pharmacy.insurers` — toutes rendant une page d'attente

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Admin/ComingSoonTest.php`:

```php
<?php

use App\Models\User;

beforeEach(fn () => useJoomlaTestKeys());

test('every admin nav entry resolves to a page', function (string $route) {
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route($route))
        ->assertOk();
})->with(['admin.pharmacies', 'admin.insurers', 'admin.exports']);

test('a pharmacy account cannot reach the admin waiting pages', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.insurers'))
        ->assertForbidden();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Admin/ComingSoonTest.php`
Expected: FAIL — `Route [admin.pharmacies] not defined.`

- [ ] **Step 3: Écrire le composant d'attente**

`ComingSoon.vue` reprend la carte du canvas : titre en `text-[22px] font-bold text-ink`, explication en `text-[13px]/[1.5] text-ink/60`, et le cartouche `font-mono text-[10px] tracking-[0.04em]` portant l'incrément prévu. Ton calme et explicite, comme la ligne « données insuffisantes » : une page d'attente n'est pas une erreur.

- [ ] **Step 4: Déclarer les routes et brancher la navigation**

Ajouter les routes au groupe `admin.` de la tâche 4 et un groupe `pharmacy.` équivalent sous `auth` + `can:declare-payments`. Remplacer les `href: '#'` des deux layouts par les URLs Wayfinder correspondantes.

- [ ] **Step 5: Vérifier l'ensemble de l'incrément**

```bash
npm run build
composer ci:check
```

Expected: `ci:check` passe en entier — Prettier, `vue-tsc`, Pint, PHPStan 0 erreur, toute la suite verte.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: pages d'attente des entrées de navigation hors périmètre"
```

---

## Auto-revue du plan

**Couverture de la spec §7 :** §7.1 palette et fontes (tâche 1), clair uniquement et retrait du sélecteur d'apparence (tâche 1) ; §7.2 coquille partagée, barre latérale encre, bandeau de titre, rappels différenciés par profil (tâche 2) ; §7.3 composants métier — `KpiCard`, `KpiRow`, `StatusChip`, `DataTable`, `InsufficientDataRow`, `ProgressMiniBar` (tâche 3) ; §7.4 écran `2a` (tâche 4) ; §7.5 `prefetch` sur la navigation, rechargements partiels sur les filtres, `<script setup>`, Wayfinder (tâches 2 et 4).

**Non couvert, et c'est voulu :** `AmountField`, `DerivedStatusNotice` et `DelayStepper` servent la déclaration `3a` et appartiennent au plan 3B. Les deux graphiques et les écrans `3b`/`3c` appartiennent au plan 3C, qui installera `@unovis/vue`. L'onboarding appartient à 3B.

**Cohérence des types :** `ConsoleNavItem` et `ConsoleNotice` sont produits par la tâche 2 et consommés par les tâches 4 et 5. `KpiTone` est produit par la tâche 3 et consommé par la tâche 4. La prop partagée `anonymityNotice` est produite par la tâche 4 mais **lue par `AdminLayout` en tâche 2** — l'ordre est donc contraint dans l'autre sens : la tâche 2 doit tolérer une prop absente, d'où le rendu en chaîne vide plutôt qu'une erreur. À traiter à l'écriture de `AdminLayout`.

**Risque principal :** les mesures du canvas sortent de l'échelle Tailwind (`10.5px`, `12.5px`, `[13px]`, `52px`). Les écrire en valeurs arbitraires est délibéré, mais rend le code verbeux et fragile au copier-coller. Si la répétition devient pénible en tâche 3, extraire les récurrentes en jetons `@theme` (`--text-label`, `--text-kpi`) plutôt que de les arrondir à l'échelle par défaut : arrondir ferait dériver le rendu du canvas écran par écran.
