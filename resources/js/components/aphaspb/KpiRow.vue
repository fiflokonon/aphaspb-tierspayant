<script setup lang="ts">
withDefaults(defineProps<{ columns?: number }>(), { columns: 3 });
</script>

<template>
    <div
        class="mt-5 grid gap-3"
        :style="{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }"
    >
        <slot />
    </div>
</template>
