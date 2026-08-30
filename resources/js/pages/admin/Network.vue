<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterChip from '@/components/aphaspb/FilterChip.vue';
import InsufficientDataRow from '@/components/aphaspb/InsufficientDataRow.vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import PrimaryAction from '@/components/aphaspb/PrimaryAction.vue';
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
    withinThresholdShare: number | null;
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
    threshold: number;
    period: string;
    city: string | null;
    cities: string[];
}>();

const TEMPLATE = '1.7fr 1fr .9fr 1fr .8fr .9fr';

const COLUMNS = [
    'ASSUREUR',
    'OFFICINES (n)',
    'DÉLAI MOYEN',
    `≤ ${props.threshold} J`,
    'REJET',
    'NON PAYÉ',
];

/** A delay past twice the threshold is what the canvas paints as alarming. */
const isAlarming = (indicator: Indicator): boolean =>
    (indicator.averageDelayDays ?? 0) > props.threshold * 2;

const shareTone = (share: number | null): KpiTone => {
    if (share === null) {
        return 'neutral';
    }

    if (share >= 50) {
        return 'good';
    }

    return share >= 20 ? 'warn' : 'bad';
};

const delayTone = (days: number | null): KpiTone => {
    if (days === null) {
        return 'neutral';
    }

    if (days <= props.threshold) {
        return 'good';
    }

    return days <= props.threshold * 2 ? 'warn' : 'bad';
};

const percent = (value: number | null): string =>
    value === null ? '—' : `${value.toLocaleString('fr-FR')} %`;

const days = (value: number | null): string =>
    value === null ? '—' : `${value.toLocaleString('fr-FR')} j`;

const footer = computed(
    () =>
        `${props.indicators.length} assureurs · ${props.summary.declarations.toLocaleString('fr-FR')} déclarations agrégées · évolution mensuelle en préparation`,
);

/** Filters reload only the props they affect, never the whole page. */
const reloadIndicators = () =>
    router.reload({ only: ['indicators', 'summary'] });
</script>



