<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type {
    ConsoleAccount,
    ConsoleNavItem,
    ConsoleNotice,
} from '@/types/console';
import ConsoleAccountFooter from './ConsoleAccountFooter.vue';
import ConsoleSidebarNotice from './ConsoleSidebarNotice.vue';

defineProps<{
    space: string | null;
    nav: ConsoleNavItem[];
    notices: ConsoleNotice[];
    account: ConsoleAccount | null;
}>();
</script>

<template>
    <!--
        The canvas draws this rail at 212px on a 1040px artboard and offers no
        reduced version. Below lg it becomes a band across the top rather than a
        drawer: the same elements, restacked, with no new interaction to learn.
    -->
    <!--
        lg:h-screen + lg:sticky rather than letting the rail stretch: as a plain
        flex child it grows to the height of the document, and mt-auto then pins
        the account footer to the bottom of the *page* instead of the viewport —
        600px below the fold on the dashboard. One viewport tall, with its own
        overflow, keeps the way out of the session always in sight.
    -->
    <aside
        class="flex w-full shrink-0 flex-col bg-ink px-[14px] py-[18px] text-white lg:sticky lg:top-0 lg:h-screen lg:w-[212px] lg:overflow-y-auto"
    >
        <div class="flex items-center gap-[9px]">
            <img
                src="/logo-aphaspb.webp"
                alt=""
                class="size-6 rounded-full bg-white p-[2px]"
            />
            <div class="text-xs font-bold">APhaSPB</div>
            <div
                v-if="space"
                class="ml-auto font-mono text-[9.5px] font-semibold tracking-[0.06em] text-gold lg:hidden"
            >
                {{ space }}
            </div>
        </div>

        <div
            v-if="space"
            class="mt-[6px] hidden font-mono text-[9.5px] font-semibold tracking-[0.06em] text-gold lg:block"
        >
            {{ space }}
        </div>

        <nav
            class="-mx-[14px] mt-4 flex gap-[3px] overflow-x-auto px-[14px] lg:mx-0 lg:mt-5 lg:flex-col lg:overflow-visible lg:px-0"
        >
            <Link
                v-for="item in nav"
                :key="item.href"
                :href="item.href"
                prefetch
                class="flex min-h-[44px] shrink-0 items-center rounded-lg px-[11px] py-[10px] text-[12.5px] whitespace-nowrap transition-colors"
                :class="
                    item.active
                        ? 'bg-white/[0.12] font-semibold text-white'
                        : 'font-medium text-white/[0.62] hover:bg-white/[0.06]'
                "
            >
                {{ item.label }}
            </Link>
        </nav>

        <div
            v-if="notices.length"
            class="mt-4 grid gap-[14px] sm:grid-cols-2 lg:mt-7 lg:grid-cols-1"
        >
            <ConsoleSidebarNotice
                v-for="notice in notices"
                :key="notice.title"
                v-bind="notice"
            />
        </div>

        <ConsoleAccountFooter v-if="account" :account="account" />
        <div v-else class="mt-auto" />
    </aside>
</template>
