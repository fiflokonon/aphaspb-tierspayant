<script setup lang="ts">
/**
 * Une ligne de tableau.
 *
 * Le corps de la ligne est le corps des chiffres : dans ces tableaux, presque
 * toutes les cellules sont des montants, des taux ou des délais, et les rares
 * cellules de texte — nom d'assureur, libellé de mois — portent déjà leur
 * propre taille. Le monter ici de 12 à 13 px grossit donc les chiffres sans
 * toucher aux libellés.
 */
import { rowToneClass } from '@/types/aphaspb';
import type { DataTableRowTone } from '@/types/aphaspb';

const props = withDefaults(
    defineProps<{
        template: string;
        tone?: DataTableRowTone;
    }>(),
    { tone: 'default' },
);
</script>

<template>
    <div
        class="items-center border-t border-ink/[0.06] px-4 py-3 text-[13px] font-semibold text-ink"
        :class="rowToneClass[props.tone]"
        :style="{ display: 'grid', gridTemplateColumns: template, gap: '14px' }"
    >
        <slot />
    </div>
</template>
