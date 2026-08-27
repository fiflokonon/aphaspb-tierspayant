<script setup lang="ts">
import { kpiToneClass, kpiToneFill } from '@/types/aphaspb';
import type { KpiTone } from '@/types/aphaspb';

const props = defineProps<{
    share: number;
    tone: KpiTone;
    label: string;
}>();
</script>

<template>
    <div class="flex items-center gap-[7px]">
        <div class="h-[5px] max-w-[52px] flex-1 rounded-full bg-ink/[0.08]">
            <div
                class="h-full rounded-full"
                :class="kpiToneFill[props.tone]"
                :style="{ width: `${Math.min(100, Math.max(0, share))}%` }"
            />
        </div>
        <span :class="kpiToneClass[props.tone]">{{ label }}</span>
    </div>
</template>
