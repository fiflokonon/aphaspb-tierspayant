<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
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
    standardDelayDays: number | null;
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
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    city: string | null;
    cities: string[];
}>();

const TEMPLATE = '1.7fr 1fr .9fr 1fr .8fr .9fr';

const COLUMNS = [
    'ASSUREUR',
    'OFFICINES (n)',
    'DÉLAI MOYEN',
    'DANS LES DÉLAIS',
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
            only: ['indicators', 'summary', 'period', 'periodLabel', 'city'],
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

    <ConsoleHeader eyebrow="RÉSEAU DES OFFICINES · BÉNIN" :title="periodLabel">
        <template #filters>
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
            :tone="delayTone(summary.averageDelayDays, networkStandard)"
            hint="statuts payés et partiels confondus"
        />
        <KpiCard
            label="PAYÉ DANS LES DÉLAIS"
            :value="
                summary.withinThresholdShare?.toLocaleString('fr-FR') ?? '—'
            "
            unit="%"
            :tone="shareTone(summary.withinThresholdShare)"
        >
            <template #hint>
                selon le délai retenu pour chaque assureur ·
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
                <div>
                    <div
                        :class="
                            delayTone(
                                indicator.averageDelayDays,
                                standardFor(indicator),
                            ) === 'bad'
                                ? 'text-terracotta-dark'
                                : ''
                        "
                    >
                        {{ days(indicator.averageDelayDays) }}
                    </div>
                    <!--
                        The rule beside the figure: without it the colour of
                        this cell reads as a verdict with no stated basis.
                    -->
                    <div class="text-[10.5px] text-ink/45">
                        standard {{ days(indicator.standardDelayDays) }}
                    </div>
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
