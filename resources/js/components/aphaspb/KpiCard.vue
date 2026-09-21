<script setup lang="ts">
import { kpiToneClass, kpiToneFill } from '@/types/aphaspb';
import type { KpiTone } from '@/types/aphaspb';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string;
        unit?: string;
        hint?: string;
        tone?: KpiTone;
        progress?: number;
        /**
         * `band` rend la carte lisible sur un aplat sombre.
         *
         * Distincte de `tone`, qui reste la lecture sémantique de la valeur —
         * neutre, bonne, en alerte. Ici il s'agit du fond sur lequel la carte
         * est posée, pas de ce que son chiffre raconte.
         */
        surface?: 'light' | 'band';
    }>(),
    { tone: 'neutral', surface: 'light' },
);
</script>

<template>
    <div
        class="rounded-[11px] py-[15px]"
        :class="
            surface === 'band'
                ? 'bg-transparent px-0'
                : 'border border-border bg-card px-4'
        "
    >
        <div
            class="font-mono font-semibold"
            :class="
                surface === 'band'
                    ? 'text-[8.5px]/none text-white/60'
                    : 'text-[10.5px]/none text-ink/[0.45]'
            "
        >
            {{ label }}
        </div>
        <div
            class="mt-[9px] flex items-baseline gap-[6px]"
            :class="surface === 'band' ? 'whitespace-nowrap' : ''"
        >
            <div
                class="text-[28px]/none font-extrabold"
                :class="
                    surface === 'band' ? 'text-white' : kpiToneClass[props.tone]
                "
            >
                {{ value }}
            </div>
            <div
                v-if="unit"
                class="text-xs font-medium"
                :class="surface === 'band' ? 'text-white/60' : 'text-ink/50'"
            >
                {{ unit }}
            </div>
        </div>
        <div
            v-if="progress !== undefined"
            class="mt-[11px] h-[5px] rounded-full bg-ink/[0.08]"
        >
            <div
                class="h-full rounded-full"
                :class="kpiToneFill[props.tone]"
                :style="{ width: `${Math.min(100, Math.max(0, progress))}%` }"
            />
        </div>
        <div
            v-else-if="(hint || $slots.hint) && surface !== 'band'"
            class="mt-[11px]"
            :class="
                surface === 'band'
                    ? 'text-[10px]/[1.35] text-white/70'
                    : 'text-[11px]/[1.4] text-ink/50'
            "
        >
            <slot name="hint">{{ hint }}</slot>
        </div>
    </div>
</template>
