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
    <div class="console-header">
        <div class="header-band">
            <div class="header-eyebrow">{{ identity }}</div>

            <!--
                Instrument Serif n'a qu'une graisse : pas de font-bold, qui
                déclencherait une graisse synthétique baveuse.
            -->
            <div class="header-title">
                <slot name="title">{{ title }}</slot>
            </div>

            <!--
                `$slots.hero` directement dans le template, et non un computed
                sur useSlots() : l'objet des slots est muté sur place entre
                deux rendus sans notifier le computed, qui resterait figé si un
                écran rendait son hero sous condition. Un `:empty` en CSS ne
                convient pas non plus — Vue laisse parfois un nœud commentaire.
            -->
            <div v-if="$slots.hero" class="header-hero">
                <slot name="hero" />
            </div>
        </div>

        <div class="header-actions">
            <slot name="filters" />
            <slot name="action" />
        </div>
    </div>
</template>

<style scoped>
.console-header {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.header-band {
    min-width: 0;
}

.header-eyebrow {
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 11.5px;
    line-height: 1;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;

    /* Dérivé de --ink : l'écrire en clair figerait l'ancienne teinte. */
    color: color-mix(in srgb, var(--ink) 72%, transparent);
}

.header-title {
    margin-top: 8px;

    font-family: var(--font-serif, ui-serif, Georgia, serif);
    font-size: 34px;
    line-height: 1.06;

    color: var(--ink);
}

.header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

/* À partir de 1024 px : en-tête clair, actions à droite du titre. */
@media (min-width: 1024px) {
    .console-header {
        flex-direction: row;
        align-items: flex-end;
        justify-content: space-between;
    }

    /* Sinon la grille du hero colle au titre de 34 px. */
    .header-hero {
        margin-top: 18px;
    }
}

/*
  Sous 1024 px, le même bloc se replie en bandeau vert.

  Ce n'est pas une seconde charte : la liste d'alertes, l'échelle
  typographique, le rythme d'espacement et le serif sont identiques des deux
  côtés. Seul l'en-tête change de forme, parce qu'à 390 px trois cartes
  claires empilées repoussent le contenu utile hors de l'écran, là où un
  bandeau tient la légende, le titre et les chiffres en un seul pavé.
*/
@media (max-width: 1023px) {
    .header-band {
        padding: 17px 18px 18px;

        border-radius: 16px;

        background: var(--officine-dark);
    }

    .header-eyebrow {
        /*
          0.72 et non 0.55 : sur --officine-dark, 0.55 donne 3,69:1, sous le
          seuil AA de 4,5:1 pour un texte de 11,5 px. 0.72 donne 5,08:1 et se
          lit toujours comme secondaire.
        */
        color: rgb(255 255 255 / 0.72);
    }

    .header-title {
        font-size: 27px;
        line-height: 1.08;

        color: #fff;
    }

    .header-hero {
        margin-top: 16px;
    }
}
</style>
