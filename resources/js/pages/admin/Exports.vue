<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

const props = defineProps<{
    downloadUrl: string;
    columns: string[];
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    city: string | null;
    cities: string[];
}>();

const period = ref(props.period);
const city = ref(props.city);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

/** Each link carries the filters, so the file matches the screen above it. */
const hrefFor = (format: 'csv' | 'xlsx') => {
    const query = new URLSearchParams({ period: period.value, format });

    if (city.value) {
        query.set('city', city.value);
    }

    return `${props.downloadUrl}?${query.toString()}`;
};

const csvHref = computed(() => hrefFor('csv'));
const xlsxHref = computed(() => hrefFor('xlsx'));

function reload() {
    router.get(
        '/admin/csv-exports',
        { period: period.value, city: city.value },
        {
            only: ['period', 'periodLabel', 'city'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch([period, city], reload);
</script>

<template>
    <Head title="Exports CSV" />

    <ConsoleHeader eyebrow="RÉSEAU DES OFFICINES · BÉNIN" title="Exports CSV">
        <template #filters>
            <FilterSelect
                v-model="period"
                :options="periods"
                aria-label="Filtrer par période"
            />
            <FilterSelect
                v-model="city"
                :options="cityOptions"
                aria-label="Filtrer par ville"
            />
        </template>
    </ConsoleHeader>

    <div class="mt-5 max-w-[680px]">
        <div class="rounded-[11px] border border-border bg-card p-5">
            <div class="text-[12.5px] font-bold text-ink">
                Statistiques agrégées par assureur
            </div>
            <p class="mt-2 text-[13px]/[1.5] text-ink/60">
                {{ periodLabel
                }}{{ city ? ` · ${city}` : ' · toutes les villes' }}, une ligne
                par assureur. Le classeur Excel porte des cellules numériques,
                donc une colonne s'additionne sans conversion. Le CSV reste là
                pour un réimport : séparateur point-virgule, décimales à la
                virgule, UTF-8 avec BOM pour les accents.
            </p>

            <!--
                Plain anchors, not Inertia Links: these are file downloads, and
                Inertia would try to interpret the response as a page visit.
            -->
            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    :href="xlsxHref"
                    class="inline-flex h-[42px] items-center justify-center rounded-[10px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground"
                >
                    Télécharger le classeur Excel
                </a>
                <a
                    :href="csvHref"
                    class="inline-flex h-[42px] items-center justify-center rounded-[10px] border border-input bg-card px-4 text-[12.5px] font-bold text-ink transition-colors hover:bg-cream-header"
                >
                    Télécharger le CSV
                </a>
            </div>

            <div class="mt-5 border-t border-ink/[0.08] pt-4">
                <div
                    class="font-mono text-[10px] font-semibold tracking-[0.04em] text-ink/[0.45]"
                >
                    COLONNES DU FICHIER
                </div>
                <ul class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                    <li
                        v-for="column in columns"
                        :key="column"
                        class="font-mono text-[11px] text-ink/60"
                    >
                        {{ column }}
                    </li>
                </ul>
            </div>
        </div>

        <div
            class="mt-3 rounded-[11px] border border-gold-mid/40 bg-gold-mid/[0.10] p-4"
        >
            <div class="text-[12.5px] font-bold text-ink">
                Ce que le fichier ne contient jamais
            </div>
            <p class="mt-2 text-[12px]/[1.5] text-ink/70">
                Aucun nom d'officine, aucun montant individuel, aucune note
                privée. Un assureur déclaré par moins de
                <strong class="font-semibold">5 officines</strong> apparaît avec
                la mention « données insuffisantes » et aucun chiffre — la ligne
                est conservée exprès, car une ligne absente se lirait comme une
                absence de données et non comme une rétention volontaire.
            </p>
        </div>
    </div>
</template>
