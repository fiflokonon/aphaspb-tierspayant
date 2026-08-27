<script setup lang="ts">
import { kpiToneClass, kpiToneFill } from '@/types/aphaspb';
import type { KpiTone } from '@/types/aphaspb';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string;
        unit?: string;
        hint?: string;
        tone?: KpiTone;
        progress?: number;
    }>(),
    { tone: 'neutral' },
);
</script>

<template>
    <div class="rounded-[11px] border border-border bg-card px-4 py-[15px]">
        <div class="font-mono text-[10.5px]/none font-semibold text-ink/[0.45]">
            {{ label }}
        </div>
        <div class="mt-[9px] flex items-baseline gap-[6px]">
            <div
                class="text-[28px]/none font-extrabold"
                :class="kpiToneClass[props.tone]"
            >
                {{ value }}
            </div>
            <div v-if="unit" class="text-xs font-medium text-ink/50">
                {{ unit }}
            </div>
        </div>
        <div
            v-if="progress !== undefined"
            class="mt-[11px] h-[5px] rounded-full bg-ink/[0.08]"
        >
            <div
                class="h-full rounded-full"
                :class="kpiToneFill[props.tone]"
                :style="{ width: `${Math.min(100, Math.max(0, progress))}%` }"
            />
        </div>
        <div
            v-else-if="hint || $slots.hint"
            class="mt-[11px] text-[11px]/[1.4] text-ink/50"
        >
            <slot name="hint">{{ hint }}</slot>
        </div>
    </div>
</template>
