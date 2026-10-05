<script setup lang="ts">
import type { ConsoleAccount, ConsoleNavItem } from '@/types/console';
import ConsoleSidebar from './ConsoleSidebar.vue';
import ConsoleTopBar from './ConsoleTopBar.vue';

defineProps<{
    space?: string | null;
    nav: ConsoleNavItem[];
    account?: ConsoleAccount | null;
    /**
     * Give the page the whole phone screen: the navigation steps aside
     * below lg. The declaration is designed as a focused flow — burying its
     * first field under 230 px of chrome defeats « déclarer en une minute ».
     * Desktop keeps the rail, where it costs nothing.
     */
    focus?: boolean;
    notificationCount?: number;
    notificationsHref?: string;
}>();
</script>

<template>
    <div class="app-shell">
        <!-- =====================================================
             SIDEBAR
        ====================================================== -->
        <ConsoleSidebar
            :space="space ?? null"
            :nav="nav"
            :account="account ?? null"
            :notification-count="notificationCount ?? 0"
            :notifications-href="notificationsHref ?? '/notifications'"
            :class="focus ? 'hidden lg:flex' : ''"
        />

        <!-- =====================================================
             CONTENU PRINCIPAL
        ====================================================== -->
        <main class="app-content">
            <ConsoleTopBar
                :count="notificationCount ?? 0"
                :href="notificationsHref ?? '/notifications'"
                :account="account ?? null"
            />

            <slot />
        </main>
    </div>
</template>

<style scoped>
.app-shell {
    position: relative;
    display: flex;
    min-height: 100vh;
    width: 100%;
    background: transparent;
    color: var(--ink);
}

.app-content {
    position: relative;
    z-index: 1;
    min-width: 0;
    flex: 1;
    padding: 18px 18px 36px;
}

.app-content::before {
    content: '';
    position: fixed;
    z-index: -1;
    inset: 0 0 0 236px;
    pointer-events: none;
    background: linear-gradient(180deg, rgb(255 255 255 / 0.22), transparent 22%);
}

@media (min-width: 640px) {
    .app-content { padding: 22px 24px 42px; }
}

@media (min-width: 1024px) {
    .app-content { padding: 18px 30px 46px; }
}

@media (min-width: 1280px) {
    .app-content { padding: 20px 38px 52px; }
}

@media (max-width: 1023px) {
    .app-shell { flex-direction: column; }
    .app-content::before { inset: 0; }
}

@media (max-width: 639px) {
    .app-shell { min-height: 100svh; }
    .app-content { padding: 14px 12px 28px; }
}
</style>
