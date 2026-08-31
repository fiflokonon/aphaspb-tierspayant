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
import { useQueryId, useQueryState } from '@/composables/useQueryState';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { exportChartToPng } from '@/lib/chartPng';
import { rankSlices } from '@/lib/donut';
import { formatMillions } from '@/lib/millions';
import { CHART_COLORS, isChartType } from '@/types/aphaspb';
import type { KpiTone } from '@/types/aphaspb';

type AmountRow = {
    insurerId: number;
    insurerName: string;
    sufficient: boolean;
    declaringPharmacies: number;
    required: number | null;
    invoiced: number | null;
    outstanding: number | null;
    recoveryRate: number | null;
};

type Trend = {
    insurers: Record<number, { name: string; points: Record<string, number> }>;
    network: Record<string, number>;
    threshold: number;
};

const props = defineProps<{
    summary: {
        invoiced: number;
        received: number;
        outstanding: number;
        recoveryRate: number | null;
        declaringPharmacies: number;
        weightedDelayDays: number | null;
        outstandingBeyond90: number;
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
}>();

const TEMPLATE = '1.9fr .9fr 1fr 1fr .9fr';
const COLUMNS = ['ASSUREUR', 'OFFICINES (n)', 'FACTURÉ', 'ENCOURS', 'RECOUVRÉ'];

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
const share = (value: number): string =>
    props.summary.invoiced === 0
        ? '—'
        : `${Math.round((value / props.summary.invoiced) * 100)} %`;

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
              caption: `Reste à recouvrer sur la période, réparti entre les assureurs · un assureur n'apparaît qu'à partir de 5 officines déclarantes.`,
          }
        : {
              title: 'Évolution du délai de paiement',
              caption: `Délai moyen pondéré par les montants, en jours · ligne de référence à ${props.threshold} j, la moyenne des délais standard des assureurs · un assureur n'apparaît qu'à partir de 5 officines déclarantes.`,
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
        <ConsoleHeader
            eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
            :title="periodLabel"
            class="evolution-header"
        >
            <template #filters>
                <div class="header-filters">
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
                </div>
            </template>
        </ConsoleHeader>

        <section class="evolution-intro">
            <div class="intro-content">
                <div class="intro-icon">
                    <span class="trend-icon"> ↗ </span>
                </div>

                <div class="intro-text">
                    <span class="intro-eyebrow">
                        OBSERVATOIRE DES PAIEMENTS
                    </span>

                    <h1>Évolution du réseau</h1>

                    <p>
                        Analysez l'évolution des montants facturés, encaissés et
                        en cours, ainsi que les délais de paiement des
                        assureurs.
                    </p>
                </div>
            </div>

            <div class="intro-status">
                <span class="status-dot"></span>

                <span>
                    {{ periodLabel }}
                </span>
            </div>
        </section>

        <KpiRow :columns="4" class="evolution-kpis">
            <div class="metric-wrapper">
                <div class="metric-accent primary"></div>

                <KpiCard
                    label="FACTURÉ · RÉSEAU"
                    :value="formatMillions(summary.invoiced)"
                    unit="FCFA"
                    :hint="`${summary.declaringPharmacies} officines déclarantes`"
                />

                <div class="metric-icon primary-icon">
                    <span> F </span>
                </div>
            </div>

            <div class="metric-wrapper">
                <div class="metric-accent success"></div>

                <KpiCard
                    label="ENCAISSÉ"
                    :value="formatMillions(summary.received)"
                    unit="FCFA"
                    :tone="recoveryTone(summary.recoveryRate)"
                    :hint="`${share(summary.received)} du facturé`"
                />

                <div class="metric-icon success-icon">
                    <span> ✓ </span>
                </div>
            </div>

            <div class="metric-wrapper">
                <div class="metric-accent danger"></div>

                <KpiCard
                    label="ENCOURS DU RÉSEAU"
                    :value="formatMillions(summary.outstanding)"
                    unit="FCFA"
                    tone="bad"
                    :hint="`${share(summary.outstanding)} du facturé · dont ${formatMillions(summary.outstandingBeyond90)} au-delà de 90 j`"
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
                        summary.weightedDelayDays?.toLocaleString('fr-FR') ??
                        '—'
                    "
                    unit="jours"
                    :tone="delayTone(summary.weightedDelayDays)"
                    :hint="`délai standard moyen ${threshold} j`"
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
                            summary.weightedDelayDays?.toLocaleString(
                                'fr-FR',
                            ) ?? '—'
                        }}
                        <small>j</small>
                    </strong>
                </div>
            </div>
        </section>

        <section class="amounts-section">
            <div class="amounts-top-line"></div>

            <DataTable
                title="Montants agrégés par assureur"
                :columns="COLUMNS"
                :template="TEMPLATE"
                footer="Aucun montant individuel : l'agrégation s'ouvre à partir de 5 officines déclarantes."
                class="amounts-table"
            >
                <template v-for="row in amounts" :key="row.insurerId">
                    <InsufficientDataRow
                        v-if="!row.sufficient"
                        :template="TEMPLATE"
                        :label="row.insurerName"
                        :span="4"
                        :explanation="`${row.declaringPharmacies}
                            officine${row.declaringPharmacies > 1 ? 's' : ''}
                            déclarante${row.declaringPharmacies > 1 ? 's' : ''}
                            — les montants s'agrègent à partir de ${row.required}`"
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
    --apha-primary: #008f83;
    --apha-primary-dark: #006f68;
    --apha-primary-soft: #e8f6f3;

    --apha-gold: #d7a33d;
    --apha-gold-soft: #fff8e9;

    --apha-ink: #243333;
    --apha-muted: #788585;
    --apha-light: #a2adad;

    --apha-border: #e7eceb;

    --apha-background: #f7f9f9;

    position: relative;

    width: 100%;

    min-height: 100vh;

    padding: 0 10px 60px;

    /* background:
        radial-gradient(
            circle at 95% 0%,
            rgba(0, 143, 131, .045),
            transparent 28%
        ); */
}

