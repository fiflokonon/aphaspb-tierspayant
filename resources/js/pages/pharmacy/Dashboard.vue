<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { CircleCheck, Clock, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import ChartToolbar from '@/components/aphaspb/charts/ChartToolbar.vue';
import InvoicedVsCollectedChart from '@/components/aphaspb/charts/InvoicedVsCollectedChart.vue';
import JourneyLineChart from '@/components/aphaspb/charts/JourneyLineChart.vue';
import OutstandingDonutChart from '@/components/aphaspb/charts/OutstandingDonutChart.vue';
import DashboardKpis from '@/components/aphaspb/DashboardKpis.vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import PenaltyTrendCard from '@/components/aphaspb/PenaltyTrendCard.vue';
import PrimaryAction from '@/components/aphaspb/PrimaryAction.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { useQueryState } from '@/composables/useQueryState';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { exportChartToPng } from '@/lib/chartPng';
import { rankSlices } from '@/lib/donut';
import { formatAmount } from '@/lib/fcfa';
import { formatMillions } from '@/lib/millions';
import type { DashboardInvitation } from '@/types';
import { isChartType } from '@/types/aphaspb';
import type { PenaltyLedger } from '@/types/aphaspb';

type RecoveryRow = {
    insurerId: number;
    insurerName: string;
    invoiced: number;
    received: number;
    outstanding: number;
    recoveryRate: number | null;
};

type OverdueRow = {
    declarationId: number;
    insurerId: number;
    insurerName: string;
    monthLabel: string;
    depositedOn: string;
    overdueDays: number;
    standardDelayDays: number;
    outstanding: number;
    penalty: number | null;
    insurerUrl: string;
};

type OverdueSummary = {
    count: number;
    hidden: number;
    historyUrl: string;
};

type LateBand = {
    insurerId: number;
    insurerName: string;
    insurerUrl: string;
    count: number;
    outstanding: number;
    penalty: number | null;
    oldestMonthLabel: string;
    oldestOverdueDays: number;
};

type InsurerBands = {
    late: LateBand[];
    owing: {
        count: number;
        outstanding: number;
        insurerNames: string[];
    } | null;
    settled: {
        count: number;
        insurerNames: string[];
    } | null;
};

type JourneyPoint = {
    key: string;
    label: string;
    invoiced: number;
    received: number;
    outstanding: number;
    isCurrent: boolean;
};

const props = defineProps<{
    pharmacyName: string;
    city: string | null;
    summary: {
        invoiced: number;
        received: number;
        outstanding: number;
        recoveryRate: number | null;
        weightedDelayDays: number | null;
        insurers: number;
        declarations: number;
    };
    ageing: { label: string; amount: number }[];
    owed: { insurerName: string; outstanding: number }[];
    recovery: RecoveryRow[];
    overdue: OverdueRow[];
    overdueSummary: OverdueSummary | null;
    insurerBands: InsurerBands;
    declareUrl: string;
    outstandingMonths: { label: string; url: string }[];
    filters: { insurer: number | null };
    journey?: JourneyPoint[];
    penaltyTrend?: PenaltyLedger;
    pendingInvitations?: DashboardInvitation[];
    whatsappInvite?: { url: string } | null;
}>();

const chartType = useQueryState('chart', 'bar', isChartType);
const journeyInsurer = ref<number | null>(props.filters.insurer);

const insurerOptions = computed(() => [
    { value: null, label: 'Tous les assureurs' },
    ...props.recovery.map((row) => ({
        value: row.insurerId,
        label: row.insurerName,
    })),
]);

/**
 * Unlike the trends screen, the journey is aggregated server-side and is not
 * broken down by insurer in the payload, so narrowing it costs a round trip.
 * Only the curve and the filter come back - the KPIs above stay put.
 */
watch(journeyInsurer, (insurer) => {
    router.get(
        window.location.pathname,
        { insurer },
        {
            only: ['journey', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
});

const journeyHeading = computed(() =>
    chartType.value === 'pie'
        ? {
              title: 'Encours par assureur',
              caption:
                  'Reste à recouvrer sur les 12 derniers mois, assureur par assureur.',
          }
        : {
              title: 'Parcours des paiements',
              caption:
                  'Facturé et encaissé, en millions de FCFA, sur les 12 derniers mois.',
          },
);

const donutSlices = computed(() =>
    rankSlices(
        props.owed.map((row) => ({
            label: row.insurerName,
            value: row.outstanding,
        })),
    ),
);

const OVERDUE_TEMPLATE = '1.6fr .8fr 1fr .8fr 1.1fr 1.1fr';
const OVERDUE_COLUMNS = [
    'Assureur',
    'Mois',
    'Déposée',
    'Retard',
    'Reste dû',
    'Pénalité',
];

const overdueFooter =
    'Au-delà du délai convenu avec chaque assureur, compté depuis le dépôt de la facture.';

/**
 * Trois bandes rouges, puis un lien pour le reste.
 *
 * La gravité décroît vite - les bandes sont triées par la plus vieille
 * facture - et six bandes repoussaient les KPI et le graphique sous la ligne
 * de flottaison d'un portable. Les trois premières portent l'essentiel.
 */
const LATE_BANDS_SHOWN = 3;

const showAllLateBands = ref(false);

const visibleLateBands = computed(() =>
    showAllLateBands.value
        ? props.insurerBands.late
        : props.insurerBands.late.slice(0, LATE_BANDS_SHOWN),
);

const hiddenLateBands = computed(
    () => props.insurerBands.late.length - visibleLateBands.value.length,
);

// La table du détail s'ouvre à la demande, puisque les bandes disent déjà qui
// doit quoi.
const showOverdueTable = ref(false);

const journeyArea = ref<HTMLElement | null>(null);
const exporting = ref(false);

const filteredInsurerName = computed(
    () =>
        props.recovery.find((row) => row.insurerId === props.filters.insurer)
            ?.insurerName ?? null,
);

async function exportJourney() {
    exporting.value = true;

    try {
        await exportChartToPng(journeyArea.value, {
            title: journeyHeading.value.title,
            // Le camembert répartit entre TOUS les assureurs : le filtre est
            // masqué dans ce mode, mais sa valeur persiste. Le nommer ici
            // ferait mentir le PNG sur son propre contenu.
            subtitle: [
                props.pharmacyName,
                chartType.value === 'pie' ? null : filteredInsurerName.value,
            ]
                .filter((one) => one !== null)
                .join(' · '),
            legend:
                chartType.value === 'pie'
                    ? donutSlices.value.map((slice) => ({
                          label: `${slice.label} · ${slice.share} %`,
                          color: slice.color,
                          shape: 'square' as const,
                      }))
                    : [
                          {
                              label: 'Facturé',
                              color: 'rgb(20 29 24 / 0.32)',
                              shape:
                                  chartType.value === 'bar'
                                      ? ('square' as const)
                                      : ('line' as const),
                          },
                          {
                              label: 'Encaissé',
                              color: 'var(--officine)',
                              shape:
                                  chartType.value === 'bar'
                                      ? ('square' as const)
                                      : ('line' as const),
                          },
                      ],
            filename:
                chartType.value === 'pie'
                    ? 'aphaspb-encours-assureurs'
                    : 'aphaspb-parcours-paiements',
        });
    } finally {
        exporting.value = false;
    }
}

const RECOVERY_TEMPLATE = '1.9fr 1fr 1fr 1fr .9fr';
const RECOVERY_COLUMNS = [
    'Asuureur',
    'Facturé',
    'Encaissé',
    'Reste dû',
    'Taux',
];

const ageingTotal = props.ageing.reduce((sum, band) => sum + band.amount, 0);
</script>


<template>
    <Head title="Tableau de bord" />
 
    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="dashboard-page">
        <br>
             <div class="intro-text">

                        <h1>Parcours des paiements</h1>

                    </div>
        <ConsoleHeader
            title=""
            class="dashboard-header"
        >
            <template #hero>
                <DashboardKpis
                    :summary="summary"
                    surface="band"
                    class="kpis-band"
                />
            </template>

            <template #action>
                <PrimaryAction
                    label="+ Nouvelle déclaration"
                    :href="declareUrl"
                />
            </template>
        </ConsoleHeader>

        <!-- =====================================================
             SYNTHÈSE DES ASSUREURS
             ===================================================== -->
        <div
            v-if="
                insurerBands.late.length > 0 ||
                insurerBands.owing ||
                insurerBands.settled
            "
            class="bands"
        >
            <!-- Assureurs en retard -->
            <section
                v-for="band in visibleLateBands"
                :key="band.insurerId"
                class="insurer-banner late"
            >
                <div class="insurer-banner-icon-wrap">
                    <TriangleAlert
                        class="insurer-banner-icon"
                        :size="17"
                    />
                </div>

                <p class="insurer-banner-line">
                    <Link
                        :href="band.insurerUrl"
                        class="insurer-banner-name"
                    >
                        {{ band.insurerName }}
                    </Link>

                    <span class="banner-separator">·</span>

                    {{ band.count }}
                    facture{{ band.count > 1 ? 's' : '' }}

                    <span class="banner-separator">·</span>

                    <strong>
                        {{ formatAmount(band.outstanding) }} FCFA
                    </strong>

                    <span class="banner-separator">·</span>

                    plus ancienne {{ band.oldestMonthLabel }}

                    <span class="insurer-banner-days">
                        +{{ band.oldestOverdueDays }} j
                    </span>

                    <template v-if="band.penalty !== null">
                        <span class="banner-separator">·</span>
                        pénalité {{ formatAmount(band.penalty) }}
                    </template>
                </p>
            </section>

            <!-- Assureurs dans le délai -->
            <section
                v-if="insurerBands.owing"
                class="insurer-banner owing"
            >
                <div class="insurer-banner-icon-wrap">
                    <Clock
                        class="insurer-banner-icon"
                        :size="17"
                    />
                </div>

                <p class="insurer-banner-line">
                    {{ insurerBands.owing.count }}
                    assureur{{ insurerBands.owing.count > 1 ? 's' : '' }}
                    dans le délai convenu

                    <span class="banner-separator">·</span>

                    <strong>
                        {{ formatAmount(insurerBands.owing.outstanding) }}
                        FCFA
                    </strong>

                    <span class="banner-separator">·</span>

                    {{ insurerBands.owing.insurerNames.join(', ') }}
                </p>
            </section>

            <!-- Assureurs soldés -->
            <section
                v-if="insurerBands.settled"
                class="insurer-banner settled"
            >
                <div class="insurer-banner-icon-wrap">
                    <CircleCheck
                        class="insurer-banner-icon"
                        :size="17"
                    />
                </div>

                <p class="insurer-banner-line">
                    {{ insurerBands.settled.count }}
                    assureur{{ insurerBands.settled.count > 1 ? 's' : '' }}
                    à jour

                    <span class="banner-separator">·</span>

                    {{ insurerBands.settled.insurerNames.join(', ') }}
                </p>
            </section>

            <!-- Actions -->
            <div
                v-if="hiddenLateBands > 0 || overdue.length > 0"
                class="bands-footer"
            >
                <button
                    v-if="hiddenLateBands > 0"
                    type="button"
                    class="bands-footer-action"
                    @click="showAllLateBands = true"
                >
                    et {{ hiddenLateBands }} autre{{
                        hiddenLateBands > 1 ? 's' : ''
                    }}
                    assureur{{ hiddenLateBands > 1 ? 's' : '' }} en retard
                </button>

                <button
                    v-if="overdue.length > 0"
                    type="button"
                    class="bands-footer-action"
                    :aria-expanded="showOverdueTable"
                    @click="showOverdueTable = !showOverdueTable"
                >
                    {{ showOverdueTable ? 'Masquer' : 'Voir' }} le détail

                    <template v-if="overdueSummary">
                        ({{ overdueSummary.count }}
                        facture{{ overdueSummary.count > 1 ? 's' : '' }})
                    </template>
                </button>
            </div>
        </div>

        <!-- =====================================================
             INVITATION WHATSAPP
             ===================================================== -->
        <p
            v-if="whatsappInvite"
            class="whatsapp-invite"
        >
            <span class="whatsapp-invite-icon">•</span>

            <span>
                Ajoutez un numéro WhatsApp pour que le réseau puisse vous
                joindre.
            </span>

            <Link
                :href="whatsappInvite.url"
                class="whatsapp-invite-link"
            >
                Ajouter un numéro
            </Link>
        </p>

        <!-- =====================================================
             MOIS À RATTRAPER
             ===================================================== -->
        <section
            v-if="outstandingMonths.length > 0"
            class="catch-up"
        >
            <div class="catch-up-header">
                <div class="catch-up-icon">
                    <span>↻</span>
                </div>

                <div class="catch-up-text">
                    <span class="catch-up-label">
                        MOIS À RATTRAPER
                    </span>

                    <p>
                        Ces mois n'ont pas encore été déclarés pour tous vos
                        assureurs. Le rattrapage reste possible douze mois en
                        arrière.
                    </p>
                </div>
            </div>

            <div class="catch-up-months">
                <Link
                    v-for="month in outstandingMonths"
                    :key="month.url"
                    :href="month.url"
                    class="catch-up-month"
                >
                    {{ month.label }}
                    <span class="month-arrow">→</span>
                </Link>
            </div>
        </section>

        <!-- =====================================================
             KPI
             ===================================================== -->
        <DashboardKpis
            :summary="summary"
            surface="light"
            class="kpis-page"
        />

        <!-- =====================================================
             FACTURES EN RETARD
             ===================================================== -->
        <section
            v-if="showOverdueTable"
            class="overdue-section"
        >
            <div class="section-intro">
                <div>
                    <span class="section-eyebrow">
                        SUIVI DES IMPAYÉS
                    </span>

                    <h2>Factures en retard</h2>

                    <p>
                        Les déclarations dont le délai de règlement est dépassé.
                    </p>
                </div>
            </div>

            <DataTable
                v-if="showOverdueTable"
                title="Factures en retard"
                :columns="OVERDUE_COLUMNS"
                :template="OVERDUE_TEMPLATE"
                :footer="overdueFooter"
            >
                <DataTableRow
                    v-for="row in overdue"
                    :key="row.declarationId"
                    :template="OVERDUE_TEMPLATE"
                >
                    <div>
                        <Link
                            :href="row.insurerUrl"
                            class="overdue-insurer"
                        >
                            {{ row.insurerName }}
                        </Link>
                    </div>

                    <div>
                        {{ row.monthLabel }}
                    </div>

                    <div>
                        {{ row.depositedOn }}
                    </div>

                    <div
                        class="overdue-days"
                        :title="`Délai convenu : ${row.standardDelayDays} jours`"
                    >
                        +{{ row.overdueDays }} j
                    </div>

                    <div>
                        {{ formatAmount(row.outstanding) }}
                    </div>

                    <div>
                        {{ formatAmount(row.penalty) }}
                    </div>
                </DataTableRow>
            </DataTable>

            <p
                v-if="
                    showOverdueTable &&
                    overdueSummary &&
                    overdueSummary.hidden > 0
                "
                class="overdue-more"
            >
                <Link :href="overdueSummary.historyUrl">
                    et {{ overdueSummary.hidden }} autre{{
                        overdueSummary.hidden > 1 ? 's' : ''
                    }}
                    dans le registre
                </Link>
            </p>
        </section>

        <!-- =====================================================
             PARCOURS DES PAIEMENTS
             ===================================================== -->
        <section class="dashboard-card journey-card">
            <div class="card-top-line"></div>

            <div class="card-header">
                <div class="card-title-group">
                    <div class="card-icon teal">
                        <span class="journey-symbol">⌁</span>
                    </div>

                    <div>
                        <span class="card-eyebrow">
                            ACTIVITÉ FINANCIÈRE
                        </span>

                        <h2>
                            {{ journeyHeading.title }}
                        </h2>

                        <p>
                            {{ journeyHeading.caption }}
                        </p>
                    </div>
                </div>

                <div class="card-badge">
                    <span class="badge-dot"></span>
                    12 mois
                </div>
            </div>

            <div class="chart-toolbar">
                <ChartToolbar
                    v-model="chartType"
                    :exporting="exporting"
                    @export="exportJourney"
                >
                    <template #filters>
                        <FilterSelect
                            v-if="chartType !== 'pie'"
                            v-model="journeyInsurer"
                            :options="insurerOptions"
                            size="compact"
                            aria-label="Filtrer le graphique par assureur"
                        />
                    </template>
                </ChartToolbar>
            </div>

            <div
                ref="journeyArea"
                class="journey-chart-area"
            >
                <OutstandingDonutChart
                    v-if="chartType === 'pie'"
                    :slices="donutSlices"
                    :height="200"
                />

                <Deferred
                    v-else
                    data="journey"
                >
                    <template #fallback>
                        <ChartSkeleton
                            class="mt-5"
                            :height="200"
                        />
                    </template>

                    <div
                        v-if="journey"
                        class="chart-wrapper"
                    >
                        <JourneyLineChart
                            v-if="chartType === 'line'"
                            :points="journey"
                        />

                        <InvoicedVsCollectedChart
                            v-else
                            :points="journey"
                        />
                    </div>
                </Deferred>
            </div>
        </section>

        <!-- =====================================================
             ÉVOLUTION DES PÉNALITÉS
             ===================================================== -->
        <PenaltyTrendCard
            :ledger="penaltyTrend"
            :subtitle="pharmacyName"
            filename="aphaspb-penalites-officine"
        />
<br>
        <!-- =====================================================
             ENCOURS PAR ANCIENNETÉ
             ===================================================== -->
        <section class="dashboard-card analysis-card">
            <div class="card-header">
                <div class="card-title-group">
                    <div class="card-icon gold">
                        <Clock :size="16" />
                    </div>

                    <div>
                        <span class="card-eyebrow">
                            ANALYSE DES ENCOURS
                        </span>

                        <h2>Encours par ancienneté</h2>

                        <p>
                            Ancienneté comptée depuis la fin du mois déclaré.
                        </p>
                    </div>
                </div>
            </div>

            <div class="ageing-list">
                <div
                    v-for="band in ageing"
                    :key="band.label"
                    class="ageing-row"
                >
                    <div class="ageing-label">
                        {{ band.label }}
                    </div>

                    <div class="ageing-progress">
                        <span
                            class="ageing-progress-fill"
                            :style="{
                                width: `${
                                    ageingTotal === 0
                                        ? 0
                                        : (band.amount / ageingTotal) * 100
                                }%`,
                            }"
                        ></span>
                    </div>

                    <div class="ageing-value">
                        {{ formatMillions(band.amount) }}
                    </div>
                </div>
            </div>
        </section>

        <!-- =====================================================
             SOURCE
             ===================================================== -->
        <p class="dashboard-source">
            <span class="source-mark">i</span>

            Les indicateurs sont calculés à partir des déclarations
            transmises par les officines participantes.
        </p>
    </div>

    <!-- =========================================================
         RECOUVREMENT PAR ASSUREUR
         ========================================================= -->
    <section class="recovery-section">
        <div class="section-intro recovery-intro">
            <div>
                <span class="section-eyebrow">
                    PERFORMANCE DES RÈGLEMENTS
                </span>

                <h2>Recouvrement par assureur</h2>

                <p>
                    Suivi des montants facturés, reçus et restant à recouvrer
                    sur les douze derniers mois.
                </p>
            </div>

       
        </div>

        <DataTable
            title=""
            :columns="RECOVERY_COLUMNS"
            :template="RECOVERY_TEMPLATE"
            footer="Sur les 12 derniers mois. Un assureur coché sans déclaration reste listé, sans taux."
        >
            <DataTableRow
                v-for="row in recovery"
                :key="row.insurerId"
                :template="RECOVERY_TEMPLATE"
            >
                <div class="recovery-insurer">
                    {{ row.insurerName }}
                </div>

                <div>
                    {{ formatMillions(row.invoiced) }}
                </div>

                <div>
                    {{ formatMillions(row.received) }}
                </div>

                <div
                    :class="
                        row.outstanding === 0
                            ? 'text-officine'
                            : 'text-terracotta-dark'
                    "
                    class="recovery-outstanding"
                >
                    {{
                        row.outstanding === 0
                            ? 'À JOUR'
                            : formatMillions(row.outstanding)
                    }}
                </div>

                <div
                    :class="
                        row.recoveryRate === null
                            ? 'text-ink/40'
                            : row.recoveryRate < 60
                              ? 'text-terracotta-dark'
                              : 'text-officine'
                    "
                    class="recovery-rate"
                >
                    {{
                        row.recoveryRate === null
                            ? '-'
                            : `${row.recoveryRate.toLocaleString('fr-FR')} %`
                    }}
                </div>
            </DataTableRow>
        </DataTable>
    </section>
</template>

<style scoped>
/* =========================================================
   BASE
   ========================================================= */
.intro-text h1 {
    margin: 0;

    color: var(--ink);

    font-size: 21px;
    font-weight: 800;

    letter-spacing: -0.025em;
}
.dashboard-page {
    --muted: color-mix(in srgb, var(--ink) 54%, transparent);
    --light: color-mix(in srgb, var(--ink) 38%, transparent);

    position: relative;
    min-height: 100vh;
    /* padding-bottom: 55px; */

    color: var(--ink);
}

.dashboard-header {
    position: relative;
    z-index: 5;
}

.kpis-band {
    margin-top: 18px;
}

/* =========================================================
   BANNIÈRES ASSUREURS
   ========================================================= */

.bands {
    margin: 20px 0 26px;
}

.insurer-banner {
    display: flex;
    align-items: center;
    gap: 11px;

    min-height: 48px;
    margin-bottom: 8px;
    padding: 10px 14px;

    border: 1px solid;
    border-left-width: 3px;
    border-radius: 11px;

    box-shadow: 0 2px 8px rgba(20, 29, 24, 0.025);

    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.insurer-banner:hover {
    transform: translateY(-1px);
    box-shadow: 0 7px 18px rgba(20, 29, 24, 0.055);
}

.insurer-banner-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 27px;
    height: 27px;
    flex: 0 0 27px;

    border-radius: 8px;
}

.insurer-banner-icon {
    flex-shrink: 0;
}

.insurer-banner-line {
    margin: 0;

    font-size: 13.5px;
    font-weight: 500;
    line-height: 1.55;
}

.insurer-banner-line strong {
    font-weight: 750;
}

.banner-separator {
    margin: 0 3px;
    opacity: 0.55;
}

.insurer-banner-days {
    margin-left: 3px;

    font-weight: 750;
    font-variant-numeric: tabular-nums;
}

.insurer-banner.late {
    border-color: var(--terracotta-dark);
    background: var(--terracotta);
    color: #fff;
}

.insurer-banner.late .insurer-banner-icon-wrap {
    background: rgba(255, 255, 255, 0.12);
}

.insurer-banner.late .insurer-banner-name,
.insurer-banner.late strong,
.insurer-banner.late .insurer-banner-days,
.insurer-banner.late .insurer-banner-icon {
    color: #fff;
}

.insurer-banner.owing {
    border-color: color-mix(
        in srgb,
        var(--gold) 35%,
        transparent
    );

    background: color-mix(
        in srgb,
        var(--gold-soft) 78%,
        #fff
    );

    color: var(--ink);
}

.insurer-banner.owing .insurer-banner-icon-wrap {
    background: color-mix(
        in srgb,
        var(--gold) 10%,
        transparent
    );
}

.insurer-banner.owing .insurer-banner-icon {
    color: var(--gold);
}

.insurer-banner.settled {
    border-color: color-mix(
        in srgb,
        var(--primary) 28%,
        transparent
    );

    background: color-mix(
        in srgb,
        var(--primary-soft) 72%,
        #fff
    );

    color: var(--ink);
}

.insurer-banner.settled .insurer-banner-icon-wrap {
    background: color-mix(
        in srgb,
        var(--primary) 9%,
        transparent
    );
}

.insurer-banner.settled .insurer-banner-icon {
    color: var(--primary);
}

.insurer-banner-name {
    color: inherit;
    font-weight: 750;

    text-decoration: underline;
    text-underline-offset: 3px;
}

.insurer-banner.late .insurer-banner-name {
    text-decoration-color: rgba(255, 255, 255, 0.65);
}

/* =========================================================
   ACTIONS DES BANNIÈRES
   ========================================================= */

.bands-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px 20px;

    margin-top: 10px;
    padding-left: 3px;
}

.bands-footer-action {
    padding: 0;

    border: 0;
    background: none;

    color: var(--muted);

    font-size: 11.5px;
    font-weight: 700;

    text-decoration: underline;
    text-decoration-color: color-mix(
        in srgb,
        currentColor 35%,
        transparent
    );
    text-underline-offset: 3px;

    cursor: pointer;

    transition: color 0.2s ease;
}

.bands-footer-action:hover {
    color: var(--ink);
}

/* =========================================================
   WHATSAPP
   ========================================================= */

.whatsapp-invite {
    display: flex;
    align-items: center;
    gap: 7px;

    margin: 0 0 18px;
    padding: 10px 13px;

    border: 1px solid var(--border);
    border-radius: 10px;

    background: rgba(255, 255, 255, 0.72);

    color: var(--muted);

    /* font-size: 13px; */
    line-height: 1.45;
}

.whatsapp-invite-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 18px;
    height: 18px;

    border-radius: 50%;

    background: color-mix(
        in srgb,
        var(--officine) 10%,
        transparent
    );

    color: var(--officine);

    font-size: 13px;
    font-weight: 900;
}

.whatsapp-invite-link {
    margin-left: 2px;

    color: var(--officine);
    font-weight: 750;

    text-decoration: underline;
    text-underline-offset: 3px;
}

/* =========================================================
   MOIS À RATTRAPER
   ========================================================= */

.catch-up {
    position: relative;

    margin: 16px 0 22px;
    padding: 17px 19px;

    border: 1px solid color-mix(
        in srgb,
        var(--gold-mid) 30%,
        transparent
    );

    border-radius: 14px;

    background: linear-gradient(
        135deg,
        color-mix(in srgb, var(--gold-soft) 80%, #fff),
        #fff
    );

    box-shadow: 0 5px 18px rgba(20, 29, 24, 0.035);
}

.catch-up::before {
    content: '';

    position: absolute;
    top: 13px;
    bottom: 13px;
    left: 0;

    width: 3px;

    border-radius: 4px;
    background: var(--gold);
}

.catch-up-header {
    display: flex;
    align-items: center;
    gap: 12px;
}

.catch-up-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 35px;
    height: 35px;
    flex: 0 0 35px;

    border-radius: 10px;

    background: var(--gold-soft);
    color: var(--gold);

    font-size: 17px;
    font-weight: 700;
}

.catch-up-label {
    display: block;

    color: var(--gold);

    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    font-weight: 750;
    letter-spacing: 0.15em;
}

.catch-up-text p {
    max-width: 760px;

    margin: 4px 0 0;

    color: var(--ink);

    font-size: 14px;
    line-height: 1.55;
}

.catch-up-months {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;

    margin-top: 14px;
    padding-left: 47px;
}

.catch-up-month {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    min-height: 33px;
    padding: 0 11px;

    border: 1px solid color-mix(
        in srgb,
        var(--gold-mid) 38%,
        transparent
    );

    border-radius: 9px;

    background: #fff;

    color: var(--ink);

    font-size: 10.5px;
    font-weight: 700;

    transition:
        background 0.2s ease,
        border-color 0.2s ease,
        transform 0.2s ease;
}

.catch-up-month:hover {
    border-color: var(--gold);
    background: var(--gold-soft);
    transform: translateY(-1px);
}

.month-arrow {
    color: var(--gold);
    font-size: 12px;
}

/* =========================================================
   KPI
   ========================================================= */

.kpis-page {
    margin-bottom: 24px;
}

@media (max-width: 1023.98px) {
    .kpis-page {
        display: none;
    }
}

/* =========================================================
   SECTION INTRO
   ========================================================= */

.section-intro {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;

    margin: 0 0 12px;
    padding: 0 2px;
}

.section-eyebrow,
.card-eyebrow {
    display: block;

    margin-bottom: 4px;

    color: var(--muted);

    font-family: 'JetBrains Mono', monospace;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 0.13em;
}

.section-intro h2 {
    margin: 0;

    color: var(--ink);

    font-size: 17px;
    font-weight: 750;
    letter-spacing: -0.02em;
}

.section-intro p {
    max-width: 650px;

    margin: 4px 0 0;

    color: black;

    font-size: 13px;
    line-height: 1.5;
}

/* =========================================================
   FACTURES EN RETARD
   ========================================================= */

.overdue-section {
    margin: 0 0 22px;
}

.overdue-insurer {
    color: var(--ink);
    font-weight: 700;

    text-decoration: underline;
    text-decoration-color: color-mix(
        in srgb,
        currentColor 30%,
        transparent
    );

    text-underline-offset: 3px;
}

.overdue-days {
    color: var(--terracotta);

    font-weight: 750;
    font-variant-numeric: tabular-nums;
}

.overdue-more {
    margin: 8px 0 0;

    color: var(--muted);

    font-size: 11px;
    text-align: right;
}

.overdue-more a {
    text-decoration: underline;
    text-underline-offset: 3px;
}

/* =========================================================
   CARTES PRINCIPALES
   ========================================================= */

.dashboard-card {
    position: relative;

    overflow: hidden;

    margin-bottom: 15px;

    border: 1px solid color-mix(
        in srgb,
        var(--ink) 7%,
        transparent
    );

    border-radius: 16px;

    background: #fff;

    box-shadow:
        0 4px 18px rgba(20, 29, 24, 0.035),
        0 1px 2px rgba(20, 29, 24, 0.025);

    animation: cardAppear 0.5s ease both;

    transition:
        box-shadow 0.25s ease,
        transform 0.25s ease;
}

.dashboard-card:hover {
    box-shadow:
        0 10px 28px rgba(20, 29, 24, 0.06),
        0 1px 2px rgba(20, 29, 24, 0.03);
}

.card-top-line {
    position: absolute;
    top: 0;
    left: 0;

    width: 100%;
    height: 2px;

    background: linear-gradient(
        90deg,
        var(--primary),
        color-mix(
            in srgb,
            var(--primary) 25%,
            transparent
        )
    );

    opacity: 0.9;
}

/* =========================================================
   CARD HEADER
   ========================================================= */

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;

    padding: 20px 21px 0;
}

