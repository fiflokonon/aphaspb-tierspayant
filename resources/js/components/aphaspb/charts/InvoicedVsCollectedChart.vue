<script setup lang="ts">
import { VisAxis, VisStackedBar, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import { toMillions } from '@/lib/millions';

type Point = {
    key: string;
    label: string;
    invoiced: number;
    received: number;
    outstanding: number;
    isCurrent: boolean;
};

const props = defineProps<{
    points: Point[];
}>();

/**
 * Collected and still-outstanding are stacked rather than drawn side by side:
 * the gap between the two *is* the outstanding balance, which is the whole
 * point of the artboard.
 */
const y = [
    (point: Point) => point.received / 1_000_000,
    (point: Point) => point.outstanding / 1_000_000,
];

const x = (_: Point, index: number) => index;

const tickFormat = (index: number) => props.points[index]?.label ?? '';

const highest = computed(() =>
    Math.max(1, ...props.points.map((point) => point.invoiced / 1_000_000)),
);
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="size-[10px] rounded-sm bg-officine" />
                <span class="text-[11px] font-medium text-ink/60"
                    >Encaissé</span
                >
            </div>
            <div class="flex items-center gap-2">
                <span class="size-[10px] rounded-sm bg-ink/[0.16]" />
                <span class="text-[11px] font-medium text-ink/60">
                    Reste à recouvrer
                </span>
            </div>
        </div>

        <VisXYContainer
            :data="props.points"
            :height="200"
            class="mt-3 [--vis-axis-tick-label-color:rgb(23_33_28_/_0.45)] [--vis-axis-tick-label-font-size:10px]"
        >
            <VisStackedBar
                :x="x"
                :y="y"
                :color="['var(--officine)', 'rgb(23 33 28 / 0.16)']"
                :bar-padding="0.28"
                :rounded-corners="3"
            />
            <!--
                One tick per month: left to itself the axis drops all but two
                labels on a phone, and « S … J » says nothing about which month
                a bar belongs to.
            -->
            <VisAxis
                type="x"
                :tick-format="tickFormat"
                :num-ticks="props.points.length"
                :tick-values="props.points.map((_, index) => index)"
                :grid-line="false"
            />
            <VisAxis
                type="y"
                :tick-format="
                    (value: number) => toMillions(value * 1_000_000, 0)
                "
                :num-ticks="4"
            />
        </VisXYContainer>

        <p class="mt-2 font-mono text-[10px] text-ink/[0.45]">
            EN MILLIONS DE FCFA · MAXIMUM
            {{ toMillions(highest * 1_000_000, 1) }} M
        </p>
    </div>
</template>
