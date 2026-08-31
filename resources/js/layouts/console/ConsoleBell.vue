<script setup lang="ts">
/**
 * Le lien vers le centre de notifications, avec sa pastille.
 *
 * Un simple <Link> plutôt qu'un menu déroulant : le détail d'un récapitulatif
 * de retard tient mal dans 320 px, et la page peut le dérouler en entier.
 */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ count: number; href: string }>();

/** Au-delà de 9 la pastille cesse d'être ronde, et le chiffre exact n'aide plus. */
const badge = computed(() => (props.count > 9 ? '9+' : String(props.count)));

const label = computed(() =>
    props.count === 0
        ? 'Notifications'
        : `Notifications, ${props.count} en attente`,
);
</script>

<template>
    <Link
        :href="href"
        prefetch
        class="relative grid size-9 shrink-0 place-items-center rounded-[10px] border border-ink/[0.10] bg-white/80 text-ink/70 transition-colors hover:bg-cream-header"
        :aria-label="label"
    >
        <span aria-hidden="true" class="text-[15px]/none">◔</span>

        <span
            v-if="count > 0"
            class="absolute -top-1.5 -right-1.5 grid min-w-[18px] place-items-center rounded-full bg-terracotta px-1 text-[10px]/[18px] font-bold text-white"
        >
            {{ badge }}
        </span>
    </Link>
</template>
