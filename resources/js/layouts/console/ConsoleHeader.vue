<script setup lang="ts">
defineProps<{
    eyebrow: string;
    title: string;
}>();
</script>

<template>
    <div class="flex items-end justify-between gap-4">
        <div>
            <div
                class="font-mono text-[10.5px]/none font-semibold tracking-[0.06em] text-ink/[0.45]"
            >
                {{ eyebrow }}
            </div>
            <div class="mt-2 text-[22px]/[1.2] font-bold text-ink">
                <slot name="title">{{ title }}</slot>
            </div>
        </div>
        <div class="flex gap-2">
            <slot name="filters" />
            <slot name="action" />
        </div>
    </div>
</template>
