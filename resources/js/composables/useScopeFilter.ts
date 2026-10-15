import { ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * La ville et les régions d'un écran réseau, exclusives l'une de l'autre.
 *
 * Le serveur fait primer une ville choisie sur les régions
 * (`RegionScopes::filterFrom()`) ; vider l'une quand on choisit l'autre
 * garde l'écran honnête sur ce qu'il filtre vraiment.
 */
export function useScopeFilter(
    initialCity: string | null,
    initialRegions: number[],
): { city: Ref<string | null>; regions: Ref<number[]> } {
    const city = ref<string | null>(initialCity);
    const regions = ref<number[]>([...initialRegions]);

    watch(city, (chosen) => {
        if (chosen !== null && regions.value.length > 0) {
            regions.value = [];
        }
    });

    watch(regions, (chosen) => {
        if (chosen.length > 0 && city.value !== null) {
            city.value = null;
        }
    });

    return { city, regions };
}

/**
 * Les régions d'un lien de téléchargement, au format que lit le serveur.
 */
export function appendRegions(query: URLSearchParams, regions: number[]): void {
    for (const id of regions) {
        query.append('regions[]', String(id));
    }
}