.evolution-header {
    position: relative;

    z-index: 5;
}

.header-filters {
    display: flex;

    align-items: center;

    gap: 8px;
}

.evolution-intro {
    position: relative;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 25px;

    margin: 16px 0 22px;

    padding: 22px 25px;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: linear-gradient(110deg, #ffffff 0%, #f8fcfb 100%);

    /* box-shadow:
        0 8px 30px
        rgba(35, 70, 68, .035); */

    overflow: hidden;

    animation: fadeUp 0.5s ease both;
}

.evolution-intro::after {
    content: '';

    position: absolute;

    right: -70px;

    top: -90px;

    width: 220px;

    height: 220px;

    border-radius: 50%;

    background: radial-gradient(
        circle,
        rgba(0, 143, 131, 0.09),
        transparent 68%
    );

    pointer-events: none;
}

.intro-content {
    position: relative;

    z-index: 1;

    display: flex;

    align-items: center;

    gap: 15px;
}

.intro-icon {
    width: 48px;

    height: 48px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 14px;

    background: linear-gradient(
        135deg,
        var(--apha-primary),
        var(--apha-primary-dark)
    );

    color: white;

    box-shadow: 0 8px 18px rgba(0, 143, 131, 0.18);
}

.trend-icon {
    font-size: 22px;

    font-weight: 800;
}

.intro-text {
    display: flex;

    flex-direction: column;
}

.intro-eyebrow {
    margin-bottom: 3px;

    color: var(--apha-primary);

    font-size: 8.5px;

    font-weight: 850;

    letter-spacing: 0.14em;
}

.intro-text h1 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 20px;

    font-weight: 800;

    letter-spacing: -0.025em;
}

.intro-text p {
    max-width: 720px;

    margin-top: 4px;

    color: var(--apha-muted);

    font-size: 10.5px;

    line-height: 1.55;
}

.intro-status {
    position: relative;

    z-index: 2;

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 8px 11px;

    border: 1px solid rgba(0, 143, 131, 0.08);

    border-radius: 30px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);

    font-size: 9px;

    font-weight: 700;

    white-space: nowrap;
}

.status-dot {
    width: 6px;

    height: 6px;

    border-radius: 50%;

    background: var(--apha-primary);
}

.evolution-kpis {
    margin-bottom: 22px;
}