<template>

    <Head title="Statistiques réseau" />

    <div class="network-page">

        <ConsoleHeader
            eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
            :title="period"
            class="network-header"
        >

            <template #filters>

                <div class="header-filters">

                    <div class="filter-control">

                        <FilterChip
                            :label="period"
                            @click="reloadIndicators"
                        />

                        <span class="filter-chevron">
                            ▾
                        </span>

                    </div>


                    <div class="filter-control">

                        <FilterChip
                            :label="city ?? 'Toutes les villes'"
                            @click="reloadIndicators"
                        />

                        <span class="filter-chevron">
                            ▾
                        </span>

                    </div>

                </div>

            </template>


            <template #action>

                <div class="header-actions">

                    <!-- EXPORT -->

                    <a
                        href="/admin/csv-exports"
                        class="action-btn action-export"
                    >

                        <span class="action-icon">
                            ↓
                        </span>

                        <span>
                            Exporter
                        </span>

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


                    <Link
                        href="/admin/insurers"
                        class="action-btn action-edit"
                    >

                        <span class="action-icon">
                            ✎
                        </span>

                        <span>
                            Modifier
                        </span>

                    </Link>

                </div>

            </template>

        </ConsoleHeader>


        <section class="network-intro">

            <div class="intro-content">

                <div class="intro-icon">
                    <span>◉</span>
                </div>

                <div>

                    <span class="intro-label">
                        OBSERVATOIRE DES PAIEMENTS
                    </span>

                    <h1>
                        Performance du réseau
                    </h1>

                    <p>
                        Suivez les délais de règlement et les indicateurs
                        de paiement des assureurs du réseau.
                    </p>

                </div>

            </div>


            <div class="privacy-badge">

                <span class="privacy-dot"></span>

                <span>
                    Données anonymisées
                </span>

            </div>

        </section>


        <KpiRow
            :columns="3"
            class="network-kpis"
        >

            <!-- KPI 1 -->

            <div class="kpi-wrapper">

                <div class="kpi-accent"></div>

                <KpiCard
                    label="OFFICINES DÉCLARANTES"
                    :value="
                        summary.declaringPharmacies
                            .toLocaleString('fr-FR')
                    "
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
                        summary.averageDelayDays?.toLocaleString('fr-FR')
                        ?? '—'
                    "
                    unit="jours"
                    :tone="delayTone(summary.averageDelayDays)"
                    hint="statuts payés et partiels confondus"
                />

                <div class="kpi-decoration">
                    <span>◷</span>
                </div>

            </div>




            <div class="kpi-wrapper">

                <div class="kpi-accent gold"></div>

                <KpiCard
                    :label="`PAYÉ ≤ ${threshold} JOURS`"
                    :value="
                        summary.withinThresholdShare
                            ?.toLocaleString('fr-FR')
                        ?? '—'
                    "
                    unit="%"
                    :tone="shareTone(summary.withinThresholdShare)"
                >

                    <template #hint>

                        <span class="kpi-hint">

                            seuil de {{ threshold }} j ·

                            <Link
                                href="/admin/insurers"
                                class="threshold-edit"
                            >

                                <span>
                                    Modifier
                                </span>

                                <span class="threshold-edit-icon">
                                    ↗
                                </span>

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



                <template #filters>

                    <div class="table-filters">

    

                        <div class="table-filter-control">

                            <FilterChip
                                label="Trier : délai moyen"
                                size="compact"
                            />

                            <span class="table-filter-chevron">
                                ▾
                            </span>

                        </div>



                        <div class="table-filter-control">

                            <FilterChip
                                label="Actifs uniquement"
                                size="compact"
                            />

                            <span class="table-filter-chevron">
                                ▾
                            </span>

                        </div>

                    </div>

                </template>


                <template
                    v-for="indicator in indicators"
                    :key="indicator.insurerId"
                >


                    <InsufficientDataRow
                        v-if="!indicator.sufficient"
                        :template="TEMPLATE"
                        :label="indicator.insurerName"
                        :span="5"
                        :explanation="
                            `${indicator.declaringPharmacies}
                            officine${indicator.declaringPharmacies > 1 ? 's' : ''}
                            déclarante${indicator.declaringPharmacies > 1 ? 's' : ''}
                            — affichage à partir de ${indicator.required},
                            pour garantir l’anonymat`
                        "
                    />


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


                        <div class="pharmacy-count">

                            <span class="count-number">
                                {{ indicator.declaringPharmacies }}
                            </span>

                            <span class="count-label">
                                officines
                            </span>

                        </div>



                        <div
                            class="delay-cell"
                            :class="{
                                'delay-alert':
                                    delayTone(
                                        indicator.averageDelayDays
                                    ) === 'bad'
                            }"
                        >

                            <span class="delay-value">

                                {{
                                    days(
                                        indicator.averageDelayDays
                                    )
                                }}

                            </span>

                            <span class="delay-unit">
                                jours
                            </span>

                        </div>


                        <div class="threshold-cell">

                            <ProgressMiniBar
                                :share="
                                    indicator.withinThresholdShare
                                    ?? 0
                                "
                                :tone="
                                    shareTone(
                                        indicator.withinThresholdShare
                                    )
                                "
                                :label="
                                    percent(
                                        indicator.withinThresholdShare
                                    )
                                "
                            />

                        </div>


                        <div
                            class="rate-cell"
                            :class="{
                                'rate-alert':
                                    (indicator.rejectionRate ?? 0) > 15
                            }"
                        >

                            <span>
                                {{
                                    percent(
                                        indicator.rejectionRate
                                    )
                                }}
                            </span>

                        </div>


                        <div class="rate-cell unpaid-cell">

                            <span>
                                {{
                                    percent(
                                        indicator.unpaidRate
                                    )
                                }}
                            </span>

                        </div>

                    </DataTableRow>

                </template>

            </DataTable>

        </section>


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


<style>


