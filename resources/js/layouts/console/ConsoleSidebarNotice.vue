<script setup lang="ts">
import { computed } from 'vue';
import type { ConsoleNoticeTone } from '@/types/console';

const props = defineProps<{
    tone: ConsoleNoticeTone;
    title: string;
    body: string;
}>();

const isGold = computed(() => props.tone === 'gold');
</script>

<template>
    <div
        class="rounded-[10px] border p-[13px]"
        :class="
            isGold
                ? 'border-gold/30 bg-gold/[0.14]'
                : 'border-white/[0.14] bg-white/[0.07]'
        "
    >
        <div
            class="text-[11px]/[1.3] font-bold"
            :class="isGold ? 'text-gold' : 'text-black'"
        >
            {{ title }}
        </div>
        <div class="mt-[5px] text-[11px]/[1.45]">
            {{ body }}
        </div>
    </div>
</template>
