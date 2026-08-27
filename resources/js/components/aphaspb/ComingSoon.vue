<script setup lang="ts">
defineProps<{
    title: string;
    body: string;
    increment: string;
}>();
</script>

<template>
    <div
        class="rounded-[11px] border border-border bg-card px-[26px] py-[30px]"
    >
        <div
            class="font-mono text-[10px] font-semibold tracking-[0.04em] text-ink/[0.45]"
        >
            {{ increment }}
        </div>
        <h1 class="mt-3 text-[22px]/[1.2] font-bold text-ink">
            {{ title }}
        </h1>
        <p class="mt-3 max-w-[52ch] text-[13px]/[1.5] text-ink/60">
            {{ body }}
        </p>
    </div>
</template>