.card-title-group {
    display: flex;
    align-items: center;
    gap: 12px;

    min-width: 0;
}

.card-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 38px;
    height: 38px;
    flex: 0 0 38px;

    border-radius: 11px;

    font-size: 15px;
    font-weight: 800;
}

.card-icon.teal {
    background: var(--primary-soft);
    color: var(--primary);
}

.card-icon.gold {
    background: var(--gold-soft);
    color: var(--gold);
}

.card-icon.terracotta {
    background: var(--terracotta-soft);
    color: var(--terracotta);
}

.journey-symbol {
    font-size: 20px;
    line-height: 1;
}

.card-header h2 {
    margin: 0;

    color: var(--ink);

    font-size: 16px;
    font-weight: 750;
    letter-spacing: -0.015em;
}

.card-header p {
    margin: 3px 0 0;

    color: var(--muted);

    /* font-size: 13px; */
    line-height: 1.45;
}

.card-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    padding: 6px 9px;

    border: 1px solid var(--border);
    border-radius: 999px;

    background: #fff;

    color: var(--muted);

    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.12em;

    white-space: nowrap;
}

/* =========================================================
   BADGE
   ========================================================= */

.badge-dot {
    width: 6px;
    height: 6px;
    flex: 0 0 6px;

    border-radius: 50%;

    background: var(--primary);

    box-shadow:
        0 0 0 4px color-mix(
            in srgb,
            var(--primary) 9%,
            transparent
        );

    animation: statusPulse 2.5s infinite;
}

