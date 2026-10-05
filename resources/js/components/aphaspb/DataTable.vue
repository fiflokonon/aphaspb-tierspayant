<script setup lang="ts">
defineProps<{
    title: string;
    columns: string[];
    template: string;
    footer?: string;
}>();
</script>

<template>
    <div
        class="overflow-hidden rounded-[var(--radius-card)] border border-ink/[0.06] bg-card shadow-[var(--surface-shadow)]"
    >
        <div
            class="flex flex-col gap-3 border-b border-ink/[0.06] bg-white/80 px-5 sm:flex-row sm:items-center sm:gap-[10px]"
        >
            <div class="text-[12.5px] font-bold text-ink">{{ title }}</div>
            <div class="flex gap-[7px] overflow-x-auto sm:ml-auto">
                <slot name="filters" />
            </div>
        </div>

        <div class="overflow-x-auto">
            <div class="min-w-[720px]">
                <div
                    class="bg-cream-state px-5 py-[10px] text-[11px] font-semibold tracking-[0.14em]"
                    :style="{
                        display: 'grid',
                        gridTemplateColumns: template,
                        gap: '14px',
                    }"
                >
                    <div v-for="column in columns" :key="column">
                        {{ column }}
                    </div>
                </div>

                <slot />
            </div>
        </div>

        <div
            v-if="footer"
            class="border-t border-ink/[0.06] bg-white/60 px-5 py-[12px] text-[12.5px] text-ink/[0.45]"
        >
            {{ footer }}
        </div>
    </div>
</template>
