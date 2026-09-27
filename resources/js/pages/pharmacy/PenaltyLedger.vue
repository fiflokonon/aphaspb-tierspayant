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
    insurer: number | null;
    insurers: { id: number; name: string }[];
    downloadUrl: string;
    pharmacyName: string;
}>();

const period = ref(props.period);
const insurer = ref(props.insurer);

const insurerOptions = computed(() => [
    { value: null, label: 'Tous mes assureurs' },
    ...props.insurers.map((one) => ({ value: one.id, label: one.name })),
]);

/** Le journal est différé : il doit être nommé dans `only` pour revenir. */
watch([period, insurer], () =>
    router.get(
        '/pharmacy/penalties',
        { period: period.value, insurer: insurer.value },
        {
            only: ['penaltyTrend', 'period', 'periodLabel', 'insurer'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    ),
);

/** Chaque lien porte les filtres : le fichier couvre l'écran, pas autre chose. */
const hrefFor = (format: 'csv' | 'xlsx' | 'pdf') => {
    const query = new URLSearchParams({ period: period.value, format });

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
                :subtitle="`${pharmacyName} · ${periodLabel}`"
                filename="aphaspb-journal-penalites-officine"
                :show-insurer-filter="false"
            />

            <Deferred data="penaltyTrend">
                <template #fallback>
                    <ChartSkeleton :height="320" />
                </template>

                <PenaltyLedgerTable
                    v-if="penaltyTrend"
                    :ledger="penaltyTrend"
                />
            </Deferred>

            <div class="exports">
                <span class="exports-label">Exporter ce journal</span>
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

.exports {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.exports-label {
    margin-right: 4px;
    font-size: var(--text-meta);
    color: var(--muted-foreground);
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