/* =========================================================
   GRAPHIQUE
   ========================================================= */

.journey-card {
    margin-bottom: 15px;
}

.chart-toolbar {
    display: flex;
    align-items: center;
    justify-content: flex-end;

    min-height: 38px;

    margin-top: 10px;
    padding: 0 18px;
}

.journey-chart-area {
    min-height: 210px;
}

.chart-wrapper {
    margin-top: 8px;
    padding: 0 17px 18px;
}

/* =========================================================
   PENALITES
   ========================================================= */

.analysis-card {
    min-width: 0;
    padding-bottom: 18px;
}

/* =========================================================
   ANCIENNETÉ
   ========================================================= */

.ageing-list {
    display: flex;
    flex-direction: column;
    gap: 15px;

    margin-top: 20px;
    padding: 0 20px;
}

.ageing-row {
    display: grid;
    grid-template-columns: 64px minmax(0, 1fr) 82px;
    align-items: center;
    gap: 11px;
}

.ageing-label {
    color: var(--muted);

    font-family: 'JetBrains Mono', monospace;
    font-size: 9px;
    font-weight: 650;
    letter-spacing: 0.11em;
}

.ageing-progress {
    position: relative;

    height: 6px;

    overflow: hidden;

    border-radius: 999px;

    background: color-mix(
        in srgb,
        var(--ink) 6%,
        transparent
    );
}

