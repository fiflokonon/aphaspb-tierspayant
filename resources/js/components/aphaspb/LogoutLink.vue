<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';

defineProps<{ href: string }>();

/**
 * The console navigation prefetches every entry it lists. Those cached pages
 * belong to the session being closed, so they go with it.
 */
const forgetPrefetchedPages = () => {
    router.flushAll();
};
</script>

<!--
    The button look lives here rather than in the three placements, which used
    to style it as muted text and, in the onboarding, as an underlined link —
    it read as a caption, and people did not find it. Callers now pass width
    and spacing only.
-->
<template>
    <Link
        :href="href"
        method="post"
        as="button"
        type="button"
        data-test="logout-button"
        class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-[10px] border border-ink/[0.10] bg-white/80 px-4 text-[12px] font-semibold text-ink/70 transition-colors hover:border-officine hover:bg-officine hover:text-white"
        @click="forgetPrefetchedPages"
    >
        <LogOut class="size-[15px]" aria-hidden="true" />
        <slot>Se déconnecter</slot>
    </Link>
</template>
