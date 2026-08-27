<script setup lang="ts">
withDefaults(defineProps<{ height?: number }>(), { height: 200 });
</script>

<template>
    <div class="animate-pulse" :style="{ height: `${height}px` }">
        <div class="flex h-full items-end gap-2">
            <div
                v-for="bar in 12"
                :key="bar"
                class="flex-1 rounded-t bg-ink/[0.07]"
                :style="{ height: `${30 + ((bar * 17) % 60)}%` }"
            />
        </div>
    </div>
</template>
