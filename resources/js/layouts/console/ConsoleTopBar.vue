<script setup lang="ts">
import { Activity, ShieldCheck } from '@lucide/vue';
import type { ConsoleAccount } from '@/types/console';
import ConsoleAccountMenu from './ConsoleAccountMenu.vue';
import ConsoleBell from './ConsoleBell.vue';
import ConsoleSpaceChip from './ConsoleSpaceChip.vue';

defineProps<{
    count: number;
    href: string;
    account: ConsoleAccount | null;
}>();
</script>

<template>
    <div class="console-topbar hidden lg:flex">
        <div class="topbar-context">
            <div class="topbar-brand-mark">
                <span class="topbar-brand-dot"></span>
            </div>

            <div class="topbar-context-copy">
                <span class="topbar-kicker">ESPACE PROFESSIONNEL</span>
                <span class="topbar-title">Tiers payant</span>
            </div>

            <span class="topbar-divider"></span>

            <!-- <ConsoleSpaceChip /> -->

            <div class="topbar-status">
                <span class="status-icon"><ShieldCheck class="size-[14px]" /></span>
                <span>Service opérationnel</span>
            </div>
        </div>

        <div class="topbar-actions">
            <div class="topbar-network">
                <Activity class="size-[14px]" />
                <span>Réseau APhaSPB</span>
            </div>

            <ConsoleBell :count="count" :href="href" />

            <span class="topbar-action-divider"></span>

            <ConsoleAccountMenu v-if="account" :account="account" />
        </div>
    </div>
</template>

<style scoped>
.console-topbar {
    position: relative;
    min-height: 72px;
    width: 100%;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 24px;
    padding: 10px 12px 10px 16px;
    border: 1px solid rgb(39 79 73 / .10);
    border-radius: 18px;
    background: linear-gradient(135deg, #f7fbfa 0%, #eef6f3 100%);
    /* box-shadow: 0 8px 24px -18px rgb(36 82 72 / .30); */
    overflow: hidden;
}

.console-topbar::before {
    content: '';
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: linear-gradient(180deg, #2b8c7b, #8ccfc2);
}

.topbar-context,
.topbar-actions {
    display: flex;
    align-items: center;
    min-width: 0;
}

.topbar-context { gap: 10px; }
.topbar-actions { gap: 9px; }

.topbar-brand-mark {
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border-radius: 12px;
    background: #2b5a52;
    /* box-shadow: 0 6px 16px rgb(39 79 73 / .16); */
}

.topbar-brand-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #b9e5dc;
    /* box-shadow: 0 0 0 5px rgb(185 229 220 / .12); */
}

.topbar-context-copy {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 98px;
}

.topbar-kicker {
    color: #78918a;
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 8.5px;
    font-weight: 800;
    letter-spacing: .13em;
}

.topbar-title {
    color: #23473f;
    font-size: 13.5px;
    font-weight: 750;
}

.topbar-divider,
.topbar-action-divider {
    width: 1px;
    height: 28px;
    flex-shrink: 0;
    background: rgb(39 79 73 / .11);
}

.topbar-divider { margin: 0 3px; }

.topbar-status,
.topbar-network {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    color: #617a73;
    font-size: 11.5px;
    font-weight: 650;
}

.topbar-status {
    padding: 8px 10px;
    border-radius: 10px;
    background: rgb(255 255 255 / .68);
    border: 1px solid rgb(39 79 73 / .07);
}

.status-icon { color: #2b8c7b; display: inline-flex; }
.topbar-network { padding: 0 4px; color: #71857f; }
.topbar-network svg { color: #2b8c7b; }

@media (max-width: 1280px) {
    .topbar-network { display: none; }
}
</style>
