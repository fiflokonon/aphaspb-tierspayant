<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import PenaltyTrace from '@/components/aphaspb/PenaltyTrace.vue';
import PenaltyTrendCard from '@/components/aphaspb/PenaltyTrendCard.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import type { PenaltyLedger, PenaltyTrace as Trace } from '@/types/aphaspb';

const props = defineProps<{
    penaltyTrend?: PenaltyLedger;
    penaltyTrace?: Trace;
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

/** Le journal et la trace sont différés : ils doivent être nommés dans `only` pour revenir. */
watch([period, insurer], () =>
    router.get(
        '/pharmacy/penalties',
        { period: period.value, insurer: insurer.value },
        {
            only: [
                'penaltyTrend',
                'penaltyTrace',
                'period',
                'periodLabel',
                'insurer',
            ],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    ),
);

/**
 * Le fichier est la trace par facture, à la date du jour : il suit le filtre
 * assureur, pas la période, qui ne borne que la courbe.
 */
const hrefFor = (format: 'csv' | 'xlsx' | 'pdf') => {
    const query = new URLSearchParams({ format });

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

    <div class="history-page">
        <div class="intro-text">
            <h1>Journal des pénalités</h1>
        </div>

        <ConsoleHeader title="" class="intro-text">
            <template #filters>
                <div class="compact-filters">
                    <div class="filter-item">
                        <span class="filter-label">Période de la courbe</span>
                        <FilterSelect
                            v-model="period"
                            :options="periods"
                            aria-label="Filtrer la courbe par période"
                        />
                    </div>

                    <div class="filter-item">
                        <span class="filter-label">Assureur</span>
                        <FilterSelect
                            v-model="insurer"
                            :options="insurerOptions"
                            aria-label="Filtrer par assureur"
                        />
                    </div>
                </div>
            </template>
        </ConsoleHeader>

        <div class="ledger-body">
            <!--
                D'abord ce qui est exigible aujourd'hui et d'où vient chaque
                franc ; la courbe mensuelle ne vient qu'ensuite, en vue d'ensemble.
            -->
            <Deferred data="penaltyTrace">
                <template #fallback>
                    <ChartSkeleton :height="260" />
                </template>

                <PenaltyTrace
                    v-if="penaltyTrace"
                    :key="`${insurer ?? 'all'}`"
                    :trace="penaltyTrace"
                />
            </Deferred>

            <PenaltyTrendCard
                :ledger="penaltyTrend"
                :subtitle="`${pharmacyName} · ${periodLabel}`"
                filename="aphaspb-journal-penalites-officine"
                :show-insurer-filter="false"
            />

            <div class="exports">
                <span class="exports-label text-ink/70"
                    >Exporter le détail par facture</span
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
.history-page {
    /* La palette vient de :root — voir resources/css/app.css. */

    position: relative;
    min-height: 100vh;

    padding-bottom: 60px;
}
.compact-filters {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    /* margin-top: 20px;
    padding-top: 16px; */
    /* border-top: 1px solid var(--border); */
}

.filter-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
    width: 170px;
}

.filter-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ink);
}

.filter-item :deep(select),
.filter-item :deep(button) {
    min-height: 36px;
    height: 36px;
    font-size: 12px;
    border-radius: 9px;
}

@media (max-width: 850px) {
    .compact-filters {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
    }

    .filter-item {
        width: 100%;
    }
}

@media (max-width: 600px) {
    .compact-filters {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 420px) {
    .compact-filters {
        grid-template-columns: 1fr;
    }
}

.intro-text h1 {
    margin: 16px 0 0;

    color: var(--ink);

    font-size: 21px;
    font-weight: 800;

    letter-spacing: -0.025em;
}

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
}

.export-link {
    padding: 7px 14px;
    border: 1px solid var(--input);
    border-radius: 10px;
    background: var(--primary);
    color: var(--primary-foreground);
    font-size: var(--text-meta);
    font-weight: 600;
}

.export-link:hover {
    background: var(--primary-dark);
}
</style>
