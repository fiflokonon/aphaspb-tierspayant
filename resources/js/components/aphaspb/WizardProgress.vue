<script setup lang="ts">
defineProps<{
    total: number;
    current: number;
}>();
</script>

<template>
    <!--
        flex-1 on the root, not only on the track: without it the component
        shrinks to its content and the segments collapse to zero width, leaving
        the counter sitting against the close button.
    -->
    <div class="flex flex-1 items-center gap-[10px]">
        <div class="flex flex-1 gap-1">
            <div
                v-for="step in total"
                :key="step"
                class="h-1 flex-1 rounded-full"
                :class="
                    step < current
                        ? 'bg-officine'
                        : step === current
                          ? 'bg-gold'
                          : 'bg-ink/[0.12]'
                "
            />
        </div>
        <div class="font-mono text-[10.5px]/none font-semibold text-ink/[0.45]">
            {{ current }}/{{ total }}
        </div>
    </div>
</template>
