<script setup lang="ts">
import { Deferred, Head } from '@inertiajs/vue3';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import InvoicedVsCollectedChart from '@/components/aphaspb/charts/InvoicedVsCollectedChart.vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import PrimaryAction from '@/components/aphaspb/PrimaryAction.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { formatMillions } from '@/lib/millions';
import type { DashboardInvitation } from '@/types';
import type { KpiTone } from '@/types/aphaspb';

type RecoveryRow = {
    insurerId: number;
    insurerName: string;
    invoiced: number;
    received: number;
    outstanding: number;
    recoveryRate: number | null;
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
    declareUrl: string;
    journey?: JourneyPoint[];
    pendingInvitations?: DashboardInvitation[];
}>();

const recoveryTone = (rate: number | null): KpiTone => {
    if (rate === null) {
        return 'neutral';
    }

    return rate >= 80 ? 'good' : rate >= 60 ? 'warn' : 'bad';
};

const delayTone = (days: number | null): KpiTone => {
    if (days === null) {
        return 'neutral';
    }

    return days <= 30 ? 'good' : days <= 60 ? 'warn' : 'bad';
};

const RECOVERY_TEMPLATE = '1.9fr 1fr 1fr 1fr .9fr';
const RECOVERY_COLUMNS = [
    'ASSUREUR',
    'FACTURÉ',
    'ENCAISSÉ',
    'RESTE DÛ',
    'TAUX',
];

const eyebrow = [props.pharmacyName, props.city]
    .filter(Boolean)
    .join(' · ')
    .toUpperCase();

const ageingTotal = props.ageing.reduce((sum, band) => sum + band.amount, 0);
</script>

