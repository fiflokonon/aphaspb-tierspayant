<script setup lang="ts">
/**
 * La carte « Évolution des pénalités », partagée par les deux tableaux de
 * bord et les deux pages Journal.
 *
 * Toutes les séries arrivent dans la charge différée : basculer d'horloge ou
 * restreindre à un assureur filtre ce que le navigateur détient déjà, sans
 * aller-retour — même choix que l'écran des tendances. Les clés d'URL sont
 * préfixées `penalty_` : `chart` appartient déjà au graphique voisin.
 */
import { Deferred } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import ChartToolbar from '@/components/aphaspb/charts/ChartToolbar.vue';
import PenaltyTrendChart from '@/components/aphaspb/charts/PenaltyTrendChart.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import { useQueryId, useQueryState } from '@/composables/useQueryState';
import { exportChartToPng } from '@/lib/chartPng';
import { visibleSeries } from '@/lib/penaltySeries';
import { CHART_COLORS, isLineOrBar, isPenaltyView } from '@/types/aphaspb';
import type { PenaltyLedger, PenaltyView } from '@/types/aphaspb';

const props = withDefaults(
    defineProps<{
        ledger?: PenaltyLedger;
        /** Sous-titre du PNG : l'officine, ou la période et la ville. */
        subtitle: string;
        filename: string;
        /** Faux sur les pages Journal, où le filtre assureur est côté serveur. */
        showInsurerFilter?: boolean;
    }>(),
    { ledger: undefined, showInsurerFilter: true },
);

const view = useQueryState<PenaltyView>(
    'penalty_view',
    'accrued',
    isPenaltyView,
);
const chartType = useQueryState('penalty_chart', 'line', isLineOrBar);
const insurer = useQueryId('penalty_insurer');

const VIEWS: { value: PenaltyView; label: string }[] = [
    { value: 'accrued', label: 'Courue par mois' },
    { value: 'declared', label: 'Par mois déclaré' },
    { value: 'due', label: 'Reste due' },
];

const insurerOptions = computed(() => [
    { value: null, label: 'Tous les assureurs' },
    ...(props.ledger?.insurers ?? []).map((one) => ({
        value: one.insurerId,
        label: one.name,
    })),
]);

const shown = computed(() =>
    visibleSeries(props.ledger, props.showInsurerFilter ? insurer.value : null),
);

const hasWithheld = computed(() =>
    [...shown.value.series, ...(shown.value.total ? [shown.value.total] : [])]
        .flatMap((one) => one.months)
        .some((month) => month.withheld),
);

const CAPTIONS: Record<PenaltyView, string> = {
    accrued:
        'Pénalité tombée chaque mois calendaire, toutes factures confondues · le mois en cours est partiel.',
    declared:
        'Pénalité à ce jour des factures de chaque mois déclaré · un mois encore ouvert continue de croître.',
    due: 'Pénalité courue chaque mois, moins ce qui a été clos payé ou annulé · ce qui peut encore être réclamé.',
};

const caption = computed(() => CAPTIONS[view.value]);

const chartArea = ref<HTMLElement | null>(null);
const exporting = ref(false);

async function exportChart() {
    exporting.value = true;

    try {
        await exportChartToPng(chartArea.value, {
            title: 'Évolution des pénalités',
            subtitle: `${props.subtitle} · ${VIEWS.find((one) => one.value === view.value)?.label ?? ''}`,
            legend: [
                ...shown.value.series.map((one, index) => ({
                    label: one.name,
                    color: CHART_COLORS[index % CHART_COLORS.length] as string,
                    dashed: index >= CHART_COLORS.length,
                    shape:
                        chartType.value === 'bar'
                            ? ('square' as const)
                            : ('line' as const),
                })),
                ...(shown.value.total
                    ? [
                          {
                              label: shown.value.total.name,
                              color: 'rgb(23 33 28 / 0.42)',
                              dashed: true,
                          },
                      ]
                    : []),
            ],
            filename: props.filename,
        });
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <section
        class="rounded-[var(--radius-card)] bg-card p-5 shadow-[var(--surface-shadow)]"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-section font-semibold text-ink">
                    Évolution des pénalités
                </h2>
                <p class="mt-1 text-meta text-ink/60">{{ caption }}</p>
            </div>

            <ChartToolbar
                v-model="chartType"
                :types="['line', 'bar']"
                :exporting="exporting"
                @export="exportChart"
            >
                <template #filters>
                    <div
                        class="flex items-center gap-0.5 rounded-[10px] border border-input bg-card p-0.5"
                        role="group"
                        aria-label="Horloge de la pénalité"
                    >
                        <button
                            v-for="one in VIEWS"
                            :key="one.value"
                            type="button"
                            class="flex h-[30px] items-center rounded-lg px-2.5 text-[11.5px] font-semibold transition-colors"
                            :class="
                                view === one.value
                                    ? 'bg-ink text-white'
                                    : 'text-ink/55 hover:bg-cream-header'
                            "
                            :aria-pressed="view === one.value"
                            @click="view = one.value"
                        >
                            {{ one.label }}
                        </button>
                    </div>

                    <FilterSelect
                        v-if="
                            showInsurerFilter &&
                            ledger &&
                            ledger.insurers.length > 1
                        "
                        v-model="insurer"
                        :options="insurerOptions"
                        size="compact"
                        aria-label="Filtrer les pénalités par assureur"
                    />
                </template>
            </ChartToolbar>
        </div>

        <Deferred data="penaltyTrend">
            <template #fallback>
                <ChartSkeleton class="mt-5" :height="220" />
            </template>

            <p
                v-if="
                    ledger &&
                    ledger.insurers.length === 0 &&
                    ledger.maskedInsurers === 0
                "
                class="mt-5 text-meta text-ink/60"
            >
                Aucun assureur sous convention de pénalité.
            </p>

            <div v-else-if="ledger" ref="chartArea" class="mt-5">
                <PenaltyTrendChart
                    :series="shown.series"
                    :total="shown.total"
                    :view="view"
                    :type="chartType"
                />
            </div>

            <p v-if="hasWithheld" class="mt-3 text-meta text-ink/60">
                Les trous de la courbe sont des mois retenus : trop peu
                d'officines pour publier le chiffre sans en exposer une.
            </p>

            <p
                v-if="ledger && ledger.maskedInsurers > 0"
                class="mt-1 text-meta text-ink/60"
            >
                {{ ledger.maskedInsurers }} assureur(s) masqué(s) sous le seuil
                d'anonymat · compté(s) dans le total.
            </p>
        </Deferred>
    </section>
</template>
