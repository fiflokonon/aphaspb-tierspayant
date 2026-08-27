<script setup lang="ts">
import { statusChipClass } from '@/types/aphaspb';
import type { DeclarationStatus } from '@/types/aphaspb';

const props = defineProps<{
    status: DeclarationStatus;
    label: string;
}>();
</script>

<template>
    <span
        class="rounded-[5px] px-[7px] py-[5px] font-mono text-[10.5px]/none font-semibold uppercase"
        :class="statusChipClass[props.status]"
    >
        {{ label }}
    </span>
</template>
