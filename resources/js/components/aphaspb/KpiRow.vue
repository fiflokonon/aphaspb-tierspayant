<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ columns?: number }>(), { columns: 3 });

/**
 * The canvas lays these out on a desktop artboard. Below that they stack, one
 * card per row on a phone and two on a tablet, rather than shrinking to
 * illegibility.
 */
const gridClass = computed(() =>
    props.columns >= 4
        ? 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-4'
        : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
);
</script>

<template>
    <div class="mt-5 grid gap-3" :class="gridClass">
        <slot />
    </div>
</template>
