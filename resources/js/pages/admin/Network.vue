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

    <ConsoleHeader eyebrow="RÉSEAU DES OFFICINES · BÉNIN" :title="period">
        <template #filters>
            <FilterChip :label="period" @click="reloadIndicators" />
            <FilterChip
                :label="city ?? 'Toutes les villes'"
                @click="reloadIndicators"
            />
        </template>
        <template #action>
            <PrimaryAction label="Export CSV" :href="'/admin/csv-exports'" />
        </template>
    </ConsoleHeader>

    <KpiRow :columns="3">
        <KpiCard
            label="OFFICINES DÉCLARANTES"
            :value="summary.declaringPharmacies.toLocaleString('fr-FR')"
            tone="neutral"
            hint="ayant déclaré au moins une fois sur la période"
        />
        <KpiCard
            label="DÉLAI MOYEN RÉSEAU"
            :value="summary.averageDelayDays?.toLocaleString('fr-FR') ?? '—'"
            unit="jours"
            :tone="delayTone(summary.averageDelayDays)"
            hint="statuts payés et partiels confondus"
        />
        <KpiCard
            :label="`PAYÉ ≤ ${threshold} JOURS`"
            :value="
                summary.withinThresholdShare?.toLocaleString('fr-FR') ?? '—'
            "
            unit="%"
            :tone="shareTone(summary.withinThresholdShare)"
        >
            <template #hint>
                seuil de {{ threshold }} j ·
                <Link
                    href="/admin/insurers"
                    class="font-semibold text-officine"
                >
                    modifier
                </Link>
            </template>
        </KpiCard>
    </KpiRow>

    <DataTable
        title="Indicateurs par assureur"
        :columns="COLUMNS"
        :template="TEMPLATE"
        :footer="footer"
    >
        <template #filters>
            <FilterChip label="Trier : délai moyen" size="compact" />
            <FilterChip label="Actifs uniquement" size="compact" />
        </template>

        <template v-for="indicator in indicators" :key="indicator.insurerId">
            <InsufficientDataRow
                v-if="!indicator.sufficient"
                :template="TEMPLATE"
                :label="indicator.insurerName"
                :span="5"
                :explanation="`${indicator.declaringPharmacies} officine${indicator.declaringPharmacies > 1 ? 's' : ''} déclarante${indicator.declaringPharmacies > 1 ? 's' : ''} — affichage à partir de ${indicator.required}, pour garantir l'anonymat`"
            />
            <DataTableRow
                v-else
                :template="TEMPLATE"
                :tone="isAlarming(indicator) ? 'alert' : 'default'"
            >
                <div>{{ indicator.insurerName }}</div>
                <div class="font-medium text-ink/60">
                    {{ indicator.declaringPharmacies }}
                </div>
                <div
                    :class="
                        delayTone(indicator.averageDelayDays) === 'bad'
                            ? 'text-terracotta-dark'
                            : ''
                    "
                >
                    {{ days(indicator.averageDelayDays) }}
                </div>
                <ProgressMiniBar
                    :share="indicator.withinThresholdShare ?? 0"
                    :tone="shareTone(indicator.withinThresholdShare)"
                    :label="percent(indicator.withinThresholdShare)"
                />
                <div
                    class="font-medium"
                    :class="
                        (indicator.rejectionRate ?? 0) > 15
                            ? 'text-terracotta-dark'
                            : ''
                    "
                >
                    {{ percent(indicator.rejectionRate) }}
                </div>
                <div class="font-medium">
                    {{ percent(indicator.unpaidRate) }}
                </div>
            </DataTableRow>
        </template>
    </DataTable>
</template>
