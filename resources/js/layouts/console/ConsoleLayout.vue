<script setup lang="ts">
import type {
    ConsoleAccount,
    ConsoleNavItem,
    ConsoleNotice,
} from '@/types/console';
import ConsoleSidebar from './ConsoleSidebar.vue';

defineProps<{
    space?: string | null;
    nav: ConsoleNavItem[];
    notices?: ConsoleNotice[];
    account?: ConsoleAccount | null;
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
    <div class="app-shell">
        <!-- =====================================================
             SIDEBAR
        ====================================================== -->
        <ConsoleSidebar
            :space="space ?? null"
            :nav="nav"
            :notices="notices ?? []"
            :account="account ?? null"
            :class="focus ? 'hidden lg:flex' : ''"
        />

        <!-- =====================================================
             CONTENU PRINCIPAL
        ====================================================== -->
        <main class="app-content">
            <slot />
        </main>
    </div>
</template>

<style scoped>
/* =============================================================
   APhaSPB — GLOBAL APPLICATION SHELL
============================================================= */

.app-shell {
    position: relative;
    display: flex;
    min-height: 100vh;
    width: 100%;

    /*
     * Fond général de l'application.
     * Même ambiance que les pages Statistiques réseau :
     * blanc cassé + halo turquoise très léger.
     */
    background:
        radial-gradient(
            circle at 95% 0%,
            rgba(0, 143, 131, 0.045),
            transparent 28%
        ),
        #f7f9f9;

    color: #243333;
}

/* =============================================================
   CONTENU
============================================================= */

.app-content {
    position: relative;
    z-index: 1;

    min-width: 0;
    flex: 1;

    padding: 20px 16px 28px;

    /*
     * Petit effet de profondeur très subtil.
     */
    background: radial-gradient(
        circle at 100% 0%,
        rgba(0, 143, 131, 0.025),
        transparent 24%
    );
}

/* =============================================================
   TABLETTE
============================================================= */

@media (min-width: 640px) {
    .app-content {
        padding: 24px 26px 32px;
    }
}

/* =============================================================
   DESKTOP
============================================================= */

@media (min-width: 1024px) {
    .app-content {
        padding: 26px 30px 38px;
    }
}

/* =============================================================
   GRAND ÉCRAN
============================================================= */

@media (min-width: 1280px) {
    .app-content {
        padding: 28px 36px 42px;
    }
}

/* =============================================================
   PETITS ÉCRANS
============================================================= */

@media (max-width: 639px) {
    .app-shell {
        min-height: 100svh;
    }

    .app-content {
        padding: 16px 14px 28px;
    }
}

/* =============================================================
   ACCESSIBILITÉ
============================================================= */

@media (prefers-reduced-motion: reduce) {
    .app-shell *,
    .app-shell *::before,
    .app-shell *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
