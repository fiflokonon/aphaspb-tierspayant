<script setup lang="ts">
import { rowToneClass } from '@/types/aphaspb';
import type { DataTableRowTone } from '@/types/aphaspb';

const props = withDefaults(
    defineProps<{
        template: string;
        tone?: DataTableRowTone;
    }>(),
    { tone: 'default' },
);
</script>

<template>
    <div
        class="items-center border-t border-ink/[0.06] px-4 py-3 text-xs font-semibold text-ink"
        :class="rowToneClass[props.tone]"
        :style="{ display: 'grid', gridTemplateColumns: template, gap: '14px' }"
    >
        <slot />
    </div>
</template>
