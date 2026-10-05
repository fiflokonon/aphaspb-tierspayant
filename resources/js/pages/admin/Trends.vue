<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import ChartToolbar from '@/components/aphaspb/charts/ChartToolbar.vue';
import DelayBarChart from '@/components/aphaspb/charts/DelayBarChart.vue';
import DelayTrendChart from '@/components/aphaspb/charts/DelayTrendChart.vue';
import OutstandingDonutChart from '@/components/aphaspb/charts/OutstandingDonutChart.vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import InsufficientDataRow from '@/components/aphaspb/InsufficientDataRow.vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import PenaltyTrendCard from '@/components/aphaspb/PenaltyTrendCard.vue';
import { useQueryId, useQueryState } from '@/composables/useQueryState';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { exportChartToPng } from '@/lib/chartPng';
import { rankSlices } from '@/lib/donut';
import { formatMillions } from '@/lib/millions';
import type { WithheldReason } from '@/lib/withheld';
import { withheldExplanation } from '@/lib/withheld';
import { CHART_COLORS, isChartType } from '@/types/aphaspb';
import type { KpiTone, PenaltyLedger } from '@/types/aphaspb';

type AmountRow = {
    insurerId: number;
    insurerName: string;
    sufficient: boolean;
    /** Null under the threshold: the exact count never leaves the server. */
    declaringPharmacies: number | null;
    required: number | null;
    withheldReason: WithheldReason | null;
    invoiced: number | null;
    outstanding: number | null;
    recoveryRate: number | null;
};

/**
 * A point resting on fewer officines than the threshold is not drawn: its
 * month is listed under `withheld` (per insurer) or `withheldMonths` (network).
 */
type Trend = {
    insurers: Record<
        number,
        { name: string; points: Record<string, number>; withheld: string[] }
    >;
    network: Record<string, number>;
    withheldMonths: string[];
    threshold: number;
    required: number;
    withheldReason: WithheldReason | null;
};

const props = defineProps<{
    /** Withheld as a whole when it rests on fewer officines than the threshold. */
    summary: {
        withheld: boolean;
        required: number;
        withheldReason: WithheldReason | null;
        invoiced: number | null;
        received: number | null;
        outstanding: number | null;
        recoveryRate: number | null;
        declaringPharmacies: number | null;
        weightedDelayDays: number | null;
        outstandingBeyond90: number | null;
    };
    amounts: AmountRow[];
    /** The mean of the agreed delays: no single one governs the network. */
    threshold: number;
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    city: string | null;
    cities: string[];
    trend?: Trend;
    penaltyTrend?: PenaltyLedger;
}>();

const TEMPLATE = '1.9fr .9fr 1fr 1fr .9fr';
const COLUMNS = ['Assureur', 'Officines (n)', 'Facturé', 'En cours', 'Recouvré'];

const delayTone = (days: number | null): KpiTone => {
    if (days === null) {
        return 'neutral';
    }

    return days <= props.threshold
        ? 'good'
        : days <= props.threshold * 2
          ? 'warn'
          : 'bad';
};

const recoveryTone = (rate: number | null): KpiTone => {
    if (rate === null) {
        return 'neutral';
    }

    return rate >= 80 ? 'good' : rate >= 60 ? 'warn' : 'bad';
};

/** Both the amount and its share, never one without the other. */
const share = (value: number | null): string =>
    value === null || !props.summary.invoiced
        ? '—'
        : `${Math.round((value / props.summary.invoiced) * 100)} %`;

/** What a withheld KPI says under its « retenu ». */
const withheldHint = computed(() =>
    withheldExplanation(props.summary.withheldReason, props.summary.required),
);

/** A withheld KPI shows « retenu », never a zero that would read « rien ». */
const millions = (value: number | null): string =>
    props.summary.withheld ? 'retenu' : formatMillions(value ?? 0);

/** The months of the curve left blank, said in one line under the chart. */
const withheldNote = computed(() => {
    const months = new Set<string>(props.trend?.withheldMonths ?? []);

    Object.values(props.trend?.insurers ?? {}).forEach((one) =>
        one.withheld.forEach((month) => months.add(month)),
    );

    return months.size === 0
        ? null
        : `Points retenus (moins de ${props.summary.required} officines déclarantes ce mois-là, ou dans les villes non publiées) : ${[...months].sort().join(', ')}.`;
});

