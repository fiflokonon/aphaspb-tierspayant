<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PanelLeftClose, PanelLeftOpen } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import { navIcon } from '@/lib/navIcons';
import { readCollapsed, writeCollapsed } from '@/lib/sidebarCollapsed';
import type { ConsoleAccount, ConsoleNavItem } from '@/types/console';
import ConsoleAccountMenu from './ConsoleAccountMenu.vue';
import ConsoleBell from './ConsoleBell.vue';

defineProps<{
    space: string | null;
    nav: ConsoleNavItem[];
    account: ConsoleAccount | null;
    notificationCount: number;
    notificationsHref: string;
}>();

/**
 * Le repli n'existe qu'au-dessus de 1024 px : en dessous, la barre est déjà
 * une bande horizontale, et la CSS neutralise la classe.
 */
/*
 * Lu dans onMounted et non au setup : le projet rend en SSR
 * (INERTIA_SSR_ENABLED vaut true par défaut), où localStorage n'existe pas.
 * Le serveur produisait donc toujours `false`, et Vue n'applique pas une
 * classe divergente à l'hydratation — le patchFlag est CLASS sans
 * dynamicProps, et la boucle de props est court-circuitée en production.
 * Résultat : la préférence était ignorée à chaque chargement, et le premier
 * clic sur « Replier » l'écrasait par un `'0'`.
 */
const collapsed = ref(false);

onMounted(() => {
    collapsed.value = readCollapsed();
});

watch(collapsed, writeCollapsed);
</script>

<template>
    <aside class="apha-sidebar" :class="{ collapsed }">
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
                les deux vivent dans ConsoleTopBar, avec le même menu de compte.
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
                    :title="collapsed ? item.label : undefined"
                    :aria-label="collapsed ? item.label : undefined"
                    prefetch
                    class="apha-nav-item"
                    :class="{
                        active: item.active,
                    }"
                >
                    <span class="apha-nav-icon">
                        <component
                            :is="navIcon(item.icon)"
                            v-if="navIcon(item.icon)"
                            class="apha-nav-glyph"
                        />
                        <!--
                            Repli visuel d'une clé inconnue. NavIconCoverageTest
                            empêche ce cas ; si jamais il survenait, une puce
                            vaut mieux qu'un trou.
                        -->
                        <span v-else class="apha-nav-dot"></span>
                    </span>

                    <span class="apha-nav-label">
                        {{ item.label }}
                    </span>

                    <span v-if="item.active" class="apha-nav-arrow"> → </span>
                </Link>
            </nav>
        </div>

        <div class="apha-sidebar-footer">
            <button
                type="button"
                class="apha-collapse"
                :aria-label="
                    collapsed
                        ? 'Déployer la navigation'
                        : 'Replier la navigation'
                "
                :aria-expanded="!collapsed"
                @click="collapsed = !collapsed"
            >
                <component
                    :is="collapsed ? PanelLeftOpen : PanelLeftClose"
                    class="apha-collapse-glyph"
                />
                <span class="apha-collapse-label">Replier</span>
            </button>

            <div class="apha-footer-line"></div>

            <div class="apha-footer-status">
                <span class="apha-status-dot"></span>

                <span> Plateforme opérationnelle </span>
            </div>
        </div>
    </aside>
</template>

