<script setup lang="ts">
defineProps<{
    label: string;
    hint?: string;
    error?: string;
}>();
</script>

<template>
    <div>
        <label
            class="block font-mono text-[10.5px]/none font-semibold tracking-[0.05em] text-ink/[0.45]"
        >
            {{ label }}
        </label>
        <div class="mt-[6px]">
            <slot />
        </div>
        <p v-if="error" class="mt-[5px] text-[11px]/[1.4] text-terracotta-dark">
            {{ error }}
        </p>
        <p v-else-if="hint" class="mt-[5px] text-[11px]/[1.4] text-ink/[0.45]">
            {{ hint }}
        </p>
    </div>
</template>
