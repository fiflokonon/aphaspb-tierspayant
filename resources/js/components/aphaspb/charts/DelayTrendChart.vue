<script setup lang="ts">
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';

type Series = { name: string; points: Record<string, number> };

const props = defineProps<{
    series: Series[];
    network: Record<string, number>;
    threshold: number;
}>();

/**
 * The palette is deliberately narrow — green, gold, terracotta. Past three
 * series colour alone stops separating them, so the fourth onward reuse the
 * same three colours with a dashed stroke.
 */
const COLORS = ['var(--officine)', 'var(--gold-mid)', 'var(--terracotta)'];

const SOLID_LIMIT = COLORS.length;

type Row = {
    index: number;
    label: string;
    network: number | null;
    threshold: number;
    [series: string]: number | string | null;
};

const months = computed(() => {
    const keys = new Set<string>(Object.keys(props.network));

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
            network: props.network[key] ?? null,
            threshold: props.threshold,
        };

        props.series.forEach((one, position) => {
            row[`s${position}`] = one.points[key] ?? null;
        });

        return row;
    }),
);

const isDashed = (position: number) => position >= SOLID_LIMIT;

const colorFor = (position: number) => COLORS[position % COLORS.length];

/**
 * One VisLine per stroke pattern.
 *
 * lineDashArray is a single value per component, not a per-series array: giving
 * it an array dashes every line in that component. Splitting the series across
 * layers is what actually lets some read solid and others dashed.
 */
const solid = computed(() =>
    props.series
        .map((_, position) => position)
        .filter((position) => !isDashed(position)),
);

const dashed = computed(() =>
    props.series
        .map((_, position) => position)
        .filter((position) => isDashed(position)),
);

const accessorsFor = (positions: number[]) =>
    positions.map(
        (position) => (row: Row) => row[`s${position}`] as number | null,
    );

const colorsFor = (positions: number[]) => positions.map(colorFor);

const x = (row: Row) => row.index;
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div
                v-for="(one, position) in props.series"
                :key="one.name"
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

            <div class="flex items-center gap-2">
                <span
                    class="h-[3px] w-5 rounded-full bg-ink/[0.4] opacity-70"
                />
                <span class="text-[11px] font-medium text-ink/60">
                    Moyenne réseau
                </span>
            </div>

            <div class="flex items-center gap-2">
                <span
                    class="h-[3px] w-5 rounded-full bg-ink/[0.38] opacity-60"
                />
                <span class="font-mono text-[10px] text-ink/[0.5]">
                    SEUIL {{ props.threshold }} JOURS
                </span>
            </div>
        </div>

        <VisXYContainer
            :data="data"
            :height="220"
            class="mt-3 [--vis-axis-tick-label-color:rgb(23_33_28_/_0.45)] [--vis-axis-tick-label-font-size:10px]"
        >
            <!-- The reference lines go underneath the measurements. -->
            <VisLine
                :x="x"
                :y="(row: Row) => row.threshold"
                color="rgb(23 33 28 / 0.30)"
                :line-dash-array="[2, 3]"
                :line-width="1.5"
            />
            <VisLine
                :x="x"
                :y="(row: Row) => row.network"
                color="rgb(23 33 28 / 0.42)"
                :line-dash-array="[7, 4]"
                :line-width="1.5"
            />
            <VisLine
                v-if="solid.length"
                :x="x"
                :y="accessorsFor(solid)"
                :color="colorsFor(solid)"
                :line-width="2"
            />
            <VisLine
                v-if="dashed.length"
                :x="x"
                :y="accessorsFor(dashed)"
                :color="colorsFor(dashed)"
                :line-dash-array="[6, 4]"
                :line-width="2"
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
