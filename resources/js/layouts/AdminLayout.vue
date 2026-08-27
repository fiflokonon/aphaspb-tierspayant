<script setup lang="ts">
import { useConsoleShell } from '@/composables/useConsoleShell';
import ConsoleLayout from './console/ConsoleLayout.vue';

const { space, nav, notices } = useConsoleShell();

// Layout props, set by the page through setLayoutProps().
defineProps<{ focus?: boolean }>();
</script>

<template>
    <ConsoleLayout :space="space" :nav="nav" :notices="notices" :focus="focus">
        <slot />
    </ConsoleLayout>
</template>
