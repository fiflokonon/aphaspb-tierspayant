<script setup lang="ts">
import type { ConsoleNavItem, ConsoleNotice } from '@/types/console';
import ConsoleSidebar from './ConsoleSidebar.vue';

defineProps<{
    space?: string | null;
    nav: ConsoleNavItem[];
    notices?: ConsoleNotice[];
    /**
     * Give the page the whole phone screen: navigation and notices step aside
     * below lg. The declaration is designed as a focused flow — burying its
     * first field under 230 px of chrome defeats « déclarer en une minute ».
     * Desktop keeps the rail, where it costs nothing.
     */
    focus?: boolean;
}>();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-cream lg:flex-row">
        <ConsoleSidebar
            :space="space ?? null"
            :nav="nav"
            :notices="notices ?? []"
            :class="focus ? 'hidden lg:flex' : ''"
        />
        <main class="min-w-0 flex-1 px-4 pt-5 pb-7 sm:px-[26px] sm:pt-6">
            <slot />
        </main>
    </div>
</template>
