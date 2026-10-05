<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    label: string;
    href?: string;
}>();
</script>

<template>
    <Link
        v-if="href"
        :href="href"
        class="flex h-[42px] items-center justify-center rounded-[12px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground shadow-[0_8px_20px_-12px_rgb(15_143_120_/_0.8)] transition-all hover:-translate-y-0.5 hover:bg-officine-dark hover:shadow-[0_12px_24px_-12px_rgb(15_143_120_/_0.9)]"
    >
        {{ label }}
    </Link>
    <button
        v-else
        type="button"
        class="flex h-[42px] items-center justify-center rounded-[12px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground shadow-[0_8px_20px_-12px_rgb(15_143_120_/_0.8)] transition-all hover:-translate-y-0.5 hover:bg-officine-dark hover:shadow-[0_12px_24px_-12px_rgb(15_143_120_/_0.9)]"
    >
        {{ label }}
    </button>
</template>
