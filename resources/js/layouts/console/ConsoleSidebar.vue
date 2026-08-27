<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ConsoleNavItem, ConsoleNotice } from '@/types/console';
import ConsoleSidebarNotice from './ConsoleSidebarNotice.vue';

defineProps<{
    space: string | null;
    nav: ConsoleNavItem[];
    notices: ConsoleNotice[];
}>();
</script>

<template>
    <aside
        class="flex w-[212px] shrink-0 flex-col bg-ink px-[14px] py-[18px] text-white"
    >
        <div class="flex items-center gap-[9px]">
            <img
                src="/logo-aphaspb.webp"
                alt=""
                class="size-6 rounded-full bg-white p-[2px]"
            />
            <div class="text-xs font-bold">APhaSPB</div>
        </div>

        <div
            v-if="space"
            class="mt-[6px] font-mono text-[9.5px] font-semibold tracking-[0.06em] text-gold"
        >
            {{ space }}
        </div>

        <nav class="mt-5 flex flex-col gap-[3px]">
            <Link
                v-for="item in nav"
                :key="item.href"
                :href="item.href"
                prefetch
                class="rounded-lg px-[11px] py-[10px] text-[12.5px] transition-colors"
                :class="
                    item.active
                        ? 'bg-white/[0.12] font-semibold text-white'
                        : 'font-medium text-white/[0.62] hover:bg-white/[0.06]'
                "
            >
                {{ item.label }}
            </Link>
        </nav>

        <div v-if="notices.length" class="mt-7 flex flex-col gap-[14px]">
            <ConsoleSidebarNotice
                v-for="notice in notices"
                :key="notice.title"
                v-bind="notice"
            />
        </div>

        <div class="mt-auto" />
    </aside>
</template>
