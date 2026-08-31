<script setup lang="ts">
/**
 * How the outstanding balance splits between insurers.
 *
 * A share at a point in time, not a series: the twelve-month window is summed
 * before it gets here. That is why the block's title has to change with the
 * chart type — the same card would otherwise promise an evolution and draw a
 * distribution.
 */
import { VisDonut, VisSingleContainer } from '@unovis/vue';
import { computed } from 'vue';
import { sliceTotal } from '@/lib/donut';
import type { RankedSlice } from '@/lib/donut';
import { formatMillions } from '@/lib/millions';

const props = withDefaults(
    defineProps<{
        /** Already ranked and coloured by rankSlices(). */
        slices: RankedSlice[];
        height?: number;
    }>(),
    { height: 220 },
);

const total = computed(() => sliceTotal(props.slices));

const value = (slice: RankedSlice) => slice.value;

const color = (slice: RankedSlice) => slice.color;
</script>

<template>
    <div>
        <div v-if="total === 0" class="py-10 text-center">
            <p class="text-[12.5px] text-ink/45">
                Rien à recouvrer sur la période — pas de répartition à tracer.
            </p>
        </div>

        <div v-else class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <!--
                La largeur vit sur ce div, pas sur VisSingleContainer : unovis
                dimensionne son propre conteneur et la classe ne le contraint
                pas. Posée sur le composant, le beignet s'étalait sur toute la
                ligne et poussait la légende hors du cadre.
            -->
            <div class="w-full shrink-0 sm:w-[240px]">
                <VisSingleContainer :data="slices" :height="height">
                    <VisDonut
                        :value="value"
                        :color="color"
                        :arc-width="34"
                        :pad-angle="0.02"
                        :corner-radius="3"
                        :central-label="formatMillions(total)"
                        central-sub-label="Encours"
                    />
                </VisSingleContainer>
            </div>

            <ul class="min-w-0 flex-1 space-y-[7px]">
                <li
                    v-for="slice in slices"
                    :key="slice.label"
                    class="flex items-center gap-2"
                >
                    <span
                        class="size-[10px] shrink-0 rounded-sm"
                        :style="{ background: slice.color }"
                    />

                    <span
                        class="min-w-0 flex-1 truncate text-[12px] text-ink/70"
                    >
                        {{ slice.label }}
                    </span>

                    <span class="font-mono text-[11px] text-ink/50">
                        {{ formatMillions(slice.value) }}
                    </span>

                    <span
                        class="w-9 text-right font-mono text-[11.5px] font-semibold text-ink"
                    >
                        {{ slice.share }} %
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