.metric-wrapper {
    position: relative;

    overflow: hidden;

    border-radius: 16px;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.metric-wrapper:hover {
    transform: translateY(-3px);

    box-shadow: 0 12px 28px rgba(35, 70, 68, 0.065);
}

.metric-accent {
    position: absolute;

    left: 0;

    top: 17px;

    bottom: 17px;

    width: 3px;

    z-index: 5;

    border-radius: 0 4px 4px 0;
}

.metric-accent.primary {
    background: var(--apha-primary);
}

.metric-accent.success {
    background: #4b9b79;
}

.metric-accent.danger {
    background: #c55245;
}

.metric-accent.gold {
    background: var(--apha-gold);
}

.metric-icon {
    position: absolute;

    right: 15px;

    top: 15px;

    width: 35px;

    height: 35px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 10px;

    font-size: 10px;

    font-weight: 850;

    pointer-events: none;

    transition: transform 0.25s ease;
}

.metric-wrapper:hover .metric-icon {
    transform: scale(1.08) rotate(4deg);
}

.primary-icon {
    background: var(--apha-primary-soft);

    color: var(--apha-primary);
}

.success-icon {
    background: #eaf6f0;

    color: #43866a;
}

.danger-icon {
    background: #fcedea;

    color: #b64d42;
}

.gold-icon {
    background: var(--apha-gold-soft);

    color: #b0842b;
}

.trend-card {
    position: relative;

    overflow: hidden;

    width: 100%;

    margin-bottom: 22px;

    padding: 4px;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: #ffffff;

    box-shadow: 0 9px 32px rgba(35, 70, 68, 0.04);

    animation: fadeUp 0.6s ease 0.05s both;
}

.trend-top-line {
    position: absolute;

    left: 0;

    top: 0;

    width: 100%;

    height: 3px;

    background: linear-gradient(
        90deg,
        var(--apha-primary),
        #35a799,
        var(--apha-gold)
    );
}

.trend-header {
    display: flex;

    align-items: flex-start;

    justify-content: space-between;

    gap: 25px;

    padding: 24px 22px 17px;

    border-bottom: 1px solid var(--apha-border);
}

.trend-title-row {
    display: flex;

    align-items: center;

    gap: 11px;
}

.chart-icon {
    width: 35px;

    height: 35px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 10px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary);

    font-size: 15px;

    font-weight: 850;
}

.trend-heading h2 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 15px;

    font-weight: 800;

    letter-spacing: -0.02em;
}

.trend-heading p {
    max-width: 760px;

    margin: 9px 0 0;

    color: var(--apha-muted);

    font-size: 10px;

    line-height: 1.5;
}

.threshold-badge {
    display: inline-flex;

    align-items: center;

    gap: 7px;

    flex-shrink: 0;

    min-height: 32px;

    padding: 0 11px;

    border: 1px solid rgba(215, 163, 61, 0.2);

    border-radius: 8px;

    background: var(--apha-gold-soft);

    color: #94702a;

    font-size: 9px;

    font-weight: 750;

    white-space: nowrap;
}

.threshold-line {
    width: 12px;

    height: 2px;

    border-radius: 4px;

    background: var(--apha-gold);
}

.chart-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 14px;
}

.chart-container {
    min-height: 220px;

    padding: 8px 18px 0;
}

.trend-chart {
    width: 100%;
}

.chart-loading {
    width: 100%;
}

.trend-footer {
    display: flex;

    align-items: center;

    gap: 18px;

    margin: 0 18px;

    padding: 11px 13px;

    border: 1px solid #edf1f0;

    border-radius: 10px;

    background: #fafcfc;
}

.trend-info {
    display: inline-flex;

    align-items: center;

    gap: 6px;

    color: var(--apha-muted);

    font-size: 8.5px;

    font-weight: 650;
}

.legend-dot {
    width: 7px;

    height: 7px;

    border-radius: 50%;
}

.legend-dot.network {
    background: var(--apha-primary);
}

.legend-dot.threshold {
    background: var(--apha-gold);
}

.trend-context {
    display: flex;

    align-items: center;

    gap: 7px;

    margin-left: auto;
}

.context-label {
    color: var(--apha-light);

    font-size: 7px;

    font-weight: 850;

    letter-spacing: 0.08em;
}

.trend-context strong {
    color: var(--apha-ink);

    font-size: 11px;

    font-weight: 850;
}

.trend-context small {
    color: var(--apha-muted);

    font-size: 8px;
}

.amounts-section {
    position: relative;

    overflow: hidden;

    width: 100%;

    padding: 4px;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: #ffffff;

    box-shadow: 0 8px 30px rgba(35, 70, 68, 0.035);

    animation: fadeUp 0.65s ease 0.1s both;
}

