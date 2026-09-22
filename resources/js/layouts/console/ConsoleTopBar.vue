<script setup lang="ts">
/**
 * Le bandeau de la colonne de contenu, à partir de lg.
 *
 * Il porte la cloche, **le compte connecté** — le nom ouvre le menu qui tient
 * le changement d'officine et la déconnexion — et **l'espace** où la session
 * est ouverte. C'est le même composant que sous 1024 px, où les deux vivent
 * dans la barre latérale : une seule implémentation, donc un seul endroit où
 * ces éléments évoluent.
 *
 * Elles vivaient auparavant au pied du rail. Deux emplacements concurrents
 * pour le même compte, dont l'un disparaissait sous 1024 px : le nom se
 * cherchait en haut à droite, la déconnexion tout en bas à gauche.
 *
 * Le nom de l'officine a rejoint ce bandeau le 22/09/2026. Il précédait le
 * titre de chaque écran, sur trois lignes et trois polices, pour dire une
 * chose qui ne change pas d'un écran à l'autre : sa place est dans la
 * coquille, à côté du compte.
 *
 * Ce bandeau couvre la seule colonne de contenu et ne traverse pas la barre
 * latérale, dont le `sticky` est porteur (voir .ai/rules/layouts.md).
 */
import type { ConsoleAccount } from '@/types/console';
import ConsoleAccountMenu from './ConsoleAccountMenu.vue';
import ConsoleBell from './ConsoleBell.vue';
import ConsoleSpaceChip from './ConsoleSpaceChip.vue';

defineProps<{
    count: number;
    href: string;
    account: ConsoleAccount | null;
}>();
</script>

<template>
    <div class="mb-4 hidden items-center justify-end gap-3 lg:flex">
        <ConsoleSpaceChip />

        <ConsoleAccountMenu v-if="account" :account="account" />

        <ConsoleBell :count="count" :href="href" />
    </div>
</template>
