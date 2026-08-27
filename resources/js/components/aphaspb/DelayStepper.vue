<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        name: string;
        min?: number;
        max?: number;
        hint?: string;
        error?: string;
    }>(),
    { min: 0, max: 365 },
);

const model = defineModel<number | null>({ default: null });

function step(by: number) {
    const next = (model.value ?? 0) + by;

    model.value = Math.min(props.max, Math.max(props.min, next));
}
</script>

<template>
    <div
        class="rounded-xl border border-ink/[0.11] bg-card px-[15px] py-[14px]"
    >
        <div class="flex items-center gap-[10px]">
            <div class="text-[12.5px]/[1.3] font-semibold text-ink">
                Délai de paiement
            </div>
            <div class="ml-auto flex items-center gap-2">
                <button
                    type="button"
                    aria-label="Diminuer le délai"
                    class="grid size-11 place-items-center text-[16px] font-semibold text-officine"
                    @click="step(-1)"
                >
                    <span
                        class="grid size-8 place-items-center rounded-lg bg-officine/10"
                    >
                        −
                    </span>
                </button>
                <div
                    class="min-w-10 text-center text-[17px]/none font-bold text-ink"
                >
                    {{ model ?? '—'
                    }}<span class="text-[11px] font-medium text-ink/50">
                        j</span
                    >
                </div>
                <button
                    type="button"
                    aria-label="Augmenter le délai"
                    class="grid size-11 place-items-center text-[16px] font-semibold text-officine"
                    @click="step(1)"
                >
                    <span
                        class="grid size-8 place-items-center rounded-lg bg-officine/10"
                    >
                        +
                    </span>
                </button>
            </div>
        </div>
        <p
            v-if="error"
            class="mt-[10px] text-[11px]/[1.45] text-terracotta-dark"
        >
            {{ error }}
        </p>
        <p
            v-else-if="hint"
            class="mt-[10px] text-[11px]/[1.45] text-ink/[0.45]"
        >
            {{ hint }}
        </p>
        <input :name="name" type="hidden" :value="model ?? ''" />
    </div>
</template>
