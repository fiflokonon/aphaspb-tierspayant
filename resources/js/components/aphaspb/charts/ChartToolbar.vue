<script setup lang="ts">
/**
 * The controls that sit above a chart block: how to draw it, what to keep, and
 * a way to take it away as an image.
 *
 * The toolbar owns none of the state — the pages keep the type and the filter
 * in the URL, so a shared link reopens the same view.
 */
import { Download } from '@lucide/vue';
import { chartTypeIcon, chartTypeLabel } from '@/types/aphaspb';
import type { ChartType } from '@/types/aphaspb';

withDefaults(
    defineProps<{
        /** Which drawings this block can actually produce. */
        types?: ChartType[];
        exporting?: boolean;
    }>(),
    { types: () => ['bar', 'line', 'pie'], exporting: false },
);

defineEmits<{ export: [] }>();

const model = defineModel<ChartType>({ required: true });
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <slot name="filters" />

        <div
            class="flex items-center gap-0.5 rounded-[10px] border border-input bg-card p-0.5"
            role="group"
            aria-label="Type de graphique"
        >
            <button
                v-for="type in types"
                :key="type"
                type="button"
                class="flex h-[30px] items-center gap-1.5 rounded-lg px-2.5 text-[11.5px] font-semibold transition-colors"
                :class="
                    model === type
                        ? 'bg-ink text-white'
                        : 'text-ink/55 hover:bg-cream-header'
                "
                :aria-pressed="model === type"
                @click="model = type"
            >
                <component
                    :is="chartTypeIcon[type]"
                    class="size-[13px]"
                    :stroke-width="2"
                    aria-hidden="true"
                />
                {{ chartTypeLabel[type] }}
            </button>
        </div>

        <button
            type="button"
            class="flex h-[34px] shrink-0 items-center gap-1.5 rounded-[10px] border border-input bg-card px-3 text-[11.5px] font-semibold text-ink/65 transition-colors hover:bg-cream-header disabled:opacity-50"
            :disabled="exporting"
            @click="$emit('export')"
        >
            <Download
                class="size-[13px]"
                :stroke-width="2"
                aria-hidden="true"
            />
            {{ exporting ? 'Export…' : 'PNG' }}
        </button>
    </div>
</template>
