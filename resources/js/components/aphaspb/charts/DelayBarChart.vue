<script setup lang="ts">
/**
 * The same monthly delays as DelayTrendChart, drawn as grouped bars.
 *
 * Bars read better than curves once the view is narrowed to one insurer: a
 * single line over twelve points invites the eye to interpolate between months
 * that have no relation to each other. With every insurer shown at once the
 * curve stays the better tool, which is why both live side by side.
 *
 * Months an insurer did not settle stay absent rather than being drawn at zero:
 * a zero-day delay is a claim, and « no data » is not one.
 */
import { VisAxis, VisGroupedBar, VisLine, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import { CHART_COLORS } from '@/types/aphaspb';

type Series = { name: string; points: Record<string, number> };

const props = defineProps<{
    series: Series[];
    threshold: number;
}>();

type Row = {
    index: number;
    label: string;
    threshold: number;
    [series: string]: number | string | null;
};

const months = computed(() => {
    const keys = new Set<string>();

    props.series.forEach((one) =>
        Object.keys(one.points).forEach((key) => keys.add(key)),
    );

    return [...keys].sort();
});

const data = computed<Row[]>(() =>
    months.value.map((key, index) => {
        const row: Row = {
            index,
            label: key.slice(5) + '/' + key.slice(2, 4),
            threshold: props.threshold,
        };

        props.series.forEach((one, position) => {
            row[`s${position}`] = one.points[key] ?? null;
        });

        return row;
    }),
);

const colorFor = (position: number) =>
    CHART_COLORS[position % CHART_COLORS.length];

const accessors = computed(() =>
    props.series.map(
        (_, position) => (row: Row) => row[`s${position}`] as number | null,
    ),
);

const colors = computed(() => props.series.map((_, index) => colorFor(index)));

const x = (row: Row) => row.index;
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div
                v-for="(one, position) in series"
                :key="one.name"
                class="flex items-center gap-2"
            >
                <span
                    class="size-[10px] rounded-sm"
                    :style="{ background: colorFor(position) }"
                />
                <span class="text-[11px] font-medium text-ink/60">
                    {{ one.name }}
                </span>
            </div>

            <div class="flex items-center gap-2">
                <span class="h-[3px] w-5 rounded-full bg-ink/[0.38]" />
                <span class="font-mono text-[10px] text-ink/[0.5]">
                    SEUIL {{ threshold }} JOURS
                </span>
            </div>
        </div>

        <VisXYContainer
            :data="data"
            :height="220"
            class="mt-3 [--vis-axis-tick-label-color:rgb(23_33_28_/_0.45)] [--vis-axis-tick-label-font-size:10px]"
        >
            <VisGroupedBar
                :x="x"
                :y="accessors"
                :color="colors"
                :group-padding="0.18"
                :bar-padding="0.06"
                :rounded-corners="3"
            />

            <!-- The reference sits on top here: bars would otherwise hide it. -->
            <VisLine
                :x="x"
                :y="(row: Row) => row.threshold"
                color="rgb(23 33 28 / 0.38)"
                :line-dash-array="[2, 3]"
                :line-width="1.5"
            />

            <VisAxis
                type="x"
                :tick-format="(index: number) => data[index]?.label ?? ''"
                :grid-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="(value: number) => `${value} j`"
                :num-ticks="4"
            />
        </VisXYContainer>
    </div>
</template>
