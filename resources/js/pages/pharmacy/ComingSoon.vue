<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ComingSoon from '@/components/aphaspb/ComingSoon.vue';

const props = defineProps<{
    title: string;
    body: string;
    increment: string;
}>();
</script>

<template>
    <Head :title="props.title" />

    <ComingSoon v-bind="props" />
</template>
