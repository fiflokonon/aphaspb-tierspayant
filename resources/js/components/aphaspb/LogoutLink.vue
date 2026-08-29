<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';

defineProps<{ href: string }>();

/**
 * The console navigation prefetches every entry it lists. Those cached pages
 * belong to the session being closed, so they go with it.
 */
const forgetPrefetchedPages = () => {
    router.flushAll();
};
</script>

<template>
    <Link
        :href="href"
        method="post"
        as="button"
        type="button"
        data-test="logout-button"
        @click="forgetPrefetchedPages"
    >
        <slot>Se déconnecter</slot>
    </Link>
</template>
