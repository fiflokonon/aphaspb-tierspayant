<script setup lang="ts">
import { Deferred, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import DelayTrendChart from '@/components/aphaspb/charts/DelayTrendChart.vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterChip from '@/components/aphaspb/FilterChip.vue';
import InsufficientDataRow from '@/components/aphaspb/InsufficientDataRow.vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { formatMillions } from '@/lib/millions';
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
    city: string | null;
    window: number;
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

const series = computed(() =>
    Object.values(props.trend?.insurers ?? {}).map((one) => ({
        name: one.name,
        points: one.points,
    })),
);
</script>

<template>
    <Head title="Évolution du réseau" />

    <ConsoleHeader
        eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
        :title="`${window} derniers mois`"
    >
        <template #filters>
            <FilterChip :label="city ?? 'Toutes les villes'" />
        </template>
    </ConsoleHeader>

    <KpiRow :columns="4">
        <KpiCard
            label="FACTURÉ · RÉSEAU"
            :value="formatMillions(summary.invoiced)"
            unit="FCFA"
            :hint="`${summary.declaringPharmacies} officines déclarantes`"
        />
        <KpiCard
            label="ENCAISSÉ"
            :value="formatMillions(summary.received)"
            unit="FCFA"
            :tone="recoveryTone(summary.recoveryRate)"
            :hint="`${share(summary.received)} du facturé`"
        />
        <KpiCard
            label="ENCOURS DU RÉSEAU"
            :value="formatMillions(summary.outstanding)"
            unit="FCFA"
            tone="bad"
            :hint="`${share(summary.outstanding)} du facturé · dont ${formatMillions(summary.outstandingBeyond90)} au-delà de 90 j`"
        />
        <KpiCard
            label="DÉLAI MOYEN PONDÉRÉ"
            :value="summary.weightedDelayDays?.toLocaleString('fr-FR') ?? '—'"
            unit="jours"
            :tone="delayTone(summary.weightedDelayDays)"
            :hint="`délai standard moyen ${threshold} j`"
        />
    </KpiRow>

    <div class="mt-[22px] rounded-[11px] border border-border bg-card p-4">
        <div class="text-[12.5px] font-bold text-ink">
            Évolution du délai de paiement par assureur
        </div>
        <p class="mt-1 text-[11px]/[1.4] text-ink/50">
            Délai moyen pondéré par les montants, en jours · ligne de référence
            à {{ threshold }} j, la moyenne des délais standard des assureurs ·
            un assureur n'apparaît qu'à partir de 5 officines déclarantes.
        </p>

        <Deferred data="trend">
            <template #fallback>
                <ChartSkeleton class="mt-4" :height="220" />
            </template>

            <DelayTrendChart
                v-if="trend"
                class="mt-4"
                :series="series"
                :network="trend.network"
                :threshold="trend.threshold"
            />
        </Deferred>
    </div>

    <DataTable
        title="Montants agrégés par assureur"
        :columns="COLUMNS"
        :template="TEMPLATE"
        footer="Aucun montant individuel : l'agrégation s'ouvre à partir de 5 officines déclarantes."
    >
        <template v-for="row in amounts" :key="row.insurerId">
            <InsufficientDataRow
                v-if="!row.sufficient"
                :template="TEMPLATE"
                :label="row.insurerName"
                :span="4"
                :explanation="`${row.declaringPharmacies} officine${row.declaringPharmacies > 1 ? 's' : ''} déclarante${row.declaringPharmacies > 1 ? 's' : ''} — les montants s'agrègent à partir de ${row.required}`"
            />
            <DataTableRow v-else :template="TEMPLATE">
                <div>{{ row.insurerName }}</div>
                <div class="font-medium text-ink/60">
                    {{ row.declaringPharmacies }}
                </div>
                <div>{{ formatMillions(row.invoiced ?? 0) }}</div>
                <div class="text-terracotta-dark">
                    {{ formatMillions(row.outstanding ?? 0) }}
                </div>
                <div
                    :class="
                        (row.recoveryRate ?? 0) < 60
                            ? 'text-terracotta-dark'
                            : 'text-officine'
                    "
                >
                    {{ row.recoveryRate?.toLocaleString('fr-FR') ?? '—' }} %
                </div>
            </DataTableRow>
        </template>
    </DataTable>
</template>
