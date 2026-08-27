<script setup lang="ts">
import type { ConsoleNavItem, ConsoleNotice } from '@/types/console';
import ConsoleSidebar from './ConsoleSidebar.vue';

defineProps<{
    space?: string | null;
    nav: ConsoleNavItem[];
    notices?: ConsoleNotice[];
}>();
</script>

<template>
    <div class="flex min-h-screen bg-cream">
        <ConsoleSidebar
            :space="space ?? null"
            :nav="nav"
            :notices="notices ?? []"
        />
        <main class="min-w-0 flex-1 px-[26px] pt-6 pb-7">
            <slot />
        </main>
    </div>
</template>
