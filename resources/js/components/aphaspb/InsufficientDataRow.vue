<script setup lang="ts">
import DataTableRow from './DataTableRow.vue';

defineProps<{
    template: string;
    label: string;
    explanation: string;
    span: number;
}>();
</script>

<template>
    <DataTableRow :template="template" tone="muted">
        <div class="text-xs font-semibold text-ink/50">{{ label }}</div>
        <div
            class="flex items-center gap-[10px]"
            :style="{ gridColumn: `span ${span}` }"
        >
            <span
                class="rounded-[5px] bg-ink/[0.07] px-2 py-[5px] font-mono text-[10px]/none font-semibold text-ink/[0.55]"
            >
                DONNÉES INSUFFISANTES
            </span>
            <span class="text-[11px] text-ink/50">{{ explanation }}</span>
        </div>
    </DataTableRow>
</template>