<template>
    <Head title="Tableau de bord" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="dashboard-page">
        <ConsoleHeader
            :eyebrow="eyebrow"
            title="Parcours des paiements"
            class="dashboard-header"
        >
            <template #action>
                <PrimaryAction
                    label="+ Nouvelle déclaration"
                    :href="declareUrl"
                />
            </template>
        </ConsoleHeader>

        <section class="dashboard-intro">
            <div class="intro-left">
                <div class="intro-icon">
                    <span class="intro-pulse"></span>
                    <span class="intro-symbol">↗</span>
                </div>

                <div class="intro-text">
                    <span class="intro-label"> VUE D'ENSEMBLE </span>

                    <h1>Suivez vos paiements</h1>

                    <p>
                        Une vision claire des montants facturés, encaissés et
                        restant dus sur votre réseau.
                    </p>
                </div>
            </div>

            <div class="intro-status">
                <span class="status-dot"></span>
                <span> Données actualisées </span>
            </div>
        </section>

        <KpiRow :columns="3" class="dashboard-kpis">
            <div class="dashboard-kpi-wrapper">
                <div class="kpi-side-accent"></div>

                <KpiCard
                    label="FACTURÉ SUR 12 MOIS"
                    :value="formatMillions(summary.invoiced)"
                    unit="FCFA"
                    :hint="`${summary.insurers} assureurs · ${summary.declarations} déclarations`"
                />

                <div class="kpi-icon">
                    <span>₣</span>
                </div>
            </div>

            <div class="dashboard-kpi-wrapper">
                <div class="kpi-side-accent teal"></div>

                <KpiCard
                    label="TAUX DE RECOUVREMENT"
                    :value="
                        summary.recoveryRate?.toLocaleString('fr-FR') ?? '—'
                    "
                    unit="%"
                    :tone="recoveryTone(summary.recoveryRate)"
                    :hint="`${formatMillions(summary.received)} FCFA encaissés`"
                />

                <div class="kpi-icon teal">
                    <span>✓</span>
                </div>
            </div>

            <div class="dashboard-kpi-wrapper">
                <div class="kpi-side-accent gold"></div>

                <KpiCard
                    label="VOTRE DÉLAI MOYEN"
                    :value="
                        summary.weightedDelayDays?.toLocaleString('fr-FR') ??
                        '—'
                    "
                    unit="jours"
                    :tone="delayTone(summary.weightedDelayDays)"
                    hint="pondéré par les montants reçus"
                />

                <div class="kpi-icon gold">
                    <span>◷</span>
                </div>
            </div>
        </KpiRow>

        <section class="dashboard-card journey-card">
            <div class="card-top-line"></div>

            <div class="card-header">
                <div class="card-title-group">
                    <div class="card-icon teal">
                        <span>⌁</span>
                    </div>

                    <div>
                        <h2>Parcours des paiements</h2>

                        <p>
                            Facturé et encaissé, en millions de FCFA, sur les 12
                            derniers mois.
                        </p>
                    </div>
                </div>

                <div class="card-badge">
                    <span class="badge-dot"></span>
                    12 mois
                </div>
            </div>

            <Deferred data="journey">
                <template #fallback>
                    <ChartSkeleton class="mt-5" :height="200" />
                </template>

                <div v-if="journey" class="chart-wrapper">
                    <InvoicedVsCollectedChart :points="journey" />
                </div>
            </Deferred>
        </section>

        <div class="analysis-grid">
            <section class="dashboard-card analysis-card">
                <div class="card-header">
                    <div class="card-title-group">
                        <div class="card-icon gold">
                            <span>◷</span>
                        </div>

                        <div>
                            <h2>Encours par ancienneté</h2>

                            <p>
                                Ancienneté comptée depuis la fin du mois
                                déclaré.
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
                                    width: `${ageingTotal === 0 ? 0 : (band.amount / ageingTotal) * 100}%`,
                                }"
                            ></span>
                        </div>

                        <div class="ageing-value">
                            {{ formatMillions(band.amount) }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="dashboard-card analysis-card">
                <div class="card-header">
                    <div class="card-title-group">
                        <div class="card-icon terracotta">
                            <span>F</span>
                        </div>

                        <div>
                            <h2>Qui vous doit le plus</h2>

                            <p>
                                Reste dû par assureur, sur les 12 derniers mois.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="owed-list">
                    <div
                        v-for="(entry, index) in owed"
                        :key="entry.insurerName"
                        class="owed-row"
                    >
                        <div class="owed-rank">
                            {{ String(index + 1).padStart(2, '0') }}
                        </div>

                        <div class="owed-name">
                            {{ entry.insurerName }}
                        </div>

                        <div v-if="entry.outstanding === 0" class="owed-status">
                            <span class="status-check"> ✓ </span>

                            À JOUR
                        </div>

                        <div v-else class="owed-amount">
                            {{ formatMillions(entry.outstanding) }}
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="dashboard-footnote">
            <div class="footnote-icon">i</div>

            <p>
                Les indicateurs sont calculés à partir des déclarations
                transmises par les officines participantes.
            </p>
        </div>
    </div>

    <DataTable
        title="Recouvrement par assureur"
        :columns="RECOVERY_COLUMNS"
        :template="RECOVERY_TEMPLATE"
        footer="Sur les 12 derniers mois. Un assureur coché sans déclaration reste listé, sans taux."
    >
        <DataTableRow
            v-for="row in recovery"
            :key="row.insurerId"
            :template="RECOVERY_TEMPLATE"
        >
            <div>{{ row.insurerName }}</div>
            <div>{{ formatMillions(row.invoiced) }}</div>
            <div>{{ formatMillions(row.received) }}</div>
            <div
                :class="
                    row.outstanding === 0
                        ? 'text-officine'
                        : 'text-terracotta-dark'
                "
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
            >
                {{
                    row.recoveryRate === null
                        ? '—'
                        : `${row.recoveryRate.toLocaleString('fr-FR')} %`
                }}
            </div>
        </DataTableRow>
    </DataTable>
</template>

<style scoped>
.dashboard-page {
    --primary: #008f83;
    --primary-dark: #006f68;
    --primary-soft: #e8f6f3;

    --gold: #d7a33d;
    --gold-soft: #fff8e9;

    --terracotta: #c0472f;
    --terracotta-soft: #fff0ec;

    --ink: #17211c;
    --muted: #788585;
    --light: #a2adad;

    --border: #e7eceb;
    --background: #f7f9f9;

    position: relative;
    min-height: 100vh;

    padding-bottom: 45px;

    color: var(--ink);

    /* background:
        radial-gradient(
            circle at 95% 0%,
            rgba(0, 143, 131, 0.045),
            transparent 28%
        ),
        var(--background); */
}

.dashboard-header {
    position: relative;
    z-index: 5;
}

.dashboard-intro {
    position: relative;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;

    margin: 12px 0 22px;

    padding: 20px 22px;

    border: 1px solid var(--border);

    border-radius: 17px;

    /* background:
        linear-gradient(
            110deg,
            #ffffff 0%,
            #f9fcfb 100%
        ); */

    /* box-shadow:
        0 8px 28px rgba(35, 70, 68, 0.035); */

    overflow: hidden;

    animation: dashboardFade 0.55s ease both;
}

/* Halo décoratif */

.dashboard-intro::after {
    content: '';

    position: absolute;

    width: 220px;
    height: 220px;

    right: -80px;
    top: -110px;

    border-radius: 50%;

    background: radial-gradient(
        circle,
        rgba(0, 143, 131, 0.09),
        transparent 68%
    );

    pointer-events: none;
}

.intro-left {
    display: flex;
    align-items: center;

    gap: 15px;

    position: relative;
    z-index: 1;
}

.intro-icon {
    position: relative;

    width: 48px;
    height: 48px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 14px;

    color: #ffffff;

    background: linear-gradient(135deg, var(--primary), var(--primary-dark));

    /* box-shadow:
        0 9px 20px rgba(0, 143, 131, .18); */

    animation: iconFloat 3s ease-in-out infinite;
}

.intro-symbol {
    position: relative;
    z-index: 2;

    font-size: 18px;
    font-weight: 800;
}

.intro-pulse {
    position: absolute;

    inset: 8px;

    border: 1px solid rgba(255, 255, 255, 0.28);

    border-radius: 10px;

    animation: pulseIcon 2.5s infinite;
}

.intro-label {
    display: block;

    margin-bottom: 3px;

    font-size: 9px;
    font-weight: 800;

    letter-spacing: 0.13em;

    color: var(--primary);
}

.intro-text h1 {
    margin: 0;

    font-size: 19px;
    font-weight: 750;

    letter-spacing: -0.025em;

    color: var(--ink);
}

.intro-text p {
    margin-top: 4px;

    font-size: 11px;

    line-height: 1.5;

    color: var(--muted);
}

.intro-status {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;

    gap: 7px;

    padding: 8px 11px;

    border-radius: 30px;

    background: var(--primary-soft);

    color: var(--primary-dark);

    font-size: 9.5px;
    font-weight: 700;

    white-space: nowrap;
}

.status-dot,
.badge-dot {
    width: 7px;
    height: 7px;

    flex-shrink: 0;

    border-radius: 50%;

    background: var(--primary);

    box-shadow: 0 0 0 4px rgba(0, 143, 131, 0.08);

    animation: statusPulse 2.2s infinite;
}

.dashboard-kpis {
    margin-bottom: 22px;
}

.dashboard-kpi-wrapper {
    position: relative;

    overflow: hidden;

    border-radius: 16px;

    animation: cardAppear 0.55s ease both;

    transition:
        transform 0.3s ease,
        box-shadow 0.3s ease;
}

.dashboard-kpi-wrapper:nth-child(2) {
    animation-delay: 0.08s;
}

.dashboard-kpi-wrapper:nth-child(3) {
    animation-delay: 0.16s;
}

.dashboard-kpi-wrapper:hover {
    transform: translateY(-4px);

    box-shadow: 0 14px 30px rgba(35, 70, 68, 0.07);
}

.kpi-side-accent {
    position: absolute;

    z-index: 5;

    left: 0;
    top: 17px;
    bottom: 17px;

    width: 3px;

    border-radius: 0 5px 5px 0;

    background: var(--primary);
}

.kpi-side-accent.teal {
    background: var(--primary);
}

.kpi-side-accent.gold {
    background: var(--gold);
}

.kpi-icon {
    position: absolute;

    top: 16px;
    right: 16px;

    width: 37px;
    height: 37px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;

    background: var(--primary-soft);

    color: var(--primary);

    pointer-events: none;

    transition: transform 0.3s ease;
}

.kpi-icon.teal {
    background: var(--primary-soft);

    color: var(--primary);
}

.kpi-icon.gold {
    background: var(--gold-soft);

    color: var(--gold);
}

.dashboard-kpi-wrapper:hover .kpi-icon {
    transform: rotate(8deg) scale(1.08);
}

.dashboard-card {
    position: relative;

    background: #ffffff;

    border: 1px solid var(--border);

    border-radius: 17px;

    box-shadow: 0 8px 30px rgba(35, 70, 68, 0.035);

    overflow: hidden;

    animation: cardAppear 0.6s ease both;
}

.dashboard-card:hover {
    box-shadow: 0 12px 32px rgba(35, 70, 68, 0.055);
}

.card-top-line {
    position: absolute;

    top: 0;
    left: 0;

    width: 100%;
    height: 3px;

    background: linear-gradient(90deg, var(--primary), #35a799, var(--gold));

    opacity: 0.9;
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 18px 19px 0;
}

.card-title-group {
    display: flex;
    align-items: center;

    gap: 12px;
}

.card-icon {
    width: 38px;
    height: 38px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

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

.card-header h2 {
    margin: 0;

    font-size: 13px;
    font-weight: 750;

    color: var(--ink);
}

.card-header p {
    margin-top: 3px;

    font-size: 10.5px;

    line-height: 1.45;

    color: var(--muted);
}

.card-badge {
    display: flex;
    align-items: center;

    gap: 6px;

    padding: 6px 9px;

    border: 1px solid var(--border);

    border-radius: 20px;

    color: var(--muted);

    font-size: 9px;
    font-weight: 700;

    white-space: nowrap;
}

.journey-card {
    margin-bottom: 12px;
}

.chart-wrapper {
    margin-top: 17px;

    padding: 0 17px 17px;
}

.analysis-grid {
    display: grid;

    grid-template-columns: repeat(2, minmax(0, 1fr));

    gap: 12px;
}

.analysis-card {
    min-width: 0;

    padding-bottom: 16px;
}

.ageing-list {
    display: flex;

    flex-direction: column;

    gap: 13px;

    margin-top: 18px;

    padding: 0 18px;
}

.ageing-row {
    display: grid;

    grid-template-columns: 62px minmax(0, 1fr) 78px;

    align-items: center;

    gap: 10px;
}

.ageing-label {
    font-family: 'JetBrains Mono', monospace;

    font-size: 9.5px;

    font-weight: 600;

    color: var(--muted);
}

.ageing-progress {
    position: relative;

    height: 7px;

    overflow: hidden;

    border-radius: 20px;

    background: rgba(23, 33, 28, 0.07);
}

.ageing-progress-fill {
    display: block;

    height: 100%;

    min-width: 3px;

    border-radius: inherit;

    background: linear-gradient(90deg, #d7a33d, #e6bf63);

    transform-origin: left center;

    animation: progressAppear 0.9s ease both;

    transition: width 0.7s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.ageing-row:hover .ageing-progress-fill {
    filter: brightness(0.96);

    transform: scaleY(1.35);
}

.ageing-value {
    text-align: right;

    font-size: 10.5px;
    font-weight: 750;

    color: var(--ink);
}

.owed-list {
    display: flex;

    flex-direction: column;

    margin-top: 15px;

    padding: 0 18px;
}

.owed-row {
    display: flex;

    align-items: center;

    gap: 9px;

    min-height: 42px;

    border-bottom: 1px solid rgba(23, 33, 28, 0.055);

    transition:
        padding 0.2s ease,
        background 0.2s ease;
}

.owed-row:last-child {
    border-bottom: 0;
}

.owed-row:hover {
    padding-left: 5px;
}

.owed-rank {
    width: 24px;

    flex-shrink: 0;

    font-family: 'JetBrains Mono', monospace;

    font-size: 8.5px;
    font-weight: 700;

    color: var(--light);
}

.owed-name {
    min-width: 0;

    flex: 1;

    overflow: hidden;

    text-overflow: ellipsis;

    white-space: nowrap;

    font-size: 10.5px;
    font-weight: 650;

    color: var(--ink);
}

.owed-amount {
    flex-shrink: 0;

    padding: 5px 8px;

    border-radius: 7px;

    background: var(--terracotta-soft);

    color: var(--terracotta);

    font-size: 9.5px;
    font-weight: 750;
}

.owed-status {
    display: flex;

    align-items: center;

    gap: 5px;

    flex-shrink: 0;

    color: var(--primary);

    font-size: 8.5px;
    font-weight: 800;
}

.status-check {
    width: 17px;
    height: 17px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 9px;
}

.dashboard-footnote {
    display: flex;

    align-items: flex-start;

    gap: 9px;

    margin-top: 13px;

    padding: 11px 14px;

    border: 1px solid rgba(0, 143, 131, 0.07);

    border-radius: 12px;

    background: rgba(0, 143, 131, 0.035);
}

.footnote-icon {
    width: 18px;
    height: 18px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: var(--primary);

    color: #ffffff;

    font-size: 9px;
    font-weight: 800;
}

.dashboard-footnote p {
    margin: 0;

    font-size: 9.5px;

    line-height: 1.5;

    color: var(--muted);
}

@keyframes dashboardFade {
    from {
        opacity: 0;
        transform: translateY(7px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
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

@keyframes iconFloat {
    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-3px);
    }
}

@keyframes pulseIcon {
    0% {
        opacity: 0.3;
        transform: scale(0.92);
    }

    50% {
        opacity: 0.8;
        transform: scale(1);
    }

    100% {
        opacity: 0.3;
        transform: scale(0.92);
    }
}

@keyframes statusPulse {
    0% {
        box-shadow: 0 0 0 0 rgba(0, 143, 131, 0.25);
    }

    70% {
        box-shadow: 0 0 0 5px rgba(0, 143, 131, 0);
    }

    100% {
        box-shadow: 0 0 0 0 rgba(0, 143, 131, 0);
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

@media (max-width: 900px) {
    .dashboard-intro {
        align-items: flex-start;

        flex-direction: column;
    }

    .intro-status {
        align-self: flex-start;
    }

    .analysis-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .dashboard-page {
        padding-bottom: 70px;
    }

    .dashboard-intro {
        margin-top: 7px;

        padding: 16px;

        border-radius: 14px;
    }

    .intro-left {
        align-items: flex-start;
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
        font-size: 10px;
    }

    .intro-status {
        width: 100%;

        justify-content: center;
    }

    .dashboard-card {
        border-radius: 14px;
    }

    .card-header {
        align-items: flex-start;

        padding: 16px 15px 0;
    }

    .card-title-group {
        align-items: flex-start;
    }

    .card-icon {
        width: 34px;
        height: 34px;

        border-radius: 10px;
    }

    .card-header h2 {
        font-size: 12px;
    }

    .card-header p {
        font-size: 9.5px;
    }

    .card-badge {
        display: none;
    }

    .chart-wrapper {
        padding: 0 11px 12px;
    }

    .ageing-list,
    .owed-list {
        padding: 0 14px;
    }

    .ageing-row {
        grid-template-columns: 55px minmax(0, 1fr) 68px;

        gap: 7px;
    }

    .ageing-label {
        font-size: 8.5px;
    }

    .ageing-value {
        font-size: 9.5px;
    }

    .owed-row {
        min-height: 40px;
    }

    .owed-name {
        font-size: 10px;
    }

    .owed-amount {
        font-size: 8.5px;
    }

    .dashboard-footnote {
        padding: 10px 12px;
    }
}

@media (max-width: 400px) {
    .dashboard-intro {
        padding: 14px;
    }

    .intro-text h1 {
        font-size: 16px;
    }

    .intro-text p {
        font-size: 9.5px;
    }

    .ageing-row {
        grid-template-columns: 50px minmax(0, 1fr) 60px;
    }

    .owed-rank {
        display: none;
    }

    .owed-row:hover {
        padding-left: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dashboard-page *,
    .dashboard-page *::before,
    .dashboard-page *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
```
