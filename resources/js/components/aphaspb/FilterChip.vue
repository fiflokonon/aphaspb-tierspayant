<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        size?: 'default' | 'compact';
    }>(),
    { size: 'default' },
);
</script>

<template>
    <button
        type="button"
        class="flex items-center justify-center rounded-[10px] border border-input bg-card font-medium text-ink/70 transition-colors hover:bg-cream-header"
        :class="
            size === 'compact'
                ? 'h-[32px] rounded-lg px-[11px] text-[11.5px] text-ink/[0.65]'
                : 'h-[42px] px-[13px] text-xs'
        "
    >
        {{ label }} ▾
    </button>
</template>
