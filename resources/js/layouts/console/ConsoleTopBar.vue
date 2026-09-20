<script setup lang="ts">
/**
 * Le bandeau de la colonne de contenu, à partir de lg.
 *
 * Il porte la cloche et **l'identité de la personne connectée**. Celle-ci
 * vivait au pied de la barre latérale, en 11,5 px sous la navigation — et
 * disparaissait entièrement sous 1024 px, où ce pied est masqué. Elle a été
 * déplacée ici, pas dupliquée : deux identités concurrentes seraient pires
 * qu'une mal placée, et le pied ne garde que la déconnexion.
 *
 * Le nom de l'officine, lui, vit dans le ConsoleHeader de chaque écran : c'est
 * le contexte du travail, pas celui du compte.
 *
 * Ce bandeau couvre la seule colonne de contenu et ne traverse pas la barre
 * latérale, dont le `sticky` est porteur (voir .ai/rules/layouts.md).
 */
import ConsoleBell from './ConsoleBell.vue';

defineProps<{ count: number; href: string; accountName: string }>();
</script>

<template>
    <div class="mb-4 hidden items-center justify-end gap-3 lg:flex">
        <span class="truncate text-[12.5px] font-semibold text-ink/[0.75]">
            {{ accountName }}
        </span>

        <ConsoleBell :count="count" :href="href" />
    </div>
</template>
