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
import { withheldExplanation } from '@/lib/withheld';
import type { WithheldReason } from '@/lib/withheld';
import type { KpiTone } from '@/types/aphaspb';

type Indicator = {
    insurerId: number;
    insurerName: string;
    sufficient: boolean;
    /** Null under the threshold: the exact count never leaves the server. */
    declaringPharmacies: number | null;
    required: number | null;
    withheldReason: WithheldReason | null;
    averageDelayDays: number | null;
    standardDelayDays: number | null;
    withinThresholdShare: number | null;
    recoveredWithinDelayShare: number | null;
    rejectionRate: number | null;
    unpaidRate: number | null;
};

/**
 * Withheld as a whole when it rests on fewer officines than the threshold —
 * a city of one declarant would otherwise print that officine's figures.
 */
type Summary = {
    withheld: boolean;
    required: number;
    withheldReason: WithheldReason | null;
    declaringPharmacies: number | null;
    declarations: number | null;
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
    'Assureur',
    'Officines (n)',
    'Délai moyen',
    'Dans les délais',
    'Argent dans les délais',
    'Rejet',
    'Non payé',
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

/** What a withheld KPI says under its « retenu ». */
const withheldHint = computed(() =>
    withheldExplanation(props.summary.withheldReason, props.summary.required),
);

const footer = computed(() =>
    props.summary.withheld
        ? `${props.indicators.length} assureurs · synthèse retenue : ${withheldHint.value}`
        : `${props.indicators.length} assureurs · ${(props.summary.declarations ?? 0).toLocaleString('fr-FR')} déclarations agrégées · évolution mensuelle en préparation`,
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

        <section class="network-intro">

            <div class="intro-decoration"></div>

            <div class="intro-main">

                <!-- TITRE -->

                <div class="intro-content">

                    <div class="intro-icon">
                        <span>◉</span>
                    </div>

                    <div class="intro-text">

                        <h1>
                            Performance du réseau
                        </h1>

                    </div>

                </div>


                <!-- FILTRES -->

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

                 <div class="header-actions">

                    <a
                        href="/admin/csv-exports"
                        class="action-btn action-export"
                    >
                        <span class="action-icon">↓</span>
                        <span>Exporter</span>
                    </a>

                    <Link
                        href="/admin/insurers"
                        class="action-btn action-edit"
                    >
                        <span class="action-icon">✎</span>
                        <span>Modifier</span>
                    </Link>

                </div>

                </div>

            </div>


           

        </section>


        <!-- =====================================================
             KPI
        ====================================================== -->

        <KpiRow
            :columns="3"
            class="network-kpis"
        >

            <!-- =================================================
                 KPI 1 — OFFICINES
            ================================================== -->

            <div class="kpi-wrapper">

                <div class="kpi-accent"></div>

                <KpiCard
                    label="OFFICINES DÉCLARANTES"
                    :value="
                        summary.withheld
                            ? 'retenu'
                            : (summary.declaringPharmacies ?? 0).toLocaleString(
                                  'fr-FR',
                              )
                    "
                    tone="neutral"
                    :hint="
                        summary.withheld
                            ? withheldHint
                            : 'ayant déclaré au moins une fois sur la période'
                    "
                />

                <div class="kpi-decoration">
                    <span>⌂</span>
                </div>

            </div>


            <!-- =================================================
                 KPI 2 — DÉLAI MOYEN
            ================================================== -->

            <div class="kpi-wrapper">

                <div class="kpi-accent"></div>

                <KpiCard
                    label="DÉLAI MOYEN RÉSEAU"
                    :value="
                        summary.withheld
                            ? 'retenu'
                            : (summary.averageDelayDays?.toLocaleString(
                                  'fr-FR',
                              ) ?? '—')
                    "
                    :unit="summary.withheld ? undefined : 'jours'"
                    :tone="
                        delayTone(
                            summary.averageDelayDays,
                            networkStandard,
                        )
                    "
                    :hint="
                        summary.withheld
                            ? withheldHint
                            : 'statuts payés et partiels confondus'
                    "
                />

                <div class="kpi-decoration">
                    <span>◷</span>
                </div>

            </div>


            <!-- =================================================
                 KPI 3 — PAYÉ DANS LES DÉLAIS
            ================================================== -->

            <div class="kpi-wrapper">

                <div class="kpi-accent gold"></div>

                <KpiCard
                    label="PAYÉ DANS LES DÉLAIS"
                    :value="
                        summary.withheld
                            ? 'retenu'
                            : (summary.withinThresholdShare?.toLocaleString(
                                  'fr-FR',
                              ) ?? '—')
                    "
                    :unit="summary.withheld ? undefined : '%'"
                    :tone="shareTone(summary.withinThresholdShare)"
                >
                    <template #hint>

                        <span class="kpi-hint">

                            <template v-if="summary.withheld">
                                {{ withheldHint }} ·
                            </template>

                            <template v-else>
                                selon le délai retenu pour chaque assureur ·
                            </template>

                            <Link
                                href="/admin/insurers"
                                class="threshold-edit"
                            >
                                <span>Modifier</span>
                                <span class="threshold-edit-icon">↗</span>
                            </Link>

                        </span>

                    </template>
                </KpiCard>

                <div class="kpi-decoration gold">
                    <span>✓</span>
                </div>

            </div>

        </KpiRow>

        


        <!-- =====================================================
             TABLEAU
        ====================================================== -->

        <section class="table-section">

            <!-- EN-TÊTE DU TABLEAU -->

            <div class="table-section-header">

                <div class="table-title-block">

                    <div class="table-icon">
                        <span>↗</span>
                    </div>

                    <div>

                        <!-- <span class="table-eyebrow">
                            SUIVI DU RÉSEAU
                        </span> -->

                        <h2>
                            Indicateurs par assureur
                        </h2>

                        <!-- <p>
                            Comparez les délais et les indicateurs de
                            règlement sur la période sélectionnée.
                        </p> -->

                    </div>

                </div>


                <!-- <div class="table-status">

                    <span class="status-dot"></span>

                    <span>
                        Données consolidées
                    </span>

                </div> -->

            </div>


            <div class="table-top-decoration"></div>


            <!-- TABLE -->

            <DataTable
                title=""
                :columns="COLUMNS"
                :template="TEMPLATE"
                :footer="footer"
                class="network-table"
            >

                <template
                    v-for="indicator in indicators"
                    :key="indicator.insurerId"
                >

                    <!-- DONNÉES INSUFFISANTES -->

                    <InsufficientDataRow
                        v-if="!indicator.sufficient"
                        :template="TEMPLATE"
                        :label="indicator.insurerName"
                        :span="6"
                        :explanation="`${withheldExplanation(
                            indicator.withheldReason,
                            indicator.required ?? summary.required,
                        )} — pour garantir l’anonymat`"
                    />


                    <!-- DONNÉES DISPONIBLES -->

                    <DataTableRow
                        v-else
                        :template="TEMPLATE"
                        :tone="
                            isAlarming(indicator)
                                ? 'alert'
                                : 'default'
                        "
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

                                <small>
                                    Assureur actif
                                </small>

                            </div>

                        </div>


                        <!-- OFFICINES -->

                        <div class="pharmacy-count">

                            <span class="count-number">
                                {{ indicator.declaringPharmacies }}
                            </span>

                            <span class="count-label">
                                officines
                            </span>

                        </div>


                        <!-- DÉLAI MOYEN -->

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

                            <span class="delay-unit">
                                jours
                            </span>

                            <span class="delay-standard">
                                standard
                                {{ days(indicator.standardDelayDays) }}
                            </span>

                        </div>


                        <!-- PAYÉ DANS LES DÉLAIS -->

                        <div class="threshold-cell">

                            <ProgressMiniBar
                                :share="
                                    indicator.withinThresholdShare ?? 0
                                "
                                :tone="
                                    shareTone(
                                        indicator.withinThresholdShare,
                                    )
                                "
                                :label="
                                    percent(
                                        indicator.withinThresholdShare,
                                    )
                                "
                            />

                        </div>


                        <!-- RÉCUPÉRÉ DANS LE DÉLAI -->

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
                                    percent(
                                        indicator.recoveredWithinDelayShare,
                                    )
                                "
                            />

                        </div>


                        <!-- REJETS -->

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


                        <!-- IMPAYÉS -->

                        <div class="rate-cell unpaid-cell">

                            <span>
                                {{ percent(indicator.unpaidRate) }}
                            </span>

                        </div>

                    </DataTableRow>

                </template>

            </DataTable>

        </section>


        <!-- =====================================================
             NOTE DE CONFIDENTIALITÉ
        ====================================================== -->

        <div class="network-footnote">

            <div class="footnote-icon">
                i
            </div>

            <p>
                Les indicateurs sont calculés à partir des déclarations
                transmises par les officines participantes. Les données
                individuelles ne sont jamais exposées.
            </p>

        </div>

    </div>
</template>


<style scoped>

/* =========================================================
   APSPB — STATISTIQUES RÉSEAU
   Design : Light / Elegant / Institutional
   Font : Manrope
========================================================= */

.network-page {

    --apha-primary: #00664c;
    --apha-primary-dark: #005741;
    --apha-primary-soft: #eef7f3;

    --apha-gold: #b08a45;
    --apha-gold-soft: #fbf7ef;

    --apha-ink: #243a32;
    --apha-muted: #788780;
    --apha-light: #9da8a3;

    --apha-border: #e5ebe8;
    --apha-surface: #ffffff;
    --apha-background: #f8faf9;

    position: relative;

    min-height: 100vh;

    padding-bottom: 55px;

    color: var(--apha-ink);

    font-family: 'Manrope', sans-serif;
}


/* =========================================================
   HEADER
========================================================= */

.network-header {
    position: relative;

    z-index: 5;
}

.header-actions {

    display: flex;

    align-items: center;

    justify-content: flex-end;

    gap: 7px;

    white-space: nowrap;
}


/* =========================================================
   BOUTONS HEADER
========================================================= */

.action-btn {

    height: 37px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    padding: 0 12px;

    border: 1px solid var(--apha-border);

    border-radius: 10px;

    background: #ffffff;

    color: var(--apha-ink);

    font-family: 'Manrope', sans-serif;

    font-size: 15px;

    font-weight: 700;

    text-decoration: none;

    white-space: nowrap;

    cursor: pointer;

    transition:
        background-color 0.2s ease,
        border-color 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.action-btn:hover {

    transform: translateY(-1px);
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
}


/* EXPORTER */

.action-export {

    background: var(--apha-primary);

    border-color: var(--apha-primary);

    color: #ffffff;
}

.action-export .action-icon {

    background: rgb(255 255 255 / 0.14);

    color: #ffffff;
}

.action-export:hover {

    background: var(--apha-primary-dark);

    border-color: var(--apha-primary-dark);

    /* box-shadow:
        0 7px 18px rgb(0 102 76 / 0.15); */
}


/* MODIFIER */

.action-edit {

    background:
        linear-gradient(
            135deg,
            #ffffff,
            #f8fcfa
        );

    border-color:
        rgb(0 102 76 / 0.16);

    color: var(--apha-primary-dark);
}

.action-edit .action-icon {

    background: var(--apha-primary-soft);

    color: var(--apha-primary);
}

.action-edit:hover {

    background: var(--apha-primary-soft);

    border-color:
        rgb(0 102 76 / 0.28);
}


/* =========================================================
   PERFORMANCE DU RÉSEAU
========================================================= */
.network-intro {
    position: relative;

    display: flex;
    align-items: center;

    width: 100%;

    margin: 10px 0 26px;

    padding: 22px 24px;

    overflow: hidden;

    border: 1px solid var(--apha-border);
    border-radius: 18px;

    background:
        linear-gradient(
            110deg,
            #ffffff 0%,
            #ffffff 62%,
            #f7fbf9 100%
        );
/* 
    box-shadow:
        0 7px 28px rgb(35 70 68 / 0.045); */

    animation: introAppear 0.55s ease both;
}

/* Ligne supérieure */

.network-intro::before {

    content: '';

    position: absolute;

    left: 24px;

    right: 24px;

    top: 0;

    height: 2px;

    border-radius:
        0 0 5px 5px;

    background:
        linear-gradient(
            90deg,
            var(--apha-primary),
            #4a9c83,
            transparent
        );

    opacity: 0.85;
}


/* Décoration circulaire */

.intro-decoration {

    position: absolute;

    right: -65px;

    top: -95px;

    width: 210px;

    height: 210px;

    border: 1px solid #e1eee9;

    border-radius: 50%;

    pointer-events: none;
}

.intro-decoration::after {

    content: '';

    position: absolute;

    right: 28px;

    bottom: 28px;

    width: 70px;

    height: 70px;

    border-radius: 50%;

    background:
        var(--apha-primary-soft);

    opacity: 0.65;
}


/* =========================================================
   BLOC PRINCIPAL
========================================================= */

.intro-main {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;

    width: 100%;
    min-width: 0;

    gap: 34px;
}


/* =========================================================
   TITRE
========================================================= */

.intro-content {

    display: flex;

    align-items: center;

    gap: 15px;

    min-width: 220px;
}

.intro-icon {

    width: 48px;

    height: 48px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 14px;

    background:
        linear-gradient(
            135deg,
            var(--apha-primary),
            var(--apha-primary-dark)
        );

    color: #ffffff;
/* 
    box-shadow:
        0 8px 18px rgb(0 102 76 / 0.16); */
}

.intro-icon span {

    font-size: 18px;

    line-height: 1;
}

.intro-text {

    min-width: 0;
}

.intro-text h1 {

    margin: 0;

    color: #1f3930;

    font-size: 21px;

    font-weight: 800;

    letter-spacing: -0.035em;

    line-height: 1.15;
}

.intro-period {

    margin: 5px 0 0;

    color: var(--apha-muted);

    font-size: 10px;

    font-weight: 600;
}


/* =========================================================
   FILTRES
========================================================= */

.intro-filters {
    display: flex;
    align-items: flex-end;

    flex: 1;

    gap: 12px;

    padding-left: 28px;

    /* border-left: 1px solid #e8eeeb; */
}

.filter-item {
    display: flex;
    flex-direction: column;

    gap: 5px;

    flex: 1;
    min-width: 0;
}

.filter-label {

    padding-left: 2px;

    color: var(--apha-light);

    font-size: 8px;

    font-weight: 800;

    letter-spacing: 0.1em;

    text-transform: uppercase;
}




/* =========================================================
   KPI
========================================================= */

.network-kpis {

    margin-bottom: 27px;
}

.kpi-wrapper {

    position: relative;

    overflow: hidden;

    border: 1px solid var(--apha-border);

    border-radius: 16px;

    background: #ffffff;

    /* box-shadow:
        0 3px 17px rgb(22 51 42 / 0.045); */

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        border-color 0.25s ease;

    animation:
        cardAppear 0.55s ease both;
}

.kpi-wrapper:nth-child(2) {

    animation-delay: 0.08s;
}

.kpi-wrapper:nth-child(3) {

    animation-delay: 0.16s;
}

.kpi-wrapper:hover {

    transform: translateY(-3px);

    border-color: #dce8e2;

    box-shadow:
        0 12px 28px rgb(22 51 42 / 0.075);
}

.kpi-accent {

    position: absolute;

    left: 0;

    top: 18px;

    bottom: 18px;

    width: 3px;

    border-radius:
        0 5px 5px 0;

    background:
        var(--apha-primary);

    z-index: 5;
}

.kpi-accent.gold {

    background:
        var(--apha-gold);
}

.kpi-decoration {

    position: absolute;

    right: 17px;

    top: 17px;

    width: 39px;

    height: 39px;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #e0eee8;

    border-radius: 11px;

    background:
        var(--apha-primary-soft);

    color:
        var(--apha-primary);

    font-size: 15px;

    font-weight: 700;

    pointer-events: none;

    transition:
        transform 0.25s ease;
}

.kpi-decoration.gold {

    border-color: #eee4cf;

    background:
        var(--apha-gold-soft);

    color:
        var(--apha-gold);
}

.kpi-wrapper:hover .kpi-decoration {

    transform:
        translateY(-2px)
        scale(1.05);
}


/* =========================================================
   TABLEAU
========================================================= */

.table-section {

    position: relative;

    overflow: hidden;

    padding: 4px;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: #ffffff;

    /* box-shadow:
        0 7px 27px rgb(35 70 68 / 0.045); */

    animation:
        tableAppear 0.65s ease both;
}


/* =========================================================
   EN-TÊTE TABLEAU
========================================================= */

.table-section-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    padding: 19px 19px 17px;

    /* border-bottom:
        1px solid #edf1ef; */
}

.table-title-block {

    display: flex;

    align-items: center;

    gap: 12px;
}

.table-icon {

    width: 38px;

    height: 38px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #dcebe4;

    border-radius: 11px;

    background:
        var(--apha-primary-soft);

    color:
        var(--apha-primary);

    font-size: 15px;

    font-weight: 800;
}

.table-eyebrow {

    display: block;

    margin-bottom: 3px;

    color:
        var(--apha-primary);

    font-size: 8px;

    font-weight: 800;

    letter-spacing: 0.12em;
}

.table-section-header h2 {

    margin: 0;

    color: #233a32;

    font-size: 14px;

    font-weight: 800;

    letter-spacing: -0.015em;
}

.table-section-header p {

    margin: 4px 0 0;

    color:
        var(--apha-muted);

    font-size: 10px;

    font-weight: 500;
}

.table-status {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 7px 10px;

    border: 1px solid #e4eee9;

    border-radius: 9px;

    background: #f8fbf9;

    color: #74827b;

    font-size: 9px;

    font-weight: 650;

    white-space: nowrap;
}

.status-dot {

    width: 6px;

    height: 6px;

    border-radius: 50%;

    background: #4a9c83;
/* 
    box-shadow:
        0 0 0 3px rgb(74 156 131 / 0.10); */
}

.table-top-decoration {

    position: absolute;

    left: 22px;

    right: 22px;

    top: 0;

    height: 2px;

    border-radius:
        0 0 4px 4px;

    background:
        linear-gradient(
            90deg,
            var(--apha-primary),
            #4a9c83,
            var(--apha-gold)
        );

    opacity: 0.85;
}

.network-table {

    border-radius: 14px;
}


/* =========================================================
   ASSUREUR
========================================================= */

.insurer-cell {

    display: flex;

    align-items: center;

    gap: 10px;

    min-width: 0;
}

.insurer-avatar {

    width: 35px;

    height: 35px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #dcebe4;

    border-radius: 10px;

    background:
        linear-gradient(
            135deg,
            #eaf6f2,
            #f5faf8
        );

    color:
        var(--apha-primary-dark);

    font-size: 11px;

    font-weight: 800;

    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.insurer-row:hover .insurer-avatar {

    transform:
        scale(1.05);

    box-shadow:
        0 5px 12px rgb(0 102 76 / 0.10);
}

.insurer-name {

    display: flex;

    flex-direction: column;

    min-width: 0;

    gap: 2px;
}

.insurer-name span {

    overflow: hidden;

    color:
        var(--apha-ink);

    font-size: 12.5px;

    font-weight: 700;

    white-space: nowrap;

    text-overflow: ellipsis;
}

.insurer-name small {

    color:
        var(--apha-light);

    font-size: 9px;

    font-weight: 500;
}


/* =========================================================
   OFFICINES
========================================================= */

.pharmacy-count {

    display: flex;

    align-items: baseline;

    gap: 4px;
}

.count-number {

    color:
        var(--apha-ink);

    font-size: 13.5px;

    font-weight: 750;
}

.count-label {

    color:
        var(--apha-light);

    font-size: 9.5px;

    font-weight: 500;
}


/* =========================================================
   DÉLAI
========================================================= */

.delay-cell {

    display: flex;

    align-items: baseline;

    flex-wrap: wrap;

    gap: 4px;
}

.delay-value {

    color:
        var(--apha-ink);

    font-size: 13.5px;

    font-weight: 750;
}

.delay-unit {

    color:
        var(--apha-light);

    font-size: 9.5px;

    font-weight: 500;
}

.delay-alert .delay-value {

    color:
        #b45f50;
}

.delay-standard {

    display: block;

    width: 100%;

    margin-top: 2px;

    color:
        var(--apha-light);

    font-size: 9.5px;

    font-weight: 500;
}


/* =========================================================
   TAUX
========================================================= */

.rate-cell {

    color:
        var(--apha-ink);

    font-size: 12.5px;

    font-weight: 700;
}

.rate-alert {

    color:
        #b45f50;
}

.unpaid-cell {

    color:
        var(--apha-primary-dark);
}


/* =========================================================
   LIEN MODIFIER SEUIL
========================================================= */

.threshold-edit {

    display: inline-flex;

    align-items: center;

    gap: 4px;

    margin-left: 3px;

    color:
        var(--apha-primary);

    font-size: 10px;

    font-weight: 750;

    text-decoration: none;

    transition:
        color 0.2s ease,
        gap 0.2s ease;
}

.threshold-edit-icon {

    font-size: 10px;

    opacity: 0.65;

    transition:
        transform 0.2s ease;
}

.threshold-edit:hover {

    color:
        var(--apha-primary-dark);

    gap: 6px;
}

.threshold-edit:hover .threshold-edit-icon {

    transform:
        translate(1px, -1px);
}


/* =========================================================
   NOTE
========================================================= */

.network-footnote {

    display: flex;

    align-items: flex-start;

    gap: 9px;

    margin-top: 14px;

    padding: 12px 15px;

    border: 1px solid
        rgb(0 102 76 / 0.07);

    border-radius: 12px;

    background:
        rgb(0 102 76 / 0.025);
}

.footnote-icon {

    width: 18px;

    height: 18px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background:
        var(--apha-primary);

    color: #ffffff;

    font-size: 10px;

    font-weight: 800;
}

.network-footnote p {

    margin: 0;

    color:
        var(--apha-muted);

    font-size: 10px;

    font-weight: 500;

    line-height: 1.55;
}


/* =========================================================
   ANIMATIONS
========================================================= */

@keyframes introAppear {

    from {

        opacity: 0;

        transform:
            translateY(8px);
    }

    to {

        opacity: 1;

        transform:
            translateY(0);
    }
}

@keyframes cardAppear {

    from {

        opacity: 0;

        transform:
            translateY(10px);
    }

    to {

        opacity: 1;

        transform:
            translateY(0);
    }
}

@keyframes tableAppear {

    from {

        opacity: 0;

        transform:
            translateY(12px);
    }

    to {

        opacity: 1;

        transform:
            translateY(0);
    }
}


/* =========================================================
   TABLET
========================================================= */

@media (max-width: 1050px) {

    .intro-main {

        gap: 22px;
    }

    .intro-content {

        min-width: auto;
    }

    .intro-filters {

        padding-left: 18px;
    }
}


@media (max-width: 900px) {

    .network-intro {

        align-items: flex-start;
    }

    .intro-main {

        flex-direction: column;

        align-items: flex-start;

        gap: 18px;

        width: 100%;
    }

    .intro-filters {

        width: 100%;

        padding-left: 0;

        padding-top: 14px;

        border-left: 0;

        border-top:
            1px solid #e8eeeb;
    }

    .intro-side {

        position: absolute;

        right: 22px;

        top: 22px;
    }

    .table-section-header {

        align-items: flex-start;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 760px) {

    .header-actions {

        width: 100%;

        justify-content: stretch;

        gap: 6px;
    }

    .action-btn {

        flex: 1;

        min-width: 0;
    }

    .network-intro {

        flex-direction: column;

        align-items: stretch;

        margin-top: 6px;
    }

    .intro-side {

        position: static;

        align-self: flex-start;
    }
}


@media (max-width: 640px) {

    .network-page {

        padding-bottom: 70px;
    }

    .network-intro {

        gap: 17px;

        padding: 19px 17px;

        border-radius: 15px;
    }

    .network-intro::before {

        left: 17px;

        right: 17px;
    }

    .intro-main {

        gap: 16px;
    }

    .intro-content {

        align-items: flex-start;

        gap: 12px;
    }

    .intro-icon {

        width: 42px;

        height: 42px;

        border-radius: 12px;
    }

    .intro-text h1 {

        font-size: 18px;
    }

    .intro-filters {

        gap: 7px;
    }

    .filter-item {

        flex: 1;

        min-width: 0;
    }

    .privacy-badge {

        font-size: 9px;
    }

    .table-section-header {

        flex-direction: column;

        align-items: stretch;

        padding: 17px 14px;
    }

    .table-status {

        align-self: flex-start;
    }

    .action-btn {

        height: 35px;

        padding: 0 8px;

        font-size: 9.5px;
    }

    .action-icon {

        width: 19px;

        height: 19px;
    }

    .table-top-decoration {

        left: 15px;

        right: 15px;
    }
}


/* =========================================================
   TRÈS PETIT MOBILE
========================================================= */

@media (max-width: 400px) {

    .header-actions {

        gap: 5px;
    }

    .action-btn {

        padding: 0 6px;

        font-size: 9px;
    }

    .action-icon {

        width: 18px;

        height: 18px;
    }

    .intro-filters {

        flex-direction: column;

        align-items: stretch;
    }

    .filter-item {

        width: 100%;
    }
}


/* =========================================================
   ACCESSIBILITÉ
========================================================= */

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
