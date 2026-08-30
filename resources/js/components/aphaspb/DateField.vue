<script setup lang="ts">
defineProps<{
    label: string;
    name: string;
    min?: string;
    max?: string;
    hint?: string;
    error?: string;
}>();

const model = defineModel<string | null>({ default: null });
</script>

<template>
    <div>
        <label
            class="font-mono text-[10.5px]/none font-semibold tracking-[0.05em] text-ink/50"
            :for="name"
        >
            {{ label }}
        </label>

        <div
            class="mt-[7px] flex h-[46px] items-center rounded-xl border-[1.5px] bg-card px-[14px] transition-shadow focus-within:border-gold-mid/[0.55] focus-within:shadow-[0_0_0_3px_rgb(217_163_37_/_0.13)]"
            :class="error ? 'border-terracotta' : 'border-ink/[0.13]'"
        >
            <!--
                The native picker rather than a bespoke calendar: it is the one
                widget a pharmacist already knows on their phone, and it comes
                with the locale's own date order for free.
            -->
            <input
                :id="name"
                v-model="model"
                :name="name"
                :min="min"
                :max="max"
                type="date"
                class="min-w-0 flex-1 bg-transparent text-[14px] font-semibold text-ink outline-none"
            />
        </div>

        <p v-if="error" class="mt-[5px] text-[11px]/[1.4] text-terracotta-dark">
            {{ error }}
        </p>
        <p v-else-if="hint" class="mt-[5px] text-[11px]/[1.4] text-ink/[0.45]">
            {{ hint }}
        </p>
    </div>
</template>
