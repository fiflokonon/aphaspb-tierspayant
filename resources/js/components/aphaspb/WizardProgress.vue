<script setup lang="ts">
defineProps<{
    total: number;
    current: number;
}>();
</script>

<template>
    <div class="flex items-center gap-[10px]">
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
