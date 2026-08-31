<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type {
    ConsoleAccount,
    ConsoleNavItem,
    ConsoleNotice,
} from '@/types/console';
import ConsoleAccountFooter from './ConsoleAccountFooter.vue';
import ConsoleAccountMenu from './ConsoleAccountMenu.vue';
import ConsoleBell from './ConsoleBell.vue';
import ConsoleSidebarNotice from './ConsoleSidebarNotice.vue';

defineProps<{
    space: string | null;
    nav: ConsoleNavItem[];
    notices: ConsoleNotice[];
    account: ConsoleAccount | null;
    notificationCount: number;
    notificationsHref: string;
}>();
</script>

<template>
    <aside class="apha-sidebar">
        <div class="sidebar-glow"></div>

        <div class="apha-sidebar-header">
            <Link :href="nav?.[0]?.href ?? '#'" class="apha-brand">
                <div class="apha-logo-wrap">
                    <img
                        src="/logo-aphaspb.webp"
                        alt="APhaSPB"
                        class="apha-logo"
                    />
                </div>

                <div class="apha-brand-content">
                    <span class="apha-brand-name"> APhaSPB </span>

                    <span class="apha-brand-subtitle">
                        Réseau des officines
                    </span>
                </div>
            </Link>

            <div v-if="space" class="apha-space">
                <span class="space-dot"></span>

                {{ space }}
            </div>

            <!--
                Sous lg, cette barre EST le bandeau supérieur : la cloche et le
                compte s'y posent plutôt que dans un second bandeau. Au-dessus,
                la cloche vit dans ConsoleTopBar et le compte dans le pied du
                rail, qui reste inchangé.
            -->
            <div class="apha-header-actions">
                <ConsoleBell
                    :count="notificationCount"
                    :href="notificationsHref"
                />

                <ConsoleAccountMenu v-if="account" :account="account" />
            </div>
        </div>

        <div class="apha-navigation">
            <div class="apha-section-label">NAVIGATION</div>

            <nav class="apha-nav">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    prefetch
                    class="apha-nav-item"
                    :class="{
                        active: item.active,
                    }"
                >
                    <span class="apha-nav-icon">
                        <span class="apha-nav-dot"></span>
                    </span>

                    <span class="apha-nav-label">
                        {{ item.label }}
                    </span>

                    <span v-if="item.active" class="apha-nav-arrow"> → </span>
                </Link>
            </nav>
        </div>

        <div v-if="notices.length" class="apha-notices">
            <div class="apha-section-label notice-label">INFORMATIONS</div>

            <div class="apha-notices-list">
                <ConsoleSidebarNotice
                    v-for="notice in notices"
                    :key="notice.title"
                    v-bind="notice"
                />
            </div>
        </div>

        <div class="apha-sidebar-footer">
            <div class="apha-footer-line"></div>

            <ConsoleAccountFooter v-if="account" :account="account" />

            <div class="apha-footer-status">
                <span class="apha-status-dot"></span>

                <span> Plateforme opérationnelle </span>
            </div>
        </div>
    </aside>
</template>

