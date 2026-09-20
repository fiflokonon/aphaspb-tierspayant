<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ConsoleAccount } from '@/types/console';

const props = defineProps<{
    /** Repli pour l'espace réseau, qui n'a pas d'officine à annoncer. */
    eyebrow?: string;
    title: string;
}>();

const page = usePage();

/**
 * L'officine passe avant la légende de l'écran.
 *
 * Elle vient du shell et non d'une prop : cinq écrans l'affichent, et la faire
 * voyager en prop obligerait autant de contrôleurs à la répéter. Dans l'espace
 * réseau il n'y en a pas, et chaque écran garde la sienne.
 */
const identity = computed(() => {
    const account = (page.props.console as { account?: ConsoleAccount } | null)
        ?.account;

    if (!account?.pharmacy) {
        return props.eyebrow ?? '';
    }

    return [account.pharmacy.name, account.pharmacy.city]
        .filter(Boolean)
        .join(' · ');
});
</script>

<template>
    <div
        class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
    >
        <div>
            <div
                class="font-mono text-[11.5px]/none font-bold tracking-[0.06em] text-ink/[0.72] uppercase"
            >
                {{ identity }}
            </div>
            <div class="mt-2 text-[22px]/[1.2] font-bold text-ink">
                <slot name="title">{{ title }}</slot>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <slot name="filters" />
            <slot name="action" />
        </div>
    </div>
</template>
