<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import InsufficientDataRow from '@/components/aphaspb/InsufficientDataRow.vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import ProgressMiniBar from '@/components/aphaspb/ProgressMiniBar.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import type { KpiTone } from '@/types/aphaspb';

type Indicator = {
    insurerId: number;
    insurerName: string;
    sufficient: boolean;
    declaringPharmacies: number;
    required: number | null;
    averageDelayDays: number | null;
    standardDelayDays: number | null;
    withinThresholdShare: number | null;
    recoveredWithinDelayShare: number | null;
    rejectionRate: number | null;
    unpaidRate: number | null;
};

type Summary = {
    declaringPharmacies: number;
    declarations: number;
    averageDelayDays: number | null;
    withinThresholdShare: number | null;
    rejectionRate: number | null;
};

const props = defineProps<{
    indicators: Indicator[];
    summary: Summary;
    period: string;
    periods: { value: string; label: string }[];
    city: string | null;
    cities: string[];
}>();

const TEMPLATE = '1.6fr .9fr .85fr 1fr 1fr .75fr .8fr';

const COLUMNS = [
    'ASSUREUR',
    'OFFICINES (n)',
    'DÉLAI MOYEN',
    'DANS LES DÉLAIS',
    'ARGENT DANS LES DÉLAIS',
    'REJET',
    'NON PAYÉ',
];

/**
 * What the network KPIs are read against: the mean of the agreed delays.
 *
 * Stated as such in the card's hint — an average of rules is not a rule.
 */
const networkStandard = computed(() => {
    const agreed = props.indicators
        .map((indicator) => indicator.standardDelayDays)
        .filter((days): days is number => days !== null);

    return agreed.length === 0
        ? 30
        : Math.round(
              agreed.reduce((total, days) => total + days, 0) / agreed.length,
          );
});

/**
 * The delay each row is judged against: the one agreed with that insurer.
 *
 * There is no network-wide threshold any more, so a row that somehow arrives
 * without its own delay falls back to the network average rather than to a
 * constant nobody set.
 */
const standardFor = (indicator: Indicator): number =>
    indicator.standardDelayDays ?? networkStandard.value;

/** A delay past twice the agreed one is what the canvas paints as alarming. */
const isAlarming = (indicator: Indicator): boolean =>
    (indicator.averageDelayDays ?? 0) > standardFor(indicator) * 2;

const shareTone = (share: number | null): KpiTone => {
    if (share === null) {
        return 'neutral';
    }

    if (share >= 50) {
        return 'good';
    }

    return share >= 20 ? 'warn' : 'bad';
};

const delayTone = (days: number | null, standard: number): KpiTone => {
    if (days === null) {
        return 'neutral';
    }

    if (days <= standard) {
        return 'good';
    }

    return days <= standard * 2 ? 'warn' : 'bad';
};

const percent = (value: number | null): string =>
    value === null ? '—' : `${value.toLocaleString('fr-FR')} %`;

const days = (value: number | null): string =>
    value === null ? '—' : `${value.toLocaleString('fr-FR')} j`;

const footer = computed(
    () =>
        `${props.indicators.length} assureurs · ${props.summary.declarations.toLocaleString('fr-FR')} déclarations agrégées · évolution mensuelle en préparation`,
);

const period = ref(props.period);
const city = ref(props.city);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