<style scoped>
.apha-sidebar {
    --muted: rgb(255 255 255 / 0.62);
    --light: rgb(255 255 255 / 0.40);
    position: sticky;
    top: 0;
    z-index: 30;
    display: flex;
    flex-direction: column;
    width: 232px;
    min-width: 232px;
    height: 100vh;
    min-height: 100vh;
    flex-shrink: 0;
    padding: 18px 12px 12px;
    background: linear-gradient(180deg, #0c342d 0%, #0a2b26 100%);
    color: #fff;
    border-right: 1px solid rgb(255 255 255 / 0.07);
    /* box-shadow: 14px 0 38px rgb(8 43 35 / 0.12); */
    overflow: hidden;
}

.apha-sidebar::before {
    content: '';
    position: absolute;
    inset: 0 auto 0 0;
    width: 3px;
    background: linear-gradient(180deg, #7bd5c5, #1aa68e);
    opacity: .9;
}

.apha-sidebar::after {
    content: '';
    position: absolute;
    width: 180px;
    height: 180px;
    right: -90px;
    top: 70px;
    border-radius: 50%;
    background: rgb(78 190 169 / 0.08);
    filter: blur(3px);
    pointer-events: none;
}

.apha-sidebar-header {
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    padding: 2px 5px 18px;
    border-bottom: 1px solid rgb(255 255 255 / 0.09);
}

.apha-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    transition: transform .22s ease;
}

.apha-brand:hover { transform: translateX(2px); }

.apha-logo-wrap {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    background: rgb(255 255 255 / 0.10);
    border: 1px solid rgb(255 255 255 / 0.12);
    box-shadow: 0 10px 24px rgb(0 0 0 / 0.12);
}

.apha-logo { width: 29px; height: 29px; object-fit: contain; border-radius: 50%; }
.apha-brand-content { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.apha-brand-name { color: #fff; font-size: 17px; font-weight: 800; letter-spacing: -.025em; }
.apha-brand-subtitle { color: rgb(255 255 255 / .48); font-size: 9px; font-weight: 600; letter-spacing: .03em; }

.apha-space {
    display: flex; align-items: center; gap: 7px; margin-top: 13px; padding-left: 49px;
    color: #8bd8ca; font-family: 'JetBrains Mono', monospace; font-size: 9px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
}
.space-dot { width: 6px; height: 6px; border-radius: 50%; background: #d8aa3b; box-shadow: 0 0 0 4px rgb(216 170 59 / .10); }

.apha-navigation {
    position: relative; z-index: 2; flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; padding-top: 18px; overflow: hidden;
}
.apha-section-label { margin: 0 7px 9px; color: rgb(255 255 255 / .34); font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
.apha-nav { display: flex; flex-direction: column; gap: 5px; min-height: 0; overflow-y: auto; padding: 0 2px 2px; scrollbar-width: thin; scrollbar-color: rgb(255 255 255 / .12) transparent; }
.apha-nav::-webkit-scrollbar { width: 3px; }
.apha-nav::-webkit-scrollbar-track { background: transparent; }
.apha-nav::-webkit-scrollbar-thumb { background: rgb(255 255 255 / .12); border-radius: 10px; }
.apha-nav-glyph { width: 18px; height: 18px; stroke-width: 1.9; }
.apha-nav-item {
    position: relative; display: flex; align-items: center; min-height: 42px; gap: 10px; padding: 8px 10px; border-radius: 12px; color: var(--muted); font-size: 13.5px; font-weight: 650; text-decoration: none; transition: background .2s ease, color .2s ease, transform .2s ease, box-shadow .2s ease;
}
.apha-nav-item:hover { color: #fff; background: rgb(255 255 255 / .07); transform: translateX(2px); }
.apha-nav-item.active { color: #fff; background: linear-gradient(135deg, #148f79, #0c7667); box-shadow: 0 10px 22px rgb(5 77 65 / .26); font-weight: 750; }
.apha-nav-icon { width: 29px; height: 29px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 9px; background: rgb(255 255 255 / .07); color: rgb(255 255 255 / .72); transition: background .2s ease, transform .2s ease; }
.apha-nav-dot { width: 5px; height: 5px; border-radius: 50%; background: rgb(255 255 255 / .42); }
.apha-nav-item:hover .apha-nav-icon { background: rgb(255 255 255 / .10); transform: scale(1.04); }
.apha-nav-item.active .apha-nav-icon { background: rgb(255 255 255 / .16); color: #fff; }
.apha-nav-item.active .apha-nav-dot { background: #fff; }
.apha-nav-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.apha-nav-arrow { color: #9ce0d3; font-size: 12px; font-weight: 800; opacity: 0; transform: translateX(-4px); transition: opacity .2s ease, transform .2s ease; }
.apha-nav-item:hover .apha-nav-arrow, .apha-nav-item.active .apha-nav-arrow { opacity: 1; transform: translateX(0); }

.apha-sidebar-footer { position: relative; z-index: 2; flex-shrink: 0; padding-top: 10px; }
.apha-footer-line { height: 1px; margin-bottom: 9px; background: rgb(255 255 255 / .08); }
.apha-footer-status { display: flex; align-items: center; gap: 7px; padding: 8px 9px; border-radius: 10px; background: rgb(255 255 255 / .045); color: rgb(255 255 255 / .46); font-size: 9px; font-weight: 650; }
.apha-status-dot { width: 6px; height: 6px; flex-shrink: 0; border-radius: 50%; background: #70d2bf; box-shadow: 0 0 0 4px rgb(112 210 191 / .08); animation: sidebarPulse 2.5s ease-in-out infinite; }
@keyframes sidebarPulse { 0%,100% { box-shadow: 0 0 0 3px rgb(112 210 191 / .07); } 50% { box-shadow: 0 0 0 6px rgb(112 210 191 / .015); } }
.apha-header-actions { display: none; }

.apha-collapse { display: flex; align-items: center; gap: 9px; width: 100%; margin-bottom: 10px; padding: 9px 10px; border: 1px solid rgb(255 255 255 / .09); border-radius: 10px; background: rgb(255 255 255 / .055); color: rgb(255 255 255 / .65); font-size: 12px; font-weight: 600; cursor: pointer; transition: background .2s ease, color .2s ease; }
.apha-collapse:hover { background: rgb(255 255 255 / .09); color: #fff; }
.apha-collapse-glyph { width: 17px; height: 17px; flex: none; stroke-width: 1.9; }

.apha-sidebar.collapsed { width: 68px; min-width: 68px; padding-left: 9px; padding-right: 9px; }
.apha-sidebar.collapsed .apha-brand-content, .apha-sidebar.collapsed .apha-nav-label, .apha-sidebar.collapsed .apha-nav-arrow, .apha-sidebar.collapsed .apha-space, .apha-sidebar.collapsed .apha-section-label, .apha-sidebar.collapsed .apha-footer-status, .apha-sidebar.collapsed .apha-collapse-label { display: none; }
.apha-sidebar.collapsed .apha-nav { overflow-x: hidden; }
.apha-sidebar.collapsed .apha-nav-item, .apha-sidebar.collapsed .apha-collapse { justify-content: center; width: 100%; padding-left: 0; padding-right: 0; gap: 0; }

@media (max-width: 1023.98px) {
    .apha-sidebar { position: relative; width: 100%; min-width: 0; height: auto; min-height: 0; padding: 10px 12px; border-right: 0; border-bottom: 1px solid rgb(255 255 255 / .07); box-shadow: 0 8px 26px rgb(8 43 35 / .10); }
    .apha-sidebar::after { display: none; }
    .apha-sidebar-header { display: flex; align-items: center; gap: 12px; padding: 2px 3px 10px; border-bottom: 0; }
    .apha-brand { min-width: 0; }
    .apha-space { display: none; }
    .apha-header-actions { display: flex; align-items: center; gap: 7px; margin-left: auto; }
    .apha-navigation { padding-top: 7px; overflow: visible; }
    .apha-section-label { display: none; }
    .apha-nav { flex-direction: row; overflow-x: auto; overflow-y: hidden; padding: 0 1px 2px; }
    .apha-nav-item { flex: 0 0 auto; min-height: 40px; padding: 7px 10px; }
    .apha-nav-icon { width: 26px; height: 26px; }
    .apha-nav-arrow { display: none; }
    .apha-sidebar-footer { display: none; }
    .apha-sidebar.collapsed { width: 100%; min-width: 0; padding-left: 12px; padding-right: 12px; }
    .apha-sidebar.collapsed .apha-brand-content { display: flex; }
    .apha-sidebar.collapsed .apha-nav-label { display: block; }
    .apha-sidebar.collapsed .apha-nav-item { justify-content: flex-start; gap: 10px; width: auto; padding-left: 10px; padding-right: 10px; }
}

@media (max-width: 480px) {
    .apha-brand-subtitle { display: none; }
    .apha-logo-wrap { width: 36px; height: 36px; }
    .apha-logo { width: 26px; height: 26px; }
    .apha-nav-item { font-size: 12.5px; }
}
</style>