.ageing-progress-fill {
    display: block;

    height: 100%;
    min-width: 3px;

    border-radius: inherit;

    background: linear-gradient(
        90deg,
        var(--gold-mid),
        var(--gold)
    );

    transform-origin: left center;

    animation: progressAppear 0.8s ease both;

    transition:
        width 0.7s cubic-bezier(0.2, 0.8, 0.2, 1),
        transform 0.2s ease;
}

.ageing-row:hover .ageing-progress-fill {
    transform: scaleY(1.45);
}

.ageing-value {
    color: var(--ink);

    text-align: right;

    font-size: 11.5px;
    font-weight: 750;
    font-variant-numeric: tabular-nums;
}

/* =========================================================
   SOURCE
   ========================================================= */

.dashboard-source {
    display: flex;
    align-items: center;
    gap: 7px;

    margin: 13px 2px 0;

    color: var(--muted);

    font-size: 10.5px;
    line-height: 1.5;
}

.source-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 17px;
    height: 17px;
    flex: 0 0 17px;

    border: 1px solid var(--border);
    border-radius: 50%;

    font-size: 9px;
    font-weight: 700;
}

/* =========================================================
   RECOUVREMENT
   ========================================================= */

.recovery-section {
    margin-top: 10px;
    margin-bottom: 50px;
}

