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
 * l'oubli, un trou le montre. `NavIconCoverageTest` le rattrape avant que ça
 * n'atteigne un écran.
 */
export function navIcon(key: string): Component | null {
    return ICONS[key] ?? null;
}
