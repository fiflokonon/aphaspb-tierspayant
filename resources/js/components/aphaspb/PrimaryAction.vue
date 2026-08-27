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
        class="flex h-[42px] items-center justify-center rounded-[10px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground transition-colors hover:bg-officine-dark"
    >
        {{ label }}
    </Link>
    <button
        v-else
        type="button"
        class="flex h-[42px] items-center justify-center rounded-[10px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground transition-colors hover:bg-officine-dark"
    >
        {{ label }}
    </button>
</template>