.amounts-top-line {
    position: absolute;

    left: 0;

    top: 0;

    width: 100%;

    height: 3px;

    background: linear-gradient(90deg, var(--apha-primary), #35a799);

    opacity: 0.8;
}

.amounts-table {
    border-radius: 14px;
}

.insurer-cell {
    display: flex;

    align-items: center;

    gap: 9px;
}

.insurer-avatar {
    width: 32px;

    height: 32px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 9px;

    background: linear-gradient(135deg, #e6f6f2, #f2faf8);

    border: 1px solid rgba(0, 143, 131, 0.08);

    color: var(--apha-primary-dark);

    font-size: 10px;

    font-weight: 850;
}

.insurer-details {
    display: flex;

    flex-direction: column;

    gap: 2px;
}

.insurer-name {
    color: var(--apha-ink);

    font-size: 11px;

    font-weight: 700;
}

.insurer-details small {
    color: var(--apha-light);

    font-size: 8px;
}

.pharmacy-cell {
    display: flex;

    align-items: baseline;

    gap: 4px;
}

.pharmacy-number {
    color: var(--apha-ink);

    font-weight: 750;
}

.pharmacy-label {
    color: var(--apha-light);

    font-size: 8px;
}

.amount-cell {
    display: flex;

    align-items: baseline;

    gap: 4px;
}

.amount-value {
    color: var(--apha-ink);

    font-weight: 700;
}

.amount-unit {
    color: var(--apha-light);

    font-size: 8px;
}

.outstanding-cell .amount-value {
    color: #b64d42;
}

.recovery-cell {
    min-width: 95px;
}

.recovery-value {
    font-weight: 750;
}

.recovery-value span {
    font-size: 8px;

    font-weight: 600;
}

.recovery-success {
    color: var(--apha-primary-dark);
}

.recovery-danger {
    color: #b64d42;
}

.recovery-bar {
    width: 80px;

    height: 3px;

    margin-top: 5px;

    overflow: hidden;

    border-radius: 5px;

    background: #edf1f0;
}

.recovery-fill {
    height: 100%;

    border-radius: inherit;

    background: currentColor;

    opacity: 0.55;

    transition: width 0.5s ease;
}

.evolution-footnote {
    display: flex;

    align-items: flex-start;

    gap: 9px;

    margin-top: 13px;

    padding: 11px 14px;

    border: 1px solid rgba(0, 143, 131, 0.07);

    border-radius: 11px;

    background: rgba(0, 143, 131, 0.025);
}

.footnote-icon {
    width: 18px;

    height: 18px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: var(--apha-primary);

    color: #ffffff;

    font-size: 9px;

    font-weight: 850;
}

.evolution-footnote p {
    margin: 0;

    color: var(--apha-muted);

    font-size: 9px;

    line-height: 1.5;
}

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

@media (max-width: 1000px) {
    .network-evolution-page {
        padding-left: 6px;

        padding-right: 6px;
    }

    .evolution-intro {
        padding: 20px;
    }

    .trend-header {
        gap: 15px;
    }
}

@media (max-width: 760px) {
    .network-evolution-page {
        padding: 0 4px 50px;
    }

    .evolution-intro {
        align-items: flex-start;

        flex-direction: column;

        margin-top: 10px;

        padding: 17px;

        border-radius: 15px;
    }

    .intro-status {
        width: 100%;

        justify-content: center;
    }

    .intro-icon {
        width: 41px;

        height: 41px;

        border-radius: 11px;
    }

    .intro-text h1 {
        font-size: 17px;
    }

    .intro-text p {
        font-size: 9.5px;
    }

    /* GRAPH */

    .trend-card {
        border-radius: 15px;
    }

    .trend-header {
        align-items: flex-start;

        flex-direction: column;

        padding: 20px 16px 15px;
    }

    .threshold-badge {
        width: fit-content;
    }

    .chart-container {
        padding: 5px 7px 0;
    }

    .trend-footer {
        flex-wrap: wrap;

        margin: 0 10px;
    }

    .trend-context {
        width: 100%;

        margin-left: 0;

        padding-top: 8px;

        border-top: 1px solid #edf1f0;
    }

    /* TABLE */

    .amounts-section {
        border-radius: 15px;

        padding: 2px;
    }
}

@media (max-width: 520px) {
    .intro-content {
        align-items: flex-start;
    }

    .trend-title-row {
        align-items: flex-start;
    }

    .chart-icon {
        width: 31px;

        height: 31px;
    }

    .trend-heading h2 {
        font-size: 13px;
    }

    .trend-heading p {
        font-size: 9px;
    }

    .threshold-badge {
        width: 100%;

        justify-content: center;
    }

    .evolution-footnote {
        padding: 9px 11px;
    }

    .evolution-footnote p {
        font-size: 8.5px;
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
