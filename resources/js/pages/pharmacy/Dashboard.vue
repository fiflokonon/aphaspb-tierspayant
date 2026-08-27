<script setup lang="ts">
import { Deferred, Head } from '@inertiajs/vue3';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import InvoicedVsCollectedChart from '@/components/aphaspb/charts/InvoicedVsCollectedChart.vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import PrimaryAction from '@/components/aphaspb/PrimaryAction.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { formatMillions } from '@/lib/millions';
import type { DashboardInvitation } from '@/types';
import type { KpiTone } from '@/types/aphaspb';

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

    <ConsoleHeader :eyebrow="eyebrow" title="Parcours des paiements">
        <template #action>
            <PrimaryAction label="+ Nouvelle déclaration" :href="declareUrl" />
        </template>
    </ConsoleHeader>

    <KpiRow :columns="3">
        <KpiCard
            label="FACTURÉ SUR 12 MOIS"
            :value="formatMillions(summary.invoiced)"
            unit="FCFA"
            :hint="`${summary.insurers} assureurs · ${summary.declarations} déclarations`"
        />
        <KpiCard
            label="TAUX DE RECOUVREMENT"
            :value="summary.recoveryRate?.toLocaleString('fr-FR') ?? '—'"
            unit="%"
            :tone="recoveryTone(summary.recoveryRate)"
            :hint="`${formatMillions(summary.received)} FCFA encaissés`"
        />
        <KpiCard
            label="VOTRE DÉLAI MOYEN"
            :value="summary.weightedDelayDays?.toLocaleString('fr-FR') ?? '—'"
            unit="jours"
            :tone="delayTone(summary.weightedDelayDays)"
            hint="pondéré par les montants reçus"
        />
    </KpiRow>

    <div class="mt-[22px] rounded-[11px] border border-border bg-card p-4">
        <div class="text-[12.5px] font-bold text-ink">
            Parcours des paiements
        </div>
        <p class="mt-1 text-[11px]/[1.4] text-ink/50">
            Facturé et encaissé, en millions de FCFA, sur les 12 derniers mois.
        </p>

        <Deferred data="journey">
            <template #fallback>
                <ChartSkeleton class="mt-4" :height="200" />
            </template>

            <InvoicedVsCollectedChart
                v-if="journey"
                class="mt-4"
                :points="journey"
            />
        </Deferred>
    </div>

    <div class="mt-3 grid gap-3 lg:grid-cols-2">
        <div class="rounded-[11px] border border-border bg-card p-4">
            <div class="text-[12.5px] font-bold text-ink">
                Encours par ancienneté
            </div>
            <p class="mt-1 text-[11px]/[1.4] text-ink/[0.45]">
                Ancienneté comptée depuis la fin du mois déclaré.
            </p>
            <div class="mt-3 flex flex-col gap-[10px]">
                <div
                    v-for="band in ageing"
                    :key="band.label"
                    class="flex items-center gap-3"
                >
                    <span
                        class="w-[62px] shrink-0 font-mono text-[10.5px] text-ink/50"
                    >
                        {{ band.label }}
                    </span>
                    <span class="h-[6px] flex-1 rounded-full bg-ink/[0.08]">
                        <span
                            class="block h-full rounded-full bg-gold-mid"
                            :style="{
                                width: `${ageingTotal === 0 ? 0 : (band.amount / ageingTotal) * 100}%`,
                            }"
                        />
                    </span>
                    <span
                        class="w-[70px] shrink-0 text-right text-xs font-semibold text-ink"
                    >
                        {{ formatMillions(band.amount) }}
                    </span>
                </div>
            </div>
        </div>

        <div class="rounded-[11px] border border-border bg-card p-4">
            <div class="text-[12.5px] font-bold text-ink">
                Qui vous doit le plus
            </div>
            <p class="mt-1 text-[11px]/[1.4] text-ink/[0.45]">
                Reste dû par assureur, sur les 12 derniers mois.
            </p>
            <div class="mt-3 flex flex-col gap-2">
                <div
                    v-for="entry in owed"
                    :key="entry.insurerName"
                    class="flex items-baseline gap-3"
                >
                    <span
                        class="min-w-0 flex-1 truncate text-xs font-semibold text-ink"
                    >
                        {{ entry.insurerName }}
                    </span>
                    <span
                        v-if="entry.outstanding === 0"
                        class="font-mono text-[10px] font-semibold text-officine"
                    >
                        À JOUR
                    </span>
                    <span
                        v-else
                        class="text-xs font-semibold text-terracotta-dark"
                    >
                        {{ formatMillions(entry.outstanding) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