const period = ref(props.period);
const city = ref(props.city);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

/**
 * The curve is a deferred prop, so it has to be named in `only` for the partial
 * reload to fetch it again — otherwise the KPIs move and the chart does not.
 */
function reload() {
    router.get(
        '/admin/trends',
        { period: period.value, city: city.value },
        {
            only: [
                'summary',
                'amounts',
                'trend',
                'penaltyTrend',
                'period',
                'periodLabel',
                'city',
            ],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch([period, city], reload);

/**
 * Every insurer's curve arrives in the deferred payload, so narrowing to one is
 * a filter over data the browser already holds — no round trip, unlike the
 * period and city filters above which change what the server aggregates.
 */
const allSeries = computed(() =>
    Object.entries(props.trend?.insurers ?? {}).map(([id, one]) => ({
        id: Number(id),
        name: one.name,
        points: one.points,
    })),
);

const chartType = useQueryState('chart', 'line', isChartType);
const chartInsurer = useQueryId('chart_insurer');

const series = computed(() =>
    allSeries.value
        .filter(
            (one) =>
                chartInsurer.value === null || one.id === chartInsurer.value,
        )
        .map((one) => ({ name: one.name, points: one.points })),
);

const insurerOptions = computed(() => [
    { value: null, label: 'Tous les assureurs' },
    ...allSeries.value.map((one) => ({ value: one.id, label: one.name })),
]);

/**
 * The donut leaves days behind for francs, so the heading has to follow it.
 * Titling a distribution of outstanding balances « Évolution du délai de
 * paiement » would be a lie the reader has no way to catch.
 */
const chartHeading = computed(() =>
    chartType.value === 'pie'
        ? {
              title: 'Encours par assureur',
              caption: `Reste à recouvrer sur la période, réparti entre les assureurs · un assureur n'apparaît qu'à partir de ${props.summary.required} officines déclarantes.`,
          }
        : {
              title: 'Évolution du délai de paiement',
              caption: `Délai moyen pondéré par les montants, en jours · ligne de référence à ${props.threshold} j, la moyenne des délais standard des assureurs · un point n'apparaît qu'à partir de ${props.summary.required} officines déclarantes.`,
          },
);

/** Only insurers cleared by the anonymity threshold carry a figure. */
const donutSlices = computed(() =>
    rankSlices(
        props.amounts
            .filter((row) => row.sufficient)
            .map((row) => ({
                label: row.insurerName,
                value: row.outstanding ?? 0,
            })),
    ),
);

const chartArea = ref<HTMLElement | null>(null);
const exporting = ref(false);

async function exportChart() {
    exporting.value = true;

    try {
        await exportChartToPng(chartArea.value, {
            title: chartHeading.value.title,
            subtitle: `${props.periodLabel}${props.city === null ? '' : ` · ${props.city}`}`,
            legend:
                chartType.value === 'pie'
                    ? donutSlices.value.map((slice) => ({
                          label: `${slice.label} · ${slice.share} %`,
                          color: slice.color,
                          shape: 'square' as const,
                      }))
                    : [
                          ...series.value.map((one, index) => ({
                              label: one.name,
                              color: CHART_COLORS[
                                  index % CHART_COLORS.length
                              ] as string,
                              dashed: index >= CHART_COLORS.length,
                              shape:
                                  chartType.value === 'bar'
                                      ? ('square' as const)
                                      : ('line' as const),
                          })),
                          {
                              label: `Seuil ${props.threshold} jours`,
                              color: 'rgb(23 33 28 / 0.38)',
                              dashed: true,
                          },
                      ],
            filename:
                chartType.value === 'pie'
                    ? 'aphaspb-encours-assureurs'
                    : 'aphaspb-delais-reseau',
        });
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
   
<Head title="Évolution du réseau" />
    <div class="network-evolution-page">
      

        <section class="evolution-intro">
            <div class="intro-decoration"></div>

            <div class="intro-main">
                <div class="intro-content">
                    <div class="intro-icon">
                        <span>↗</span>
                    </div>

                    <div class="intro-text">
                        
                        <h1>Évolution du réseau</h1>
                       
                    </div>
                </div>

                <div class="intro-filters">
                    <div class="filter-item">
                       
                        <FilterSelect
                            v-model="period"
                            :options="periods"
                            aria-label="Filtrer par période"
                        />
                    </div>

                    <div class="filter-item">
                       
                        <FilterSelect
                            v-model="city"
                            :options="cityOptions"
                            aria-label="Filtrer par ville"
                        />
                    </div>
                </div>
            </div>
        </section>

        <KpiRow :columns="4" class="evolution-kpis">
            <div class="metric-wrapper">
                <div class="metric-accent primary"></div>

                <KpiCard
                    label="FACTURÉ · RÉSEAU"
                    :value="millions(summary.invoiced)"
                    :unit="summary.withheld ? undefined : 'FCFA'"
                    :hint="
                        summary.withheld
                            ? withheldHint
                            : `${summary.declaringPharmacies} officines déclarantes`
                    "
                />

                <div class="metric-icon primary-icon">
                    <span> F </span>
                </div>
            </div>

            <div class="metric-wrapper">
                <div class="metric-accent success"></div>

                <KpiCard
                    label="ENCAISSÉ"
                    :value="millions(summary.received)"
                    :unit="summary.withheld ? undefined : 'FCFA'"
                    :tone="recoveryTone(summary.recoveryRate)"
                    :hint="
                        summary.withheld
                            ? withheldHint
                            : `${share(summary.received)} du facturé`
                    "
                />

                <div class="metric-icon success-icon">
                    <span> ✓ </span>
                </div>
            </div>

            <div class="metric-wrapper">
                <div class="metric-accent danger"></div>

                <KpiCard
                    label="ENCOURS DU RÉSEAU"
                    :value="millions(summary.outstanding)"
                    :unit="summary.withheld ? undefined : 'FCFA'"
                    :tone="summary.withheld ? 'neutral' : 'bad'"
                    :hint="
                        summary.withheld
                            ? withheldHint
                            : `${share(summary.outstanding)} du facturé · dont ${formatMillions(summary.outstandingBeyond90 ?? 0)} au-delà de 90 j`
                    "
                />

                <div class="metric-icon danger-icon">
                    <span> ! </span>
                </div>
            </div>

            <div class="metric-wrapper">
                <div class="metric-accent gold"></div>

                <KpiCard
                    label="DÉLAI MOYEN PONDÉRÉ"
                    :value="
                        summary.withheld
                            ? 'retenu'
                            : (summary.weightedDelayDays?.toLocaleString(
                                  'fr-FR',
                              ) ?? '—')
                    "
                    :unit="summary.withheld ? undefined : 'jours'"
                    :tone="delayTone(summary.weightedDelayDays)"
                    :hint="
                        summary.withheld
                            ? withheldHint
                            : `délai standard moyen ${threshold} j`
                    "
                />

                <div class="metric-icon gold-icon">
                    <span> ◷ </span>
                </div>
            </div>
        </KpiRow>

        <section class="trend-card">
            <div class="trend-top-line"></div>

            <div class="trend-header">
                <div class="trend-heading">
                    <div class="trend-title-row">
                        <div class="chart-icon">↗</div>

                        <div>
                            <span class="section-label">
                                ANALYSE DU RÉSEAU
                            </span>

                            <h2>{{ chartHeading.title }}</h2>
                        </div>
                    </div>

                    <p>{{ chartHeading.caption }}</p>

                    <p v-if="chartType !== 'pie' && withheldNote">
                        {{ withheldNote }}
                    </p>
                </div>

                <div v-if="chartType !== 'pie'" class="threshold-badge">
                    <span class="threshold-line"></span>

                    <span> Référence · {{ threshold }} jours </span>
                </div>
            </div>

            <div class="chart-toolbar">
                <ChartToolbar
                    v-model="chartType"
                    :exporting="exporting"
                    @export="exportChart"
                >
                    <template #filters>
                        <!--
                            Hidden on the donut: a distribution narrowed to one
                            insurer is a single wedge filling the circle.
                        -->
                        <FilterSelect
                            v-if="chartType !== 'pie'"
                            v-model="chartInsurer"
                            :options="insurerOptions"
                            size="compact"
                            aria-label="Filtrer le graphique par assureur"
                        />
                    </template>
                </ChartToolbar>
            </div>

            <div ref="chartArea" class="chart-container">
                <OutstandingDonutChart
                    v-if="chartType === 'pie'"
                    :slices="donutSlices"
                    :height="220"
                />

                <Deferred v-else data="trend">
                    <template #fallback>
                        <div class="chart-loading">
                            <ChartSkeleton :height="220" />
                        </div>
                    </template>

                    <DelayBarChart
                        v-if="trend && chartType === 'bar'"
                        class="trend-chart"
                        :series="series"
                        :threshold="trend.threshold"
                    />

                    <DelayTrendChart
                        v-else-if="trend"
                        class="trend-chart"
                        :series="series"
                        :network="trend.network"
                        :threshold="trend.threshold"
                    />
                </Deferred>
            </div>

            <div class="trend-footer">
                <div class="trend-info">
                    <span class="legend-dot network"></span>

                    <span> Réseau </span>
                </div>

                <div class="trend-info">
                    <span class="legend-dot threshold"></span>

                    <span> Seuil de référence </span>
                </div>

                <div class="trend-context">
                    <span class="context-label"> DÉLAI ACTUEL </span>

                    <strong>
                        {{
                            summary.withheld
                                ? 'retenu'
                                : (summary.weightedDelayDays?.toLocaleString(
                                      'fr-FR',
                                  ) ?? '—')
                        }}
                        <small v-if="!summary.withheld">j</small>
                    </strong>
                </div>
            </div>
        </section>

       

        <PenaltyTrendCard
            :ledger="penaltyTrend"
            :subtitle="`${periodLabel}${city === null ? '' : ` · ${city}`}`"
            filename="aphaspb-penalites-reseau"
        />
 <br>
        <section class="amounts-section">
            <div class="amounts-top-line"></div>
              <div class="table-section-header">

                <div class="table-title-block">

                    <div class="table-icon">
                      
                    </div>

                    <div style="padding: 10px;">

                     
                       
            <h2 style="font-weight: 700;">Montants agrégés par assureur</h2>

                    </div>

                </div>

            </div>


            <div class="table-top-decoration"></div>

            <DataTable
                title=""
                :columns="COLUMNS"
                :template="TEMPLATE"
                :footer="`Aucun montant individuel : l'agrégation s'ouvre à partir de ${summary.required} officines déclarantes.`"
                class="amounts-table"
            >
                <template v-for="row in amounts" :key="row.insurerId">
                    <InsufficientDataRow
                        v-if="!row.sufficient"
                        :template="TEMPLATE"
                        :label="row.insurerName"
                        :span="4"
                        :explanation="`${withheldExplanation(
                            row.withheldReason,
                            row.required ?? summary.required,
                        )} — les montants restent retenus`"
                    />

                    <DataTableRow
                        v-else
                        :template="TEMPLATE"
                        class="amount-row"
                    >
                        <div class="insurer-cell">
                            <div class="insurer-avatar">
                                {{ row.insurerName?.charAt(0)?.toUpperCase() }}
                            </div>

                            <div class="insurer-details">
                                <span class="insurer-name">
                                    {{ row.insurerName }}
                                </span>

                                <small> Assureur actif </small>
                            </div>
                        </div>

                        <div class="pharmacy-cell">
                            <span class="pharmacy-number">
                                {{ row.declaringPharmacies }}
                            </span>

                            <span class="pharmacy-label"> officines </span>
                        </div>

                        <div class="amount-cell">
                            <span class="amount-value">
                                {{ formatMillions(row.invoiced ?? 0) }}
                            </span>

                            <span class="amount-unit"> FCFA </span>
                        </div>

                        <div class="amount-cell outstanding-cell">
                            <span class="amount-value">
                                {{ formatMillions(row.outstanding ?? 0) }}
                            </span>

                            <span class="amount-unit"> FCFA </span>
                        </div>

                        <div
                            class="recovery-cell"
                            :class="
                                (row.recoveryRate ?? 0) < 60
                                    ? 'recovery-danger'
                                    : 'recovery-success'
                            "
                        >
                            <div class="recovery-value">
                                {{
                                    row.recoveryRate?.toLocaleString('fr-FR') ??
                                    '—'
                                }}

                                <span> % </span>
                            </div>

                            <div class="recovery-bar">
                                <div
                                    class="recovery-fill"
                                    :style="{
                                        width: `${Math.min(
                                            row.recoveryRate ?? 0,
                                            100,
                                        )}%`,
                                    }"
                                ></div>
                            </div>
                        </div>
                    </DataTableRow>
                </template>
            </DataTable>
        </section>

        <div class="evolution-footnote">
            <div class="footnote-icon">i</div>

            <p>
                Les montants présentés sont agrégés afin de préserver l'anonymat
                des officines participantes. Les données individuelles ne sont
                jamais exposées.
            </p>
        </div>
    </div>
</template>

<style scoped>
.network-evolution-page {
    --page-bg: #f7faf8;
    --surface: #ffffff;
    --surface-soft: #fbfdfc;
    --ink: #20372f;
    --muted: #7c8984;
    --muted-soft: #a1aca7;
    --border: #e5ece8;
    --primary: #00664c;
    --primary-dark: #00523e;
    --primary-soft: #eef7f3;
    --success: #27805f;
    --success-soft: #eef8f3;
    --danger: #b96558;
    --danger-soft: #fbf1ef;
    --gold: #b08a45;
    --gold-soft: #fbf7ef;
    --shadow: 0 8px 30px rgb(30 68 57 / 0.055);
    --shadow-hover: 0 15px 38px rgb(30 68 57 / 0.095);

    position: relative;
    width: 100%;
    min-height: 100vh;
    /* padding: 0 10px 64px; */
    color: var(--ink);
    /* background:
        radial-gradient(circle at 92% 5%, rgb(0 102 76 / 0.035), transparent 26%),
        linear-gradient(180deg, #ffffff 0%, var(--page-bg) 48%, #f8faf9 100%); */
    font-family: 'Manrope', sans-serif;
}

.evolution-header {
    position: relative;
    z-index: 5;
}

.header-filters {
    display: none;
}

/* ─────────────────────────────────────────
   INTRODUCTION
───────────────────────────────────────── */

.evolution-intro {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
    margin: 10px 0 25px;
    padding: 21px 24px;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: linear-gradient(110deg, #ffffff 0%, #ffffff 60%, #f6fbf8 100%);
    /* box-shadow: 0 7px 28px rgb(35 70 68 / 0.045); */
    animation: fadeUp 0.5s ease both;
}

.evolution-intro::before {
    position: absolute;
    left: 24px;
    right: 24px;
    top: 0;
    height: 2px;
    border-radius: 0 0 4px 4px;
    background: linear-gradient(90deg, var(--primary), #318a70, var(--gold));
    content: '';
}

.intro-decoration {
    position: absolute;
    right: -70px;
    top: -105px;
    width: 220px;
    height: 220px;
    border: 1px solid rgb(0 102 76 / 0.06);
    border-radius: 50%;
    pointer-events: none;
}

.intro-decoration::after {
    position: absolute;
    right: 30px;
    bottom: 30px;
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: var(--primary-soft);
    content: '';
    opacity: 0.7;
}

.intro-main {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    width: 100%;
    min-width: 0;
    gap: 34px;
}

.intro-content {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 250px;
}

.intro-icon {
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgb(255 255 255 / 0.55);
    border-radius: 14px;
    background: linear-gradient(145deg, var(--primary), var(--primary-dark));
    color: #ffffff;
    font-size: 17px;
    font-weight: 850;
    /* box-shadow: 0 7px 16px rgb(0 102 76 / 0.16); */
}

.intro-text {
    min-width: 0;
}

.intro-eyebrow {
    display: block;
    margin-bottom: 4px;
    color: var(--primary);
    font-size: 8px;
    font-weight: 850;
    letter-spacing: 0.16em;
}

.intro-text h1 {
    margin: 0;
    color: #1f3930;
    font-size: 20px;
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -0.025em;
}

.intro-period {
    display: block;
    margin-top: 5px;
    color: var(--muted);
    font-size: 10px;
    font-weight: 600;
}

.intro-filters {
    display: flex;
    align-items: flex-end;
    flex: 1;
    gap: 12px;
    min-width: 0;
    padding-left: 28px;
    /* border-left: 1px solid #e8eeeb; */
}

.filter-item {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
}

.filter-label {
    color: var(--muted-soft);
    font-size: 8px;
    font-weight: 850;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

/* ─────────────────────────────────────────
   KPI
───────────────────────────────────────── */

.evolution-kpis {
    margin: 12px 0 24px;
}

.metric-wrapper {
    position: relative;
    min-height: 128px;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 17px;
    background: var(--surface);
    /* box-shadow: var(--shadow); */
    isolation: isolate;
    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        border-color 0.25s ease;
}

.metric-wrapper::after {
    position: absolute;
    right: -28px;
    bottom: -38px;
    width: 105px;
    height: 105px;
    border: 1px solid rgb(0 102 76 / 0.055);
    border-radius: 50%;
    content: '';
    pointer-events: none;
}

.metric-wrapper:hover {
    border-color: #d8e5df;
    /* box-shadow: var(--shadow-hover); */
    transform: translateY(-3px);
}

.metric-accent {
    position: absolute;
    left: 0;
    top: 19px;
    bottom: 19px;
    width: 3px;
    z-index: 3;
    border-radius: 0 5px 5px 0;
}

.metric-accent.primary {
    background: var(--primary);
}

.metric-accent.success {
    background: var(--success);
}

.metric-accent.danger {
    background: var(--danger);
}

.metric-accent.gold {
    background: var(--gold);
}

.metric-icon {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 11px;
    font-size: 11px;
    font-weight: 850;
    pointer-events: none;
    transition: transform 0.25s ease;
}

.metric-wrapper:hover .metric-icon {
    transform: scale(1.07) rotate(3deg);
}

.primary-icon {
    background: var(--primary-soft);
    color: var(--primary);
}

.success-icon {
    background: var(--success-soft);
    color: var(--success);
}

.danger-icon {
    background: var(--danger-soft);
    color: var(--danger);
}

.gold-icon {
    background: var(--gold-soft);
    color: var(--gold);
}

/* ─────────────────────────────────────────
   ANALYSE / GRAPHIQUE
───────────────────────────────────────── */

.trend-card {
    position: relative;
    width: 100%;
    margin-bottom: 24px;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: var(--surface);
    /* box-shadow: var(--shadow); */
    animation: fadeUp 0.55s ease 0.04s both;
}

.trend-top-line,
.amounts-top-line {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    height: 3px;
    background: linear-gradient(90deg, var(--primary) 0%, #24866b 58%, var(--gold) 100%);
}

.trend-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 28px;
    padding: 25px 25px 18px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(135deg, #ffffff 0%, #fbfdfc 100%);
}

.trend-heading {
    min-width: 0;
}

.trend-title-row {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chart-icon {
    width: 39px;
    height: 39px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #d9ebe4;
    border-radius: 12px;
    background: var(--primary-soft);
    color: var(--primary);
    font-size: 16px;
    font-weight: 850;
    /* box-shadow: inset 0 0 0 3px rgb(255 255 255 / 0.55); */
}

.section-label {
    display: block;
    margin-bottom: 4px;
    color: var(--primary);
    font-size: 8.5px;
    font-weight: 850;
    letter-spacing: 0.16em;
}

.trend-heading h2 {
    margin: 0;
    color: var(--ink);
    font-size: 18px;
    font-weight: 800;
    line-height: 1.25;
    letter-spacing: -0.025em;
}

.trend-heading p {
    max-width: 780px;
    margin: 9px 0 0;
   color: var(--ink);
    font-size: 12px;
    line-height: 1.55;
}

.threshold-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid rgb(176 138 69 / 0.22);
    border-radius: 10px;
    background: var(--gold-soft);
    color: #8d6d36;
    font-size: 11px;
    font-weight: 750;
    white-space: nowrap;
}

.threshold-line {
    width: 15px;
    height: 2px;
    border-radius: 5px;
    background: var(--gold);
}

.chart-toolbar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    min-height: 51px;
    margin: 0 20px;
    padding: 9px 0;
    border-bottom: 1px solid #edf1ef;
}

.chart-container {
    min-height: 220px;
    padding: 10px 22px 4px;
}

.trend-chart,
.chart-loading {
    width: 100%;
}

.trend-footer {
    display: flex;
    align-items: center;
    gap: 20px;
    margin: 8px 20px 18px;
    padding: 12px 14px;
    border: 1px solid #edf1ef;
    border-radius: 12px;
    background: var(--surface-soft);
}

.trend-info {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 650;
}

.legend-dot {
    width: 7px;
    height: 7px;
    flex-shrink: 0;
    border-radius: 50%;
}

.legend-dot.network {
    background: var(--primary);
    /* box-shadow: 0 0 0 3px var(--primary-soft); */
}

.legend-dot.threshold {
    background: var(--gold);
    /* box-shadow: 0 0 0 3px var(--gold-soft); */
}

.trend-context {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
    padding-left: 18px;
    border-left: 1px solid var(--border);
}

.context-label {
    color: var(--muted-soft);
    font-size: 8.5px;
    font-weight: 850;
    letter-spacing: 0.14em;
}

.trend-context strong {
    color: var(--ink);
    font-size: 12px;
    font-weight: 850;
}

.trend-context small {
    color: var(--muted);
    font-size: 11px;
    font-weight: 650;
}

/* ─────────────────────────────────────────
   PENALITÉS
───────────────────────────────────────── */

.network-evolution-page :deep(.penalty-trend-card) {
    margin-bottom: 24px;
}

/* ─────────────────────────────────────────
   TABLEAU
───────────────────────────────────────── */

.amounts-section {
    position: relative;
    width: 100%;
    overflow: hidden;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: var(--surface);
    /* box-shadow: var(--shadow); */
    animation: fadeUp 0.6s ease 0.08s both;
}

.amounts-top-line {
    opacity: 0.9;
}

.amounts-table {
    border-radius: 15px;
}

.insurer-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.insurer-avatar {
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #dcebe5;
    border-radius: 10px;
    background: linear-gradient(145deg, var(--primary-soft), #f7fbf9);
    color: var(--primary-dark);
    font-size: 10px;
    font-weight: 850;
}

.insurer-details {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.insurer-name {
    overflow: hidden;
    color: var(--ink);
    font-size: 11px;
    font-weight: 750;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.insurer-details small {
    color: var(--muted-soft);
    font-size: 9px;
    font-weight: 600;
}

.pharmacy-cell,
.amount-cell {
    display: flex;
    align-items: baseline;
    gap: 4px;
}

.pharmacy-number,
.amount-value {
    color: var(--ink);
    font-weight: 750;
}

.pharmacy-label,
.amount-unit {
    color: var(--muted-soft);
    font-size: 9px;
    font-weight: 600;
}

.outstanding-cell .amount-value {
    color: var(--danger);
}

.recovery-cell {
    min-width: 95px;
}

.recovery-value {
    color: var(--primary-dark);
    font-size: 11px;
    font-weight: 800;
}

.recovery-value span {
    color: var(--muted-soft);
    font-size: 9px;
    font-weight: 650;
}

.recovery-success {
    color: var(--primary-dark);
}

.recovery-danger {
    color: var(--danger);
}

.recovery-bar {
    width: 82px;
    height: 4px;
    margin-top: 6px;
    overflow: hidden;
    border-radius: 99px;
    background: #e8eeeb;
}

.recovery-fill {
    height: 100%;
    border-radius: inherit;
    background: currentColor;
    opacity: 0.58;
    transition: width 0.5s ease;
}

/* ─────────────────────────────────────────
   NOTE
───────────────────────────────────────── */

.evolution-footnote {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 14px;
    padding: 13px 15px;
    border: 1px solid #e6eee9;
    border-radius: 13px;
    background: linear-gradient(100deg, #f8fcfa, #ffffff);
}

.footnote-icon {
    width: 19px;
    height: 19px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--primary-soft);
    color: var(--primary);
    font-size: 9px;
    font-weight: 850;
}

.evolution-footnote p {
    margin: 0;
    color: var(--muted);
    font-size: 10.5px;
    line-height: 1.55;
}

/* ─────────────────────────────────────────
   ANIMATION
───────────────────────────────────────── */

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ─────────────────────────────────────────
   RESPONSIVE
───────────────────────────────────────── */

@media (max-width: 1100px) {
    .network-evolution-page {
        padding-left: 7px;
        padding-right: 7px;
    }

    .trend-header {
        gap: 18px;
    }

    .trend-heading p {
        max-width: 650px;
    }
}

@media (max-width: 900px) {
    .evolution-intro {
        padding: 20px;
    }

    .intro-main {
        flex-direction: column;
        align-items: stretch;
        gap: 18px;
    }

    .intro-content {
        min-width: 0;
    }

    .intro-filters {
        width: 100%;
        padding-top: 15px;
        padding-left: 0;
        border-top: 1px solid #e8eeeb;
        border-left: 0;
    }

    .trend-header {
        flex-direction: column;
        padding: 22px 19px 17px;
    }

    .threshold-badge {
        align-self: flex-start;
    }

    .chart-toolbar {
        margin: 0 15px;
    }

    .chart-container {
        padding: 8px 12px 3px;
    }

    .trend-footer {
        margin: 8px 15px 15px;
    }
}

@media (max-width: 760px) {
    .network-evolution-page {
        padding: 0 4px 50px;
    }

    .evolution-intro {
        margin-bottom: 19px;
        padding: 19px 17px;
        border-radius: 16px;
    }

    .evolution-intro::before {
        left: 17px;
        right: 17px;
    }

    .intro-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        font-size: 15px;
    }

    .intro-text h1 {
        font-size: 18px;
    }

    .intro-filters {
        gap: 9px;
    }

    .evolution-kpis {
        margin-bottom: 18px;
    }

    .trend-card,
    .amounts-section {
        border-radius: 16px;
    }

    .trend-header {
        padding: 20px 15px 15px;
    }

    .trend-title-row {
        align-items: flex-start;
    }

    .trend-heading h2 {
        font-size: 16px;
    }

    .chart-toolbar {
        min-height: 48px;
        margin: 0 12px;
    }

    .chart-container {
        min-height: 205px;
        padding: 5px 6px 0;
    }

    .trend-footer {
        flex-wrap: wrap;
        gap: 11px 17px;
        margin: 7px 10px 12px;
        padding: 11px 12px;
    }

    .trend-context {
        width: 100%;
        margin-left: 0;
        padding-top: 9px;
        padding-left: 0;
        border-top: 1px solid var(--border);
        border-left: 0;
    }

    .amounts-section {
        padding: 2px;
    }

    .evolution-footnote {
        padding: 11px 12px;
    }
}

@media (max-width: 520px) {
    .header-filters {
        padding: 4px;
        border-radius: 10px;
    }

    .trend-header {
        padding: 18px 13px 14px;
    }

    .chart-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        font-size: 14px;
    }

    .threshold-badge {
        width: 100%;
        justify-content: center;
    }

    .trend-heading p {
        font-size: 11px;
    }

    .chart-toolbar {
        margin: 0 9px;
    }

    .chart-container {
        padding: 3px 2px 0;
    }

    .trend-footer {
        margin: 6px 7px 10px;
    }

    .evolution-footnote {
        gap: 8px;
        margin-top: 10px;
    }

    .evolution-footnote p {
        font-size: 10px;
    }
}

@media (max-width: 400px) {
    .network-evolution-page {
        padding-left: 2px;
        padding-right: 2px;
    }

    .intro-filters {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-item {
        width: 100%;
    }

    .trend-card,
    .amounts-section {
        border-radius: 14px;
    }

    .trend-title-row {
        gap: 9px;
    }

    .section-label {
        font-size: 7.5px;
    }

    .trend-heading h2 {
        font-size: 15px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .network-evolution-page *,
    .network-evolution-page *::before,
    .network-evolution-page *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>

