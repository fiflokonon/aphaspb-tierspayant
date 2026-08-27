<script setup lang="ts">
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';

type Series = { name: string; points: Record<string, number> };

const props = defineProps<{
    series: Series[];
    network: Record<string, number>;
    threshold: number;
}>();

const COLORS = [
    'var(--officine)',
    'var(--gold-mid)',
    'var(--terracotta)',
    'var(--officine-dark)',
    'var(--gold-dark)',
];

/** Every month any series covers, in order. */
const months = computed(() => {
    const keys = new Set<string>(Object.keys(props.network));

    props.series.forEach((one) =>
        Object.keys(one.points).forEach((key) => keys.add(key)),
    );

    return [...keys].sort();
});

type Row = {
    index: number;
    label: string;
    network: number | null;
    threshold: number;
    /** One entry per series, keyed s0, s1, … */
    [series: string]: number | string | null;
};

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

const accessors = computed(() => [
    ...props.series.map(
        (_, position) => (row: Row) => row[`s${position}`] as number | null,
    ),
    (row: Row) => row.network as number | null,
    (row: Row) => row.threshold as number,
]);

const colors = computed(() => [
    ...props.series.map((_, position) => COLORS[position % COLORS.length]),
    'rgb(23 33 28 / 0.35)',
    'rgb(23 33 28 / 0.22)',
]);
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
                    class="h-[3px] w-4 rounded-full"
                    :style="{ background: COLORS[position % COLORS.length] }"
                />
                <span class="text-[11px] font-medium text-ink/60">
                    {{ one.name }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="h-[3px] w-4 rounded-full bg-ink/[0.35]" />
                <span class="text-[11px] font-medium text-ink/60">
                    Moyenne réseau
                </span>
            </div>
            <div class="ml-auto font-mono text-[10px] text-ink/[0.45]">
                SEUIL {{ props.threshold }} JOURS
            </div>
        </div>

        <VisXYContainer
            :data="data"
            :height="220"
            class="mt-3 [--vis-axis-tick-label-color:rgb(23_33_28_/_0.45)] [--vis-axis-tick-label-font-size:10px]"
        >
            <VisLine
                :x="(row: Row) => row.index"
                :y="accessors"
                :color="colors"
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
