<script setup lang="ts">
/**
 * Le bandeau de la colonne de contenu, à partir de lg.
 *
 * Il ne porte que la cloche. Le logo, le badge d'espace, l'identité et la
 * déconnexion vivent dans la barre latérale ; les répéter ici créerait deux
 * identités concurrentes, et chaque page rend son propre ConsoleHeader juste
 * en dessous — un bandeau chargé se lirait comme un troisième en-tête.
 *
 * Il couvre la seule colonne de contenu et ne traverse pas la barre latérale,
 * dont le `sticky` est porteur (voir .ai/rules/layouts.md).
 */
import ConsoleBell from './ConsoleBell.vue';

defineProps<{ count: number; href: string }>();
</script>

<template>
    <div class="mb-4 hidden items-center justify-end lg:flex">
        <ConsoleBell :count="count" :href="href" />
    </div>
</template>
