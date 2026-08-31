<script setup lang="ts">
/**
 * The invoiced-versus-collected journey drawn as two curves.
 *
 * The stacked bars answer « how much is still owed this month »; the curves
 * answer « is the gap widening ». Same figures, two questions, so the reader
 * picks rather than the screen deciding for them.
 */
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
import { toMillions } from '@/lib/millions';

type Point = {
    key: string;
    label: string;
    invoiced: number;
    received: number;
    outstanding: number;
    isCurrent: boolean;
};

const props = defineProps<{ points: Point[] }>();

const x = (_: Point, index: number) => index;

const y = [
    (point: Point) => point.invoiced / 1_000_000,
    (point: Point) => point.received / 1_000_000,
];

const colors = ['rgb(23 33 28 / 0.32)', 'var(--officine)'];

const tickFormat = (index: number) => props.points[index]?.label ?? '';
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="h-[3px] w-5 rounded-full bg-ink/[0.32]" />
                <span class="text-[11px] font-medium text-ink/60">
                    Facturé
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="h-[3px] w-5 rounded-full bg-officine" />
                <span class="text-[11px] font-medium text-ink/60">
                    Encaissé
                </span>
            </div>
        </div>

        <VisXYContainer
            :data="points"
            :height="200"
            class="mt-3 [--vis-axis-tick-label-color:rgb(23_33_28_/_0.45)] [--vis-axis-tick-label-font-size:10px]"
        >
            <VisLine :x="x" :y="y" :color="colors" :line-width="2" />

            <VisAxis type="x" :tick-format="tickFormat" :grid-line="false" />
            <VisAxis
                type="y"
                :tick-format="(value: number) => toMillions(value, 0)"
                :num-ticks="4"
            />
        </VisXYContainer>
    </div>
</template>
