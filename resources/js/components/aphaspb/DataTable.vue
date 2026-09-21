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
        class="mt-[22px] overflow-hidden rounded-[var(--radius-card)] bg-card shadow-[var(--surface-shadow)]"
    >
        <div
            class="flex flex-col gap-3 border-b border-ink/[0.08] px-4 py-[13px] sm:flex-row sm:items-center sm:gap-[10px]"
        >
            <div class="text-[12.5px] font-bold text-ink">{{ title }}</div>
            <div class="flex gap-[7px] overflow-x-auto sm:ml-auto">
                <slot name="filters" />
            </div>
        </div>

        <div class="overflow-x-auto">
            <div class="min-w-[720px]">
                <div
                    class="bg-cream-header px-4 py-[9px] font-mono text-[9.5px] font-semibold tracking-[0.14em] text-ink/50"
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
            class="border-t border-ink/[0.06] px-4 py-[11px] text-[12.5px] text-ink/[0.45]"
        >
            {{ footer }}
        </div>
    </div>
</template>