.network-page {

    --apha-primary: #008F83;
    --apha-primary-dark: #006F68;
    --apha-primary-soft: #E8F6F3;

    --apha-gold: #D7A33D;
    --apha-gold-soft: #FFF8E9;

    --apha-ink: #243333;
    --apha-muted: #788585;
    --apha-light: #A2ADAD;

    --apha-border: #E7ECEB;

    /* --apha-background: #F7F9F9; */

    position: relative;

    min-height: 100vh;

    /* background:
        radial-gradient(
            circle at 90% 0%,
            rgba(0, 143, 131, .045),
            transparent 30%
        ),

        var(--apha-background); */

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


.filter-control {

    position: relative;

    display: inline-flex;

    align-items: center;

    min-height: 36px;

    border:
        1px solid
        var(--apha-border);

    border-radius: 10px;

    background:
        rgba(255,255,255,.88);

    /* box-shadow:
        0 2px 8px
        rgba(35,70,68,.025); */

    overflow: hidden;

    transition:
        border-color .2s ease,
        box-shadow .2s ease,
        transform .2s ease;

}


.filter-control:hover {

    border-color:
        rgba(0,143,131,.22);
/* 
    box-shadow:
        0 5px 14px
        rgba(35,70,68,.06); */

    transform:
        translateY(-1px);

}




.filter-control :deep(button),
.filter-control :deep(a) {

    border: 0 !important;

    box-shadow: none !important;

}


.filter-chevron {

    display: flex;

    align-items: center;

    justify-content: center;

    width: 25px;

    height: 100%;

    margin-left: -4px;

    padding-right: 7px;

    color:
        var(--apha-muted);

    font-size: 12px;

    font-weight: 800;

    pointer-events: none;

    transition:
        color .2s ease,
        transform .2s ease;

}


.filter-control:hover
.filter-chevron {

    color:
        var(--apha-primary);

    transform:
        translateY(1px);

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

    padding:
        0 12px;

    border:
        1px solid
        var(--apha-border);

    border-radius: 10px;

    background:
        #FFFFFF;

    color:
        var(--apha-ink);

    font-size: 10px;

    font-weight: 700;

    text-decoration: none;

    white-space: nowrap;

    cursor: pointer;

    transition:
        background .2s ease,
        border-color .2s ease,
        color .2s ease,
        transform .2s ease,
        box-shadow .2s ease;

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

    background:
        var(--apha-primary);

    border-color:
        var(--apha-primary);

    color:
        #FFFFFF;

    /* box-shadow:
        0 5px 14px
        rgba(0,143,131,.16); */

}


.action-export .action-icon {

    background:
        rgba(255,255,255,.14);

    color:
        #FFFFFF;

}


.action-export:hover {

    background:
        var(--apha-primary-dark);

    border-color:
        var(--apha-primary-dark);

    color:
        #FFFFFF;

    transform:
        translateY(-1px);

    /* box-shadow:
        0 8px 18px
        rgba(0,143,131,.20); */

}


.action-sort {

    background:
        #FFFFFF;

    color:
        var(--apha-ink);

}


.action-sort .action-icon {

    background:
        var(--apha-primary-soft);

    color:
        var(--apha-primary);

}


.action-sort:hover {

    border-color:
        rgba(0,143,131,.25);

    background:
        #FAFCFC;

    color:
        var(--apha-primary-dark);

    transform:
        translateY(-1px);

}



.action-edit {

    background:
        linear-gradient(
            135deg,
            #FFFFFF,
            #F8FCFB
        );

    border-color:
        rgba(0,143,131,.18);

    color:
        var(--apha-primary-dark);

}


.action-edit .action-icon {

    background:
        var(--apha-primary-soft);

    color:
        var(--apha-primary);

}


.action-edit:hover {

    background:
        var(--apha-primary-soft);

    border-color:
        rgba(0,143,131,.35);

    color:
        var(--apha-primary-dark);

    transform:
        translateY(-1px);
/* 
    box-shadow:
        0 6px 16px
        rgba(0,143,131,.10); */

}




.network-intro {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    margin:
        10px 0 26px;

    padding:
        22px 24px;

    background:
        linear-gradient(
            110deg,
            #FFFFFF 0%,
            #F9FCFB 100%
        );

    border:
        1px solid
        var(--apha-border);

    border-radius:
        18px;
/* 
    box-shadow:
        0 8px 30px
        rgba(35,70,68,.035); */

    overflow:
        hidden;

    position:
        relative;

    animation:
        introAppear .55s ease both;

}


.network-intro::after {

    content: "";

    position: absolute;

    right:
        -50px;

    top:
        -80px;

    width:
        190px;

    height:
        190px;

    border-radius:
        50%;

    background:
        radial-gradient(
            circle,
            rgba(0,143,131,.08),
            transparent 68%
        );

    pointer-events:
        none;

}


.intro-content {

    display:
        flex;

    align-items:
        center;

    gap:
        16px;

}


.intro-icon {

    width:
        48px;

    height:
        48px;

    flex-shrink:
        0;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    border-radius:
        14px;

    color:
        white;

    background:
        linear-gradient(
            135deg,
            var(--apha-primary),
            var(--apha-primary-dark)
        );

    box-shadow:
        0 8px 18px
        rgba(0,143,131,.18);

    animation:
        iconFloat 3s ease-in-out infinite;

}


.intro-icon span {

    font-size:
        19px;

}


.intro-label {

    display:
        block;

    font-size:
        9px;

    font-weight:
        800;

    letter-spacing:
        .12em;

    color:
        var(--apha-primary);

    margin-bottom:
        3px;

}


.intro-content h1 {

    font-size:
        20px;

    font-weight:
        750;

    letter-spacing:
        -.025em;

    color:
        var(--apha-ink);

}


.intro-content p {

    margin-top:
        4px;

    font-size:
        11px;

    color:
        var(--apha-muted);

}



.privacy-badge {

    position:
        relative;

    z-index:
        2;

    display:
        flex;

    align-items:
        center;

    gap:
        7px;

    padding:
        8px 11px;

    background:
        var(--apha-primary-soft);

    color:
        var(--apha-primary-dark);

    border-radius:
        30px;

    font-size:
        10px;

    font-weight:
        650;

    white-space:
        nowrap;

}


.privacy-dot {

    width:
        7px;

    height:
        7px;

    border-radius:
        50%;

    background:
        var(--apha-primary);

    box-shadow:
        0 0 0 4px
        rgba(0,143,131,.08);

    animation:
        statusPulse 2.2s infinite;

}



.network-kpis {

    margin-bottom:
        24px;

}


.kpi-wrapper {

    position:
        relative;

    overflow:
        hidden;

    border-radius:
        16px;

    transition:
        transform .3s ease,
        box-shadow .3s ease;

    animation:
        cardAppear .55s ease both;

}


.kpi-wrapper:nth-child(2) {

    animation-delay:
        .08s;

}


.kpi-wrapper:nth-child(3) {

    animation-delay:
        .16s;

}


.kpi-wrapper:hover {

    transform:
        translateY(-4px);

    box-shadow:
        0 14px 30px
        rgba(35,70,68,.07);

}


.kpi-accent {

    position:
        absolute;

    left:
        0;

    top:
        18px;

    bottom:
        18px;

    width:
        3px;

    background:
        var(--apha-primary);

    border-radius:
        0 4px 4px 0;

    z-index:
        5;

}


.kpi-accent.gold {

    background:
        var(--apha-gold);

}


.kpi-decoration {

    position:
        absolute;

    right:
        17px;

    top:
        17px;

    width:
        38px;

    height:
        38px;

    border-radius:
        11px;

    background:
        var(--apha-primary-soft);

    color:
        var(--apha-primary);

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    pointer-events:
        none;

    transition:
        transform .3s ease;

}


.kpi-decoration.gold {

    background:
        var(--apha-gold-soft);

    color:
        var(--apha-gold);

}


.kpi-wrapper:hover
.kpi-decoration {

    transform:
        rotate(8deg)
        scale(1.08);

}



.table-section {

    position:
        relative;

    background:
        white;

    border:
        1px solid
        var(--apha-border);

    border-radius:
        18px;

    padding:
        4px;

    box-shadow:
        0 8px 30px
        rgba(35,70,68,.035);

    animation:
        tableAppear .65s ease both;

    overflow:
        hidden;

}


.table-top-decoration {

    position:
        absolute;

    left:
        0;

    top:
        0;

    width:
        100%;

    height:
        3px;

    background:
        linear-gradient(
            90deg,
            var(--apha-primary),
            #35A799,
            var(--apha-gold)
        );

    opacity:
        .9;

}




.table-filters {

    display:
        flex;

    align-items:
        center;

    gap:
        8px;

    white-space:
        nowrap;

}


.table-filter-control {

    position:
        relative;

    display:
        inline-flex;

    align-items:
        center;

    min-height:
        32px;

    border:
        1px solid
        var(--apha-border);

    border-radius:
        9px;

    background:
        #FFFFFF;

    box-shadow:
        0 2px 7px
        rgba(35,70,68,.025);

    overflow:
        hidden;

    transition:
        border-color .2s ease,
        box-shadow .2s ease,
        transform .2s ease;

}


.table-filter-control:hover {

    border-color:
        rgba(0,143,131,.24);

    box-shadow:
        0 4px 12px
        rgba(35,70,68,.055);

    transform:
        translateY(-1px);

}


.table-filter-control
:deep(button),
.table-filter-control
:deep(a) {

    border:
        0 !important;

    box-shadow:
        none !important;

}


.table-filter-chevron {

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    width:
        23px;

    height:
        100%;

    padding-right:
        6px;

    color:
        var(--apha-muted);

    font-size:
        11px;

    font-weight:
        800;

    pointer-events:
        none;

    transition:
        color .2s ease,
        transform .2s ease;

}


.table-filter-control:hover
.table-filter-chevron {

    color:
        var(--apha-primary);

    transform:
        translateY(1px);

}



.network-table {

    border-radius:
        14px;

}



.insurer-cell {

    display:
        flex;

    align-items:
        center;

    gap:
        10px;

}


.insurer-avatar {

    width:
        34px;

    height:
        34px;

    flex-shrink:
        0;

    border-radius:
        10px;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    color:
        var(--apha-primary-dark);

    background:
        linear-gradient(
            135deg,
            #E6F6F2,
            #F2FAF8
        );

    font-size:
        11px;

    font-weight:
        800;

    border:
        1px solid
        rgba(0,143,131,.08);

    transition:
        transform .25s ease,
        box-shadow .25s ease;

}


.insurer-row:hover
.insurer-avatar {

    transform:
        scale(1.08);

    box-shadow:
        0 5px 12px
        rgba(0,143,131,.12);

}


.insurer-name {

    display:
        flex;

    flex-direction:
        column;

    gap:
        2px;

}


.insurer-name span {

    font-weight:
        650;

    color:
        var(--apha-ink);

}


.insurer-name small {

    font-size:
        9px;

    color:
        var(--apha-light);

}




.pharmacy-count {

    display:
        flex;

    align-items:
        baseline;

    gap:
        4px;

}


.count-number {

    font-weight:
        700;

    color:
        var(--apha-ink);

}


.count-label {

    font-size:
        9px;

    color:
        var(--apha-light);

}



.delay-cell {

    display:
        flex;

    align-items:
        baseline;

    gap:
        4px;

}


.delay-value {

    font-weight:
        750;

    color:
        var(--apha-ink);

}


.delay-unit {

    font-size:
        9px;

    color:
        var(--apha-light);

}


.delay-alert .delay-value {

    color:
        #C55245;

}



.rate-cell {

    font-weight:
        650;

    color:
        var(--apha-ink);

}


.rate-alert {

    color:
        #C55245;

}


.unpaid-cell {

    color:
        var(--apha-primary-dark);

}




.threshold-edit {

    display:
        inline-flex;

    align-items:
        center;

    gap:
        4px;

    margin-left:
        3px;

    color:
        var(--apha-primary);

    font-size:
        9px;

    font-weight:
        750;

    text-decoration:
        none;

    transition:
        color .2s ease,
        gap .2s ease;

}


.threshold-edit-icon {

    font-size:
        10px;

    opacity:
        .7;

    transition:
        transform .2s ease;

}


.threshold-edit:hover {

    color:
        var(--apha-primary-dark);

    gap:
        6px;

}


.threshold-edit:hover
.threshold-edit-icon {

    transform:
        translate(1px,-1px);

}



.network-footnote {

    display:
        flex;

    align-items:
        flex-start;

    gap:
        9px;

    margin-top:
        14px;

    padding:
        12px 15px;

    border-radius:
        12px;

    background:
        rgba(0,143,131,.035);

    border:
        1px solid
        rgba(0,143,131,.07);

}


.footnote-icon {

    width:
        18px;

    height:
        18px;

    flex-shrink:
        0;

    border-radius:
        50%;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    background:
        var(--apha-primary);

    color:
        white;

    font-size:
        10px;

    font-weight:
        800;

}


.network-footnote p {

    font-size:
        10px;

    line-height:
        1.5;

    color:
        var(--apha-muted);

}



@keyframes introAppear {

    from {

        opacity:
            0;

        transform:
            translateY(8px);

    }

    to {

        opacity:
            1;

        transform:
            translateY(0);

    }

}


@keyframes cardAppear {

    from {

        opacity:
            0;

        transform:
            translateY(12px);

    }

    to {

        opacity:
            1;

        transform:
            translateY(0);

    }

}


@keyframes tableAppear {

    from {

        opacity:
            0;

        transform:
            translateY(15px);

    }

    to {

        opacity:
            1;

        transform:
            translateY(0);

    }

}


@keyframes iconFloat {

    0%,
    100% {

        transform:
            translateY(0);

    }

    50% {

        transform:
            translateY(-3px);

    }

}


@keyframes statusPulse {

    0% {

        box-shadow:
            0 0 0 0
            rgba(0,143,131,.25);

    }

    70% {

        box-shadow:
            0 0 0 5px
            rgba(0,143,131,0);

    }

    100% {

        box-shadow:
            0 0 0 0
            rgba(0,143,131,0);

    }

}



@media (max-width: 900px) {

    .network-intro {

        align-items:
            flex-start;

        flex-direction:
            column;

    }


    .privacy-badge {

        align-self:
            flex-start;

    }

}


@media (max-width: 760px) {

    .header-filters {

        width:
            100%;

        overflow-x:
            auto;

        padding-bottom:
            2px;

        scrollbar-width:
            none;

    }


    .header-filters::-webkit-scrollbar {

        display:
            none;

    }


    .header-actions {

        width:
            100%;

        justify-content:
            stretch;

        gap:
            6px;

    }


    .action-btn {

        flex:
            1;

        min-width:
            0;

        padding:
            0 9px;

    }


    .table-filters {

        width:
            100%;

        overflow-x:
            auto;

        padding-bottom:
            2px;

        scrollbar-width:
            none;

    }


    .table-filters::-webkit-scrollbar {

        display:
            none;

    }

}


@media (max-width: 640px) {

    .network-page {

        padding-bottom:
            80px;

    }


    .network-intro {

        margin-top:
            5px;

        padding:
            17px;

        border-radius:
            14px;

    }


    .intro-content {

        align-items:
            flex-start;

    }


    .intro-icon {

        width:
            40px;

        height:
            40px;

        border-radius:
            11px;

    }


    .intro-content h1 {

        font-size:
            17px;

    }


    .intro-content p {

        font-size:
            10px;

        line-height:
            1.5;

    }


    .privacy-badge {

        width:
            100%;

        justify-content:
            center;

    }


    .header-actions {

        gap:
            5px;

    }


    .action-btn {

        height:
            34px;

        padding:
            0 7px;

        gap:
            5px;

        font-size:
            9px;

    }


    .action-icon {

        width:
            18px;

        height:
            18px;

        font-size:
            11px;

    }


    .table-section {

        border-radius:
            14px;

        padding:
            2px;

    }


    .network-footnote {

        margin-top:
            10px;

    }

}


@media (max-width: 400px) {

    .action-btn {

        padding:
            0 5px;

        gap:
            4px;

        font-size:
            8px;

    }


    .action-icon {

        width:
            17px;

        height:
            17px;

        font-size:
            10px;

    }

}



@media (prefers-reduced-motion: reduce) {

    .network-page *,
    .network-page *::before,
    .network-page *::after {

        animation-duration:
            .01ms !important;

        animation-iteration-count:
            1 !important;

        transition-duration:
            .01ms !important;

    }

}

</style>