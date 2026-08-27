<script setup lang="ts">
import { useConsoleShell } from '@/composables/useConsoleShell';
import ConsoleLayout from './console/ConsoleLayout.vue';

const { space, nav, notices } = useConsoleShell();
</script>

<template>
    <ConsoleLayout :space="space" :nav="nav" :notices="notices">
        <slot />
    </ConsoleLayout>
</template>
