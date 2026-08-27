<script setup lang="ts">
import { computed } from 'vue';
import { formatFcfa, parseFcfa } from '@/lib/fcfa';

defineProps<{
    label: string;
    name: string;
    shortcut?: { label: string; value: number };
    error?: string;
}>();

const model = defineModel<number>({ default: 0 });

const displayed = computed(() => formatFcfa(model.value));

function onInput(event: Event) {
    model.value = parseFcfa((event.target as HTMLInputElement).value);
}
</script>

<template>
    <div>
        <div class="flex items-baseline gap-2">
            <label
                class="font-mono text-[10.5px]/none font-semibold tracking-[0.05em] text-ink/50"
            >
                {{ label }}
            </label>
            <button
                v-if="shortcut"
                type="button"
                class="ml-auto text-[11px]/none font-semibold text-officine"
                @click="model = shortcut.value"
            >
                {{ shortcut.label }}
            </button>
        </div>

        <div
            class="mt-[7px] flex h-14 items-center gap-2 rounded-xl border-[1.5px] bg-card px-[14px] transition-shadow focus-within:border-gold-mid/[0.55] focus-within:shadow-[0_0_0_3px_rgb(217_163_37_/_0.13)]"
            :class="error ? 'border-terracotta' : 'border-ink/[0.13]'"
        >
            <input
                :name="name"
                :value="displayed"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                placeholder="0"
                class="min-w-0 flex-1 bg-transparent text-[22px]/none font-bold text-ink outline-none placeholder:text-ink/25"
                @input="onInput"
            />
            <span class="font-mono text-xs font-semibold text-ink/[0.45]">
                FCFA
            </span>
        </div>

        <p v-if="error" class="mt-[5px] text-[11px]/[1.4] text-terracotta-dark">
            {{ error }}
        </p>
    </div>
</template>