.recovery-intro {
    margin-bottom: 13px;
}

.recovery-period {
    flex: 0 0 auto;

    padding: 6px 9px;

    border: 1px solid var(--border);
    border-radius: 999px;

    color: var(--muted);

    font-family: 'JetBrains Mono', monospace;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 0.08em;
}

.recovery-insurer {
    font-weight: 700;
}

.recovery-outstanding {
    font-weight: 750;
    font-variant-numeric: tabular-nums;
}

.recovery-rate {
    font-weight: 750;
    font-variant-numeric: tabular-nums;
}

/* =========================================================
   TABLEAUX - PETITES FINITIONS
   ========================================================= */

.dashboard-page :deep(.data-table),
.recovery-section :deep(.data-table) {
    border-radius: 15px;
}

.dashboard-page :deep(.data-table-header),
.recovery-section :deep(.data-table-header) {
    padding-left: 20px;
    padding-right: 20px;
}

.dashboard-page :deep(.data-table-row),
.recovery-section :deep(.data-table-row) {
    min-height: 52px;
}

/* =========================================================
   ANIMATIONS
   ========================================================= */

@keyframes cardAppear {
    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes statusPulse {
    0% {
        box-shadow:
            0 0 0 0 color-mix(
                in srgb,
                var(--primary) 24%,
                transparent
            );
    }

    70% {
        box-shadow:
            0 0 0 5px color-mix(
                in srgb,
                var(--primary) 0%,
                transparent
            );
    }

    100% {
        box-shadow:
            0 0 0 0 color-mix(
                in srgb,
                var(--primary) 0%,
                transparent
            );
    }
}

@keyframes progressAppear {
    from {
        opacity: 0;
        transform: scaleX(0);
    }

    to {
        opacity: 1;
        transform: scaleX(1);
    }
}

/* =========================================================
   TABLETTE
   ========================================================= */

@media (max-width: 800px) {
    .dashboard-page {
        padding-bottom: 50px;
    }

    .bands {
        margin-top: 16px;
    }

    .insurer-banner {
        padding: 10px 13px;
    }

    /* .insurer-banner-line {
        font-size: 12px;
    } */

    .catch-up {
        padding: 17px;
    }

    .card-header {
        padding: 18px 17px 0;
    }

    .chart-toolbar {
        padding: 0 15px;
    }

    .catch-up-months {
        padding-left: 0;
    }
}

/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 640px) {
    .dashboard-page {
        padding-bottom: 65px;
    }

    .bands {
        margin: 14px 0 20px;
    }

    .insurer-banner {
        align-items: flex-start;
        gap: 9px;

        padding: 10px 11px;
        border-radius: 10px;
    }

    .insurer-banner-icon-wrap {
        width: 25px;
        height: 25px;
        flex-basis: 25px;
    }

    .insurer-banner-icon {
        margin-top: 0;
    }

    /* .insurer-banner-line {
        font-size: 11.5px;
        line-height: 1.55;
    } */

    .banner-separator {
        margin: 0 2px;
    }

    .bands-footer {
        padding-left: 2px;
    }

    .whatsapp-invite {
        display: block;
        padding: 10px 12px;
    }

    .whatsapp-invite-icon {
        display: inline-flex;
        margin-right: 3px;
        vertical-align: middle;
    }

    .whatsapp-invite-link {
        display: inline-block;
        margin-top: 3px;
        margin-left: 0;
    }

    .catch-up {
        margin-top: 12px;
        padding: 16px 15px;
        border-radius: 13px;
    }

    .catch-up-header {
        align-items: flex-start;
    }

    .catch-up-icon {
        width: 32px;
        height: 32px;
        flex-basis: 32px;
    }

    .catch-up-months {
        gap: 6px;
        margin-top: 12px;
        padding-left: 0;
    }

    .catch-up-month {
        min-height: 32px;
        padding: 0 10px;
        font-size: 10.5px;
    }

    .section-intro {
        align-items: flex-start;
    }

    .section-intro h2 {
        font-size: 15px;
    }

    .section-intro p {
        font-size: 13px;
    }

    .recovery-period {
        display: none;
    }

    .card-header {
        align-items: flex-start;
        padding: 16px 15px 0;
    }

    .card-title-group {
        align-items: flex-start;
        gap: 10px;
    }

    .card-icon {
        width: 34px;
        height: 34px;
        flex-basis: 34px;
        border-radius: 9px;
    }

    .card-header h2 {
        font-size: 15px;
    }

    .card-header p {
        font-size: 13px;
    }

    .card-badge {
        display: none;
    }

    .chart-toolbar {
        justify-content: flex-start;

        margin-top: 12px;
        padding: 0 13px;
    }

    .chart-wrapper {
        margin-top: 8px;
        padding: 0 10px 12px;
    }

    .journey-chart-area {
        min-height: 190px;
    }

    .ageing-list {
        gap: 13px;

        margin-top: 17px;
        padding: 0 14px;
    }

    .ageing-row {
        grid-template-columns: 54px minmax(0, 1fr) 67px;
        gap: 7px;
    }

    .ageing-label {
        font-size: 8.5px;
    }

    .ageing-value {
        font-size: 11.5px;
    }

    .dashboard-source {
        font-size: 10px;
    }

    .recovery-section {
        margin-bottom: 35px;
    }
}

/* =========================================================
   TRÈS PETITS ÉCRANS
   ========================================================= */

@media (max-width: 400px) {
    .ageing-row {
        grid-template-columns: 48px minmax(0, 1fr) 59px;
    }

    .ageing-list {
        padding: 0 12px;
    }

    /* .insurer-banner-line {
        font-size: 11px;
    } */

    .catch-up-text p {
        font-size: 11px;
    }
}

/* =========================================================
   ACCESSIBILITÉ
   ========================================================= */

@media (prefers-reduced-motion: reduce) {
    .dashboard-page *,
    .dashboard-page *::before,
    .dashboard-page *::after,
    .recovery-section *,
    .recovery-section *::before,
    .recovery-section *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

</style>