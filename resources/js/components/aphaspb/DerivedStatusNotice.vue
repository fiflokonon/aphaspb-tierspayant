<script setup lang="ts">
import { computed } from 'vue';
import { formatFcfa } from '@/lib/fcfa';
import type { DeclarationStatus } from '@/types/aphaspb';

const props = defineProps<{
    status: DeclarationStatus;
    label: string;
    settledShare: number;
    outstanding: number;
    manual: boolean;
}>();

const TONES: Record<
    DeclarationStatus,
    { card: string; badge: string; fill: string; glyph: string }
> = {
    paid: {
        card: 'bg-officine/[0.10] border-officine/[0.30]',
        badge: 'bg-officine',
        fill: 'bg-officine',
        glyph: '✓',
    },
    partial: {
        card: 'bg-gold-mid/[0.13] border-gold-mid/[0.35]',
        badge: 'bg-gold-mid',
        fill: 'bg-gold-mid',
        glyph: '◐',
    },
    unpaid: {
        card: 'bg-ink/[0.05] border-ink/[0.14]',
        badge: 'bg-ink/50',
        fill: 'bg-ink/40',
        glyph: '—',
    },
    rejected: {
        card: 'bg-terracotta/[0.09] border-terracotta/[0.30]',
        badge: 'bg-terracotta',
        fill: 'bg-terracotta',
        glyph: '✕',
    },
};

const tone = computed(() => TONES[props.status]);
</script>

<template>
    <div class="rounded-xl border px-[15px] py-[14px]" :class="tone.card">
        <div class="flex items-center gap-[9px]">
            <div
                class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white"
                :class="tone.badge"
            >
                {{ tone.glyph }}
            </div>
            <div class="text-[13px]/[1.2] font-bold text-ink">
                {{ manual ? 'Statut corrigé à la main' : 'Statut déduit' }} :
                {{ label.toLowerCase() }}
            </div>
        </div>

        <div class="mt-[11px] h-2 overflow-hidden rounded-full bg-ink/[0.10]">
            <div
                class="h-full rounded-full"
                :class="tone.fill"
                :style="{
                    width: `${Math.min(100, Math.max(0, settledShare))}%`,
                }"
            />
        </div>

        <div class="mt-2 flex flex-wrap items-baseline gap-x-[6px]">
            <div class="text-[13px]/none font-bold text-ink">
                {{ Math.round(settledShare) }} % réglé
            </div>
            <div
                v-if="outstanding > 0"
                class="text-[11.5px]/none font-medium text-ink/[0.55]"
            >
                · reste {{ formatFcfa(outstanding) }} FCFA en attente
            </div>
        </div>

        <p class="mt-[10px] text-[11px]/[1.45] text-ink/50">
            <template v-if="manual">
                Vous avez choisi ce statut vous-même. Videz-le pour revenir au
                statut calculé.
            </template>
            <template v-else>
                Vous n'avez plus à choisir le statut : il se calcule. Vous
                pouvez le corriger si besoin.
            </template>
        </p>
    </div>
</template>
