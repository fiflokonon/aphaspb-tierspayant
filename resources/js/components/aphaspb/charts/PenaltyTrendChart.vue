<script setup lang="ts">
/**
 * L'évolution mensuelle des pénalités, en courbes ou en barres.
 *
 * Un mois retenu ou futur vaut null et reste un **trou** : tracé à zéro, il se
 * lirait « rien n'a couru », ce qui est une affirmation. Même règle que
 * DelayBarChart pour les mois sans règlement.
 *
 * Au-delà de trois séries, la couleur seule ne les sépare plus : les suivantes
 * reprennent la palette en pointillé, comme DelayTrendChart.
 */
import { VisAxis, VisGroupedBar, VisLine, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import { gap } from '@/lib/chartGap';
import { formatMillions } from '@/lib/millions';
import { penaltyChartRows } from '@/lib/penaltySeries';
import type { PenaltyChartRow } from '@/lib/penaltySeries';
import { CHART_COLORS } from '@/types/aphaspb';
import type {
    ChartType,
    PenaltyLedgerSeries,
    PenaltyView,
} from '@/types/aphaspb';

const props = defineProps<{
    series: PenaltyLedgerSeries[];
    /** La série totale, ou null quand un seul assureur est affiché. */
    total: PenaltyLedgerSeries | null;
    view: PenaltyView;
    type: ChartType;
}>();

const data = computed(() =>
    penaltyChartRows(props.series, props.total, props.view),
);

const SOLID_LIMIT = CHART_COLORS.length;

const isDashed = (position: number) => position >= SOLID_LIMIT;

const colorFor = (position: number) =>
    CHART_COLORS[position % CHART_COLORS.length] as string;

/**
 * Une VisLine par motif de trait : lineDashArray vaut pour tout le composant,
 * c'est en répartissant les séries sur deux couches que certaines restent
 * pleines et d'autres pointillées (voir DelayTrendChart).
 */
const positionsWhere = (dashed: boolean) =>
    props.series
        .map((_, position) => position)
        .filter((position) => isDashed(position) === dashed);

const solid = computed(() => positionsWhere(false));
const dashed = computed(() => positionsWhere(true));

const accessorsFor = (positions: number[]) =>
    positions.map(
        (position) => (row: PenaltyChartRow) => gap(row[`s${position}`]),
    );

const barAccessors = computed(() =>
    accessorsFor(props.series.map((_, position) => position)),
);

/**
 * Une barre ne se pointille pas : au-delà de trois séries, la teinte reprise
 * est éclaircie, comme la pastille de légende (opacité 0,6).
 */
const barColors = computed(() =>
    props.series.map((_, position) =>
        isDashed(position)
            ? `color-mix(in srgb, ${colorFor(position)} 60%, transparent)`
            : colorFor(position),
    ),
);

const x = (row: PenaltyChartRow) => row.index;
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div
                v-for="(one, position) in series"
                :key="one.insurerId ?? one.name"
                class="flex items-center gap-2"
            >
                <span
                    class="h-[3px] w-5 rounded-full"
                    :style="{
                        background: colorFor(position),
                        opacity: isDashed(position) ? 0.6 : 1,
                    }"
                />
                <span class="text-[11px] font-medium text-ink/60">
                    {{ one.name }}
                </span>
            </div>

            <div v-if="total" class="flex items-center gap-2">
                <span class="h-[3px] w-5 rounded-full bg-ink/[0.42]" />
                <span class="text-[11px] font-medium text-ink/60">
                    {{ total.name }}
                </span>
            </div>
        </div>

        <VisXYContainer
            :data="data"
            :height="220"
            class="mt-3 [--vis-axis-tick-label-color:rgb(23_33_28_/_0.45)] [--vis-axis-tick-label-font-size:10px]"
        >
            <VisGroupedBar
                v-if="type === 'bar' && series.length"
                :x="x"
                :y="barAccessors"
                :color="barColors"
                :group-padding="0.18"
                :bar-padding="0.06"
                :rounded-corners="3"
            />

            <template v-if="type !== 'bar'">
                <VisLine
                    v-if="solid.length"
                    :x="x"
                    :y="accessorsFor(solid)"
                    :color="solid.map(colorFor)"
                    :line-width="2"
                />
                <VisLine
                    v-if="dashed.length"
                    :x="x"
                    :y="accessorsFor(dashed)"
                    :color="dashed.map(colorFor)"
                    :line-dash-array="[6, 4]"
                    :line-width="2"
                />
            </template>

            <!-- Le total par-dessus : en barres, il serait sinon caché. -->
            <VisLine
                v-if="total"
                :x="x"
                :y="(row: PenaltyChartRow) => gap(row.total)"
                color="rgb(23 33 28 / 0.42)"
                :line-dash-array="[7, 4]"
                :line-width="1.5"
            />

            <VisAxis
                type="x"
                :tick-format="(index: number) => data[index]?.label ?? ''"
                :grid-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="(value: number) => formatMillions(value)"
                :num-ticks="4"
            />
        </VisXYContainer>
    </div>
</template>