/** Filters reload only the props they affect, never the whole page. */
function reload() {
    router.get(
        '/admin/network',
        { period: period.value, city: city.value },
        {
            only: ['indicators', 'summary', 'period', 'city'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch([period, city], reload);
</script>

<template>
    <Head title="Statistiques réseau" />

    <div class="network-page">
        <ConsoleHeader
            eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
            title="Performance du réseau"
            class="network-header"
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

            <template #action>
                <div class="header-actions">
                    <!-- EXPORT -->

                    <a
                        href="/admin/csv-exports"
                        class="action-btn action-export"
                    >
                        <span class="action-icon"> ↓ </span>

                        <span> Exporter </span>
                    </a>

                    <!-- <button
                        type="button"
                        class="action-btn action-sort"
                    >

                        <span class="action-icon">
                            ↕
                        </span>

                        <span>
                            Trier
                        </span>

                    </button> -->

                    <Link href="/admin/insurers" class="action-btn action-edit">
                        <span class="action-icon"> ✎ </span>

                        <span> Modifier </span>
                    </Link>
                </div>
            </template>
        </ConsoleHeader>

        <KpiRow :columns="3" class="network-kpis">
            <!-- KPI 1 -->

            <div class="kpi-wrapper">
                <div class="kpi-accent"></div>

                <KpiCard
                    label="OFFICINES DÉCLARANTES"
                    :value="summary.declaringPharmacies.toLocaleString('fr-FR')"
                    tone="neutral"
                    hint="ayant déclaré au moins une fois sur la période"
                />

                <div class="kpi-decoration">
                    <span>⌂</span>
                </div>
            </div>

            <div class="kpi-wrapper">
                <div class="kpi-accent"></div>

                <KpiCard
                    label="DÉLAI MOYEN RÉSEAU"
                    :value="
                        summary.averageDelayDays?.toLocaleString('fr-FR') ?? '—'
                    "
                    unit="jours"
                    :tone="delayTone(summary.averageDelayDays, networkStandard)"
                    hint="statuts payés et partiels confondus"
                />

                <div class="kpi-decoration">
                    <span>◷</span>
                </div>
            </div>

            <div class="kpi-wrapper">
                <div class="kpi-accent gold"></div>

                <KpiCard
                    label="PAYÉ DANS LES DÉLAIS"
                    :value="
                        summary.withinThresholdShare?.toLocaleString('fr-FR') ??
                        '—'
                    "
                    unit="%"
                    :tone="shareTone(summary.withinThresholdShare)"
                >
                    <template #hint>
                        <span class="kpi-hint">
                            selon le délai retenu pour chaque assureur ·

                            <Link href="/admin/insurers" class="threshold-edit">
                                <span> Modifier </span>

                                <span class="threshold-edit-icon"> ↗ </span>
                            </Link>
                        </span>
                    </template>
                </KpiCard>

                <div class="kpi-decoration gold">
                    <span>✓</span>
                </div>
            </div>
        </KpiRow>

        <section class="table-section">
            <div class="table-top-decoration"></div>

            <DataTable
                title="Indicateurs par assureur"
                :columns="COLUMNS"
                :template="TEMPLATE"
                :footer="footer"
                class="network-table"
            >
                <template
                    v-for="indicator in indicators"
                    :key="indicator.insurerId"
                >
                    <InsufficientDataRow
                        v-if="!indicator.sufficient"
                        :template="TEMPLATE"
                        :label="indicator.insurerName"
                        :span="6"
                        :explanation="`${indicator.declaringPharmacies}
                            officine${indicator.declaringPharmacies > 1 ? 's' : ''}
                            déclarante${indicator.declaringPharmacies > 1 ? 's' : ''}
                            — affichage à partir de ${indicator.required},
                            pour garantir l’anonymat`"
                    />

                    <DataTableRow
                        v-else
                        :template="TEMPLATE"
                        :tone="isAlarming(indicator) ? 'alert' : 'default'"
                        class="insurer-row"
                    >
                        <!-- ASSUREUR -->

                        <div class="insurer-cell">
                            <div class="insurer-avatar">
                                {{
                                    indicator.insurerName
                                        .charAt(0)
                                        .toUpperCase()
                                }}
                            </div>

                            <div class="insurer-name">
                                <span>
                                    {{ indicator.insurerName }}
                                </span>

                                <small> Assureur actif </small>
                            </div>
                        </div>

                        <div class="pharmacy-count">
                            <span class="count-number">
                                {{ indicator.declaringPharmacies }}
                            </span>

                            <span class="count-label"> officines </span>
                        </div>

                        <div
                            class="delay-cell"
                            :class="{
                                'delay-alert':
                                    delayTone(
                                        indicator.averageDelayDays,
                                        standardFor(indicator),
                                    ) === 'bad',
                            }"
                        >
                            <span class="delay-value">
                                {{ days(indicator.averageDelayDays) }}
                            </span>

                            <span class="delay-unit"> jours </span>

                            <!--
                                La règle à côté du chiffre : sans elle, la
                                couleur de cette cellule est un verdict sans
                                fondement énoncé.
                            -->
                            <span class="delay-standard">
                                standard {{ days(indicator.standardDelayDays) }}
                            </span>
                        </div>

                        <div class="threshold-cell">
                            <ProgressMiniBar
                                :share="indicator.withinThresholdShare ?? 0"
                                :tone="
                                    shareTone(indicator.withinThresholdShare)
                                "
                                :label="percent(indicator.withinThresholdShare)"
                            />
                        </div>

                        <!--
                            La part des déclarations réglées dans les temps dit
                            combien de mois sont passés dans les clous ; celle-ci
                            dit combien d'argent y est passé. Les deux divergent
                            dès qu'un assureur règle un mois en plusieurs fois :
                            un solde tardif fait sortir toute la déclaration du
                            délai, alors que l'acompte, lui, est bien arrivé.
                        -->
                        <div class="threshold-cell">
                            <ProgressMiniBar
                                :share="
                                    indicator.recoveredWithinDelayShare ?? 0
                                "
                                :tone="
                                    shareTone(
                                        indicator.recoveredWithinDelayShare,
                                    )
                                "
                                :label="
                                    percent(indicator.recoveredWithinDelayShare)
                                "
                            />
                        </div>

                        <div
                            class="rate-cell"
                            :class="{
                                'rate-alert':
                                    (indicator.rejectionRate ?? 0) > 15,
                            }"
                        >
                            <span>
                                {{ percent(indicator.rejectionRate) }}
                            </span>
                        </div>

                        <div class="rate-cell unpaid-cell">
                            <span>
                                {{ percent(indicator.unpaidRate) }}
                            </span>
                        </div>
                    </DataTableRow>
                </template>
            </DataTable>
        </section>

        <p class="page-source">
            Les indicateurs sont calculés à partir des déclarations transmises
            par les officines participantes. Les données individuelles ne sont
            jamais exposées.
        </p>
    </div>
</template>

<style scoped>
.delay-standard {
    display: block;

    font-size: 12.5px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);
}

.network-page {
    /* La palette vient de :root — voir resources/css/app.css. */

    position: relative;

    min-height: 100vh;

    padding-bottom: 50px;
}

.network-header {
    position: relative;

    z-index: 2;
}

.header-filters {
    display: flex;

    align-items: center;

    gap: 7px;

    white-space: nowrap;
}

.header-actions {
    display: flex;

    align-items: center;

    justify-content: flex-end;

    gap: 7px;

    white-space: nowrap;
}

.action-btn {
    height: 36px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    padding: 0 12px;

    border: 1px solid var(--border);

    border-radius: 10px;

    background: #ffffff;

    color: var(--ink);

    font-size: 10px;

    font-weight: 700;

    text-decoration: none;

    white-space: nowrap;

    cursor: pointer;

    transition:
        background 0.2s ease,
        border-color 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.action-icon {
    width: 20px;

    height: 20px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 6px;

    font-size: 12px;

    font-weight: 800;

    line-height: 1;
}

.action-export {
    background: var(--primary);

    border-color: var(--primary);

    color: #ffffff;

    /* box-shadow:
        0 5px 14px
        color-mix(in srgb, var(--officine) 16%, transparent); */
}

.action-export .action-icon {
    background: rgba(255, 255, 255, 0.14);

    color: #ffffff;
}

.action-export:hover {
    background: var(--primary-dark);

    border-color: var(--primary-dark);

    color: #ffffff;

    transform: translateY(-1px);

    /* box-shadow:
        0 8px 18px
        color-mix(in srgb, var(--officine) 20%, transparent); */
}

.action-sort {
    background: #ffffff;

    color: var(--ink);
}

.action-sort .action-icon {
    background: var(--primary-soft);

    color: var(--primary);
}

.action-sort:hover {
    border-color: color-mix(in srgb, var(--officine) 25%, transparent);

    background: var(--cream-state);

    color: var(--primary-dark);

    transform: translateY(-1px);
}

.action-edit {
    background: #fff;

    border-color: color-mix(in srgb, var(--officine) 18%, transparent);

    color: var(--primary-dark);
}

.action-edit .action-icon {
    background: var(--primary-soft);

    color: var(--primary);
}

.action-edit:hover {
    background: var(--primary-soft);

    border-color: color-mix(in srgb, var(--officine) 35%, transparent);

    color: var(--primary-dark);

    transform: translateY(-1px);
    /* 
    box-shadow:
        0 6px 16px
        color-mix(in srgb, var(--officine) 10%, transparent); */
}

.network-kpis {
    margin-bottom: 24px;
}

.kpi-wrapper {
    position: relative;

    overflow: hidden;

    border-radius: 16px;

    transition:
        transform 0.3s ease,
        box-shadow 0.3s ease;

    animation: cardAppear 0.55s ease both;
}

.kpi-wrapper:nth-child(2) {
    animation-delay: 0.08s;
}

.kpi-wrapper:nth-child(3) {
    animation-delay: 0.16s;
}

.kpi-wrapper:hover {
    transform: translateY(-4px);

    box-shadow: 0 14px 30px color-mix(in srgb, var(--ink) 7%, transparent);
}

.kpi-accent {
    position: absolute;

    left: 0;

    top: 18px;

    bottom: 18px;

    width: 3px;

    background: var(--primary);

    border-radius: 0 4px 4px 0;

    z-index: 5;
}

.kpi-accent.gold {
    background: var(--gold-mid);
}

.kpi-decoration {
    position: absolute;

    right: 17px;

    top: 17px;

    width: 38px;

    height: 38px;

    border-radius: 11px;

    background: var(--primary-soft);

    color: var(--primary);

    display: flex;

    align-items: center;

    justify-content: center;

    pointer-events: none;

    transition: transform 0.3s ease;
}

.kpi-decoration.gold {
    background: var(--gold-soft);

    color: var(--gold-mid);
}

.kpi-wrapper:hover .kpi-decoration {
    transform: rotate(8deg) scale(1.08);
}

.table-section {
    position: relative;

    background: white;

    border-radius: var(--radius-card);

    padding: 4px;

    box-shadow: var(--surface-shadow);

    animation: tableAppear 0.65s ease both;

    overflow: hidden;
}

.table-top-decoration {
    position: absolute;

    left: 0;

    top: 0;

    width: 100%;

    height: 3px;

    background: var(--primary);

    opacity: 0.9;
}

.network-table {
    border-radius: 14px;
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

    border-radius: 10px;

    display: flex;

    align-items: center;

    justify-content: center;

    color: var(--primary-dark);

    background: var(--primary-soft);

    font-size: 11px;

    font-weight: 800;

    border: 1px solid color-mix(in srgb, var(--officine) 8%, transparent);

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.insurer-row:hover .insurer-avatar {
    transform: scale(1.08);

    box-shadow: 0 5px 12px color-mix(in srgb, var(--officine) 12%, transparent);
}

.insurer-name {
    display: flex;

    flex-direction: column;

    gap: 2px;
}

.insurer-name span {
    font-weight: 650;

    color: var(--ink);
}

.insurer-name small {
    font-size: 12.5px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);
}

.pharmacy-count {
    display: flex;

    align-items: baseline;

    gap: 4px;
}

.count-number {
    font-weight: 700;

    color: var(--ink);
}

.count-label {
    font-size: 12.5px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);
}

.delay-cell {
    display: flex;

    align-items: baseline;

    gap: 4px;
}

.delay-value {
    font-weight: 750;

    color: var(--ink);
}

.delay-unit {
    font-size: 12.5px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);
}

.delay-alert .delay-value {
    color: var(--terracotta);
}

.rate-cell {
    font-weight: 650;

    color: var(--ink);
}

.rate-alert {
    color: var(--terracotta);
}

.unpaid-cell {
    color: var(--primary-dark);
}

.threshold-edit {
    display: inline-flex;

    align-items: center;

    gap: 4px;

    margin-left: 3px;

    color: var(--primary);

    font-size: 12.5px;

    font-weight: 750;

    text-decoration: none;

    transition:
        color 0.2s ease,
        gap 0.2s ease;
}

.threshold-edit-icon {
    font-size: 10px;

    opacity: 0.7;

    transition: transform 0.2s ease;
}

.threshold-edit:hover {
    color: var(--primary-dark);

    gap: 6px;
}

.threshold-edit:hover .threshold-edit-icon {
    transform: translate(1px, -1px);
}

/*
  Une ligne de métadonnée, plus un panneau : la phrase mérite d'être lisible,
  pas d'occuper une bande avec une icône « i ».
*/
.page-source {
    margin-top: 16px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;
    line-height: 1.5;
}

@keyframes cardAppear {
    from {
        opacity: 0;

        transform: translateY(12px);
    }

    to {
        opacity: 1;

        transform: translateY(0);
    }
}

@keyframes tableAppear {
    from {
        opacity: 0;

        transform: translateY(15px);
    }

    to {
        opacity: 1;

        transform: translateY(0);
    }
}

@media (max-width: 760px) {
    .header-filters {
        width: 100%;

        overflow-x: auto;

        padding-bottom: 2px;

        scrollbar-width: none;
    }

    .header-filters::-webkit-scrollbar {
        display: none;
    }

    .header-actions {
        width: 100%;

        justify-content: stretch;

        gap: 6px;
    }

    .action-btn {
        flex: 1;

        min-width: 0;

        padding: 0 9px;
    }
}

@media (max-width: 640px) {
    .network-page {
        padding-bottom: 80px;
    }

    .header-actions {
        gap: 5px;
    }

    .action-btn {
        height: 34px;

        padding: 0 7px;

        gap: 5px;

        font-size: 12.5px;
    }

    .action-icon {
        width: 18px;

        height: 18px;

        font-size: 11px;
    }

    .table-section {
        padding: 2px;
    }
}

@media (max-width: 400px) {
    .action-btn {
        padding: 0 5px;

        gap: 4px;

        font-size: 12.5px;
    }

    .action-icon {
        width: 17px;

        height: 17px;

        font-size: 10px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .network-page *,
    .network-page *::before,
    .network-page *::after {
        animation-duration: 0.01ms !important;

        animation-iteration-count: 1 !important;

        transition-duration: 0.01ms !important;
    }
}
</style>
