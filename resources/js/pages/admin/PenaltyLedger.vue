<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import PenaltyLedgerTable from '@/components/aphaspb/PenaltyLedgerTable.vue';
import PenaltyTrendCard from '@/components/aphaspb/PenaltyTrendCard.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import type { PenaltyLedger } from '@/types/aphaspb';

const props = defineProps<{
    penaltyTrend?: PenaltyLedger;
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    city: string | null;
    cities: string[];
    insurer: number | null;
    insurers: { id: number; name: string }[];
    downloadUrl: string;
}>();

const period = ref(props.period);
const city = ref(props.city);
const insurer = ref(props.insurer);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

const insurerOptions = computed(() => [
    { value: null, label: 'Tous les assureurs' },
    ...props.insurers.map((one) => ({ value: one.id, label: one.name })),
]);

/** Le journal est différé : il doit être nommé dans `only` pour revenir. */
watch([period, city, insurer], () =>
    router.get(
        '/admin/penalties',
        { period: period.value, city: city.value, insurer: insurer.value },
        {
            only: ['penaltyTrend', 'period', 'periodLabel', 'city', 'insurer'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    ),
);

/** Chaque lien porte les filtres : le fichier couvre l'écran, pas autre chose. */
const hrefFor = (format: 'csv' | 'xlsx' | 'pdf') => {
    const query = new URLSearchParams({ period: period.value, format });

    if (city.value) {
        query.set('city', city.value);
    }

    if (insurer.value) {
        query.set('insurer', String(insurer.value));
    }

    return `${props.downloadUrl}?${query.toString()}`;
};

const FORMATS = [
    { key: 'pdf' as const, label: 'PDF' },
    { key: 'xlsx' as const, label: 'XLSX' },
    { key: 'csv' as const, label: 'CSV' },
];
</script>

<template>
    <Head title="Journal des pénalités" />

    <div class="penalty-ledger-page">
        <ConsoleHeader title="Journal des pénalités">
            <template #filters>
                <FilterSelect
                    v-model="period"
                    :options="periods"
                    label="Période"
                    aria-label="Filtrer par période"
                />
                <FilterSelect
                    v-model="city"
                    :options="cityOptions"
                    label="Ville"
                    aria-label="Filtrer par ville"
                />
                <FilterSelect
                    v-model="insurer"
                    :options="insurerOptions"
                    label="Assureur"
                    aria-label="Filtrer par assureur"
                />
            </template>
        </ConsoleHeader>

        <div class="ledger-body">
            <PenaltyTrendCard
                :ledger="penaltyTrend"
                :subtitle="`${periodLabel}${city === null ? '' : ` · ${city}`}`"
                filename="aphaspb-journal-penalites-reseau"
                :show-insurer-filter="false"
            />

            <Deferred data="penaltyTrend">
                <template #fallback>
                    <ChartSkeleton :height="320" />
                </template>

                <!--
                    Sans assureur sous convention, un tableau de zéros ne dirait
                    rien de plus que la carte au-dessus : il se tait.
                -->
                <PenaltyLedgerTable
                    v-if="
                        penaltyTrend &&
                        (penaltyTrend.insurers.length > 0 ||
                            penaltyTrend.maskedInsurers > 0)
                    "
                    :ledger="penaltyTrend"
                />

                <p
                    v-if="penaltyTrend && penaltyTrend.maskedInsurers > 0"
                    class="masked-note text-ink/70"
                >
                    Le total couvre aussi
                    {{ penaltyTrend.maskedInsurers }} assureur(s) masqué(s) sous
                    le seuil d'anonymat.
                </p>
            </Deferred>

            <div class="exports">
                <span class="exports-label text-ink/70"
                    >Exporter ce journal</span
                >
                <a
                    v-for="format in FORMATS"
                    :key="format.key"
                    :href="hrefFor(format.key)"
                    class="export-link"
                >
                    {{ format.label }}
                </a>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ledger-body {
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding: 22px 0 40px;
}

.masked-note {
    font-size: var(--text-meta);
}

.exports {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.exports-label {
    margin-right: 4px;
    font-size: var(--text-meta);
}

.export-link {
    padding: 7px 14px;
    border: 1px solid var(--input);
    border-radius: 10px;
    background: var(--card);
    color: var(--ink);
    font-size: var(--text-meta);
    font-weight: 600;
}

.export-link:hover {
    background: var(--cream-header);
}
</style>