<style scoped>
.apha-sidebar {
    --primary: #008f83;
    --primary-dark: #006f68;
    --primary-soft: rgba(0, 143, 131, 0.09);

    --gold: #d7a33d;
    --gold-soft: rgba(215, 163, 61, 0.1);

    --ink: #243333;
    --muted: #788585;
    --light: #a2adad;

    --background: #ffffff;
    --background-soft: #f7faf9;

    --border: #e7eceb;

    position: sticky;

    top: 0;

    z-index: 30;

    display: flex;

    flex-direction: column;

    width: 212px;

    min-width: 212px;

    height: 100vh;

    min-height: 100vh;

    flex-shrink: 0;

    padding: 15px 11px 11px;

    background:
        radial-gradient(
            circle at 100% 0%,
            rgba(0, 143, 131, 0.075),
            transparent 34%
        ),
        linear-gradient(180deg, #ffffff 0%, #fbfdfc 100%);

    color: var(--ink);

    border-right: 1px solid var(--border);

    box-shadow: 8px 0 30px rgba(35, 70, 68, 0.035);

    overflow: hidden;
}

.sidebar-glow {
    position: absolute;

    top: -80px;

    right: -80px;

    width: 160px;

    height: 160px;

    border-radius: 50%;

    background: radial-gradient(
        circle,
        rgba(0, 143, 131, 0.09),
        transparent 68%
    );

    pointer-events: none;
}

.apha-sidebar::before {
    content: '';

    position: absolute;

    left: 0;

    top: 18px;

    bottom: 18px;

    width: 3px;

    background: linear-gradient(
        180deg,
        var(--primary),
        rgba(0, 143, 131, 0.15),
        var(--gold)
    );

    border-radius: 0 4px 4px 0;

    opacity: 0.75;
}

.apha-sidebar-header {
    position: relative;

    z-index: 2;

    flex-shrink: 0;

    padding: 2px 4px 12px;

    border-bottom: 1px solid rgba(35, 70, 68, 0.07);
}

.apha-brand {
    display: flex;

    align-items: center;

    gap: 9px;

    text-decoration: none;

    transition:
        transform 0.25s ease,
        opacity 0.25s ease;
}

.apha-brand:hover {
    transform: translateX(2px);
}

.apha-logo-wrap {
    position: relative;

    width: 34px;

    height: 34px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 11px;

    background: linear-gradient(135deg, #e8f6f3, #ffffff);

    border: 1px solid rgba(0, 143, 131, 0.12);

    box-shadow: 0 5px 14px rgba(0, 143, 131, 0.08);

    transition:
        transform 0.3s ease,
        box-shadow 0.3s ease;
}

.apha-brand:hover .apha-logo-wrap {
    transform: rotate(-4deg) scale(1.05);

    box-shadow: 0 8px 18px rgba(0, 143, 131, 0.14);
}

.apha-logo {
    width: 25px;

    height: 25px;

    object-fit: contain;

    border-radius: 50%;
}

.apha-brand-content {
    min-width: 0;

    display: flex;

    flex-direction: column;

    gap: 1px;
}

.apha-brand-name {
    color: var(--ink);

    font-size: 13px;

    font-weight: 800;

    letter-spacing: -0.02em;
}

.apha-brand-subtitle {
    color: var(--light);

    font-size: 8px;

    font-weight: 600;
}

.apha-space {
    display: flex;

    align-items: center;

    gap: 6px;

    margin-top: 9px;

    padding-left: 43px;

    color: var(--primary);

    font-family: 'JetBrains Mono', monospace;

    font-size: 8px;

    font-weight: 700;

    letter-spacing: 0.08em;

    text-transform: uppercase;
}

.space-dot {
    width: 5px;

    height: 5px;

    border-radius: 50%;

    background: var(--gold);

    box-shadow: 0 0 0 3px rgba(215, 163, 61, 0.08);
}

.apha-navigation {
    position: relative;

    z-index: 2;

    flex: 1 1 auto;

    min-height: 0;

    display: flex;

    flex-direction: column;

    padding-top: 14px;

    overflow: hidden;
}

.apha-section-label {
    flex-shrink: 0;

    margin: 0 5px 7px;

    color: var(--light);

    font-size: 7px;

    font-weight: 800;

    letter-spacing: 0.14em;

    text-transform: uppercase;
}

.apha-nav {
    display: flex;

    flex-direction: column;

    gap: 3px;

    min-height: 0;

    overflow-y: auto;

    padding: 0 2px 2px;

    scrollbar-width: thin;

    scrollbar-color: rgba(0, 143, 131, 0.14) transparent;
}

.apha-nav::-webkit-scrollbar {
    width: 3px;
}

.apha-nav::-webkit-scrollbar-track {
    background: transparent;
}

.apha-nav::-webkit-scrollbar-thumb {
    background: rgba(0, 143, 131, 0.14);

    border-radius: 10px;
}

.apha-nav-item {
    position: relative;

    display: flex;

    align-items: center;

    min-height: 36px;

    gap: 9px;

    padding: 7px 9px;

    border-radius: 10px;

    color: var(--muted);

    font-size: 10px;

    font-weight: 650;

    text-decoration: none;

    transition:
        background 0.22s ease,
        color 0.22s ease,
        transform 0.22s ease,
        box-shadow 0.22s ease;
}

.apha-nav-item:hover {
    color: var(--primary-dark);

    background: rgba(0, 143, 131, 0.055);

    transform: translateX(2px);
}

.apha-nav-item.active {
    color: var(--primary-dark);

    background: linear-gradient(
        100deg,
        rgba(0, 143, 131, 0.11),
        rgba(0, 143, 131, 0.045)
    );

    box-shadow:
        inset 3px 0 0 var(--primary),
        0 4px 12px rgba(0, 143, 131, 0.05);

    font-weight: 750;
}

.apha-nav-icon {
    width: 23px;

    height: 23px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 7px;

    background: #f5f8f7;

    transition:
        background 0.22s ease,
        transform 0.22s ease;
}

.apha-nav-dot {
    width: 5px;

    height: 5px;

    border-radius: 50%;

    background: var(--light);

    transition:
        transform 0.22s ease,
        background 0.22s ease;
}

.apha-nav-item:hover .apha-nav-icon {
    background: var(--primary-soft);

    transform: scale(1.05);
}

.apha-nav-item:hover .apha-nav-dot {
    background: var(--primary);

    transform: scale(1.25);
}

.apha-nav-item.active .apha-nav-icon {
    background: rgba(0, 143, 131, 0.13);
}

.apha-nav-item.active .apha-nav-dot {
    background: var(--primary);

    box-shadow: 0 0 0 4px rgba(0, 143, 131, 0.08);
}

.apha-nav-label {
    flex: 1;

    min-width: 0;

    overflow: hidden;

    text-overflow: ellipsis;

    white-space: nowrap;
}

.apha-nav-arrow {
    color: var(--primary);

    font-size: 12px;

    font-weight: 800;

    opacity: 0;

    transform: translateX(-4px);

    transition:
        opacity 0.22s ease,
        transform 0.22s ease;
}

.apha-nav-item:hover .apha-nav-arrow,
.apha-nav-item.active .apha-nav-arrow {
    opacity: 1;

    transform: translateX(0);
}

.apha-notices {
    position: relative;

    z-index: 2;

    flex-shrink: 0;

    margin-top: 10px;

    padding-top: 10px;

    border-top: 1px solid rgba(35, 70, 68, 0.07);
}

.notice-label {
    margin-bottom: 6px;
}

.apha-notices-list {
    display: flex;

    flex-direction: column;

    gap: 5px;

    max-height: 125px;

    overflow-y: auto;

    padding-right: 2px;

    scrollbar-width: thin;

    scrollbar-color: rgba(0, 143, 131, 0.15) transparent;
}

.apha-notices-list::-webkit-scrollbar {
    width: 3px;
}

.apha-notices-list::-webkit-scrollbar-track {
    background: transparent;
}

.apha-notices-list::-webkit-scrollbar-thumb {
    background: rgba(0, 143, 131, 0.15);

    border-radius: 10px;
}

.apha-notices-list :deep(*) {
    font-size: 8.5px;
}

.apha-notices-list :deep(p) {
    line-height: 1.3;

    margin: 0;
}

.apha-notices-list :deep(h1),
.apha-notices-list :deep(h2),
.apha-notices-list :deep(h3),
.apha-notices-list :deep(h4) {
    font-size: 9px;

    line-height: 1.25;

    margin-bottom: 3px;
}

.apha-notices-list :deep(.p-4) {
    padding: 8px !important;
}

.apha-notices-list :deep(.p-5) {
    padding: 8px !important;
}

.apha-notices-list :deep(.p-6) {
    padding: 8px !important;
}

.apha-sidebar-footer {
    position: relative;

    z-index: 2;

    flex-shrink: 0;

    padding-top: 8px;
}

.apha-footer-line {
    height: 1px;

    margin-bottom: 7px;

    background: linear-gradient(90deg, rgba(0, 143, 131, 0.14), transparent);
}

.apha-footer-status {
    display: flex;

    align-items: center;

    gap: 6px;

    padding: 5px 7px;

    border-radius: 8px;

    background: rgba(0, 143, 131, 0.035);

    color: #9aa5a5;

    font-size: 7px;

    font-weight: 650;
}

.apha-status-dot {
    width: 5px;

    height: 5px;

    flex-shrink: 0;

    border-radius: 50%;

    background: var(--primary);

    box-shadow: 0 0 0 3px rgba(0, 143, 131, 0.08);

    animation: sidebarPulse 2.5s ease-in-out infinite;
}

@keyframes sidebarPulse {
    0%,
    100% {
        box-shadow: 0 0 0 3px rgba(0, 143, 131, 0.08);
    }

    50% {
        box-shadow: 0 0 0 5px rgba(0, 143, 131, 0.02);
    }
}

.apha-header-actions {
    display: none;
}

@media (max-width: 1023px) {
    .apha-header-actions {
        position: absolute;

        top: 0;
        right: 0;

        display: flex;

        align-items: center;

        gap: 8px;
    }

    .apha-sidebar {
        position: relative;

        top: auto;

        width: 100%;

        min-width: 0;

        height: auto;

        min-height: auto;

        padding: 12px;

        border-right: 0;

        border-bottom: 1px solid var(--border);

        overflow: visible;
    }

    .apha-sidebar-header {
        padding-bottom: 9px;
    }

    .apha-navigation {
        flex: none;

        overflow: visible;

        padding-top: 10px;
    }

    .apha-nav {
        flex-direction: row;

        overflow-x: auto;

        overflow-y: hidden;

        padding-bottom: 3px;
    }

    .apha-nav-item {
        flex-shrink: 0;
    }

    .apha-notices {
        display: none;
    }

    .apha-sidebar-footer {
        display: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .apha-sidebar *,
    .apha-sidebar *::before,
    .apha-sidebar *::after {
        animation: none !important;

        transition: none !important;
    }
}
</style>
