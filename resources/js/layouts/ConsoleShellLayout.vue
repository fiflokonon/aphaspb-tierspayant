<script setup lang="ts">
import { useConsoleShell } from '@/composables/useConsoleShell';
import ConsoleLayout from './console/ConsoleLayout.vue';

const { space, nav, notices, account, notificationCount } = useConsoleShell();

// Layout props, set by the page through setLayoutProps().
defineProps<{ focus?: boolean }>();
</script>

<template>
    <ConsoleLayout
        :space="space"
        :nav="nav"
        :notices="notices"
        :account="account"
        :focus="focus"
        :notification-count="notificationCount"
        notifications-href="/notifications"
    >
        <slot />
    </ConsoleLayout>
</template>
