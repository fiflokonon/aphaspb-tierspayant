<script setup lang="ts">
import ConsoleSpaceChip from './ConsoleSpaceChip.vue';

defineProps<{
    /**
     * La légende propre à l'écran — « MON COMPTE », « CE QUI VOUS ATTEND ».
     * L'espace, lui, n'est plus annoncé ici : il vit dans la coquille, à côté
     * du compte (voir ConsoleSpaceChip).
     */
    eyebrow?: string;
    title: string;
}>();
</script>

<template>
    <div class="console-header">
        <div class="header-band">
            <!--
                Sous 1024 px seulement : le bandeau supérieur, qui porte la
                puce d'espace à partir de lg, n'existe pas ici. Même composant,
                deux emplacements exclusifs — comme le menu de compte.
            -->
            <ConsoleSpaceChip class="on-band header-space" />

            <div v-if="eyebrow" class="header-eyebrow">{{ eyebrow }}</div>

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

/*
  La puce d'espace n'apparaît ici que dans le bandeau vert : au-dessus de
  1024 px, c'est ConsoleTopBar qui la porte, et l'afficher aux deux endroits
  ferait deux fois le même repère sur le même écran.
*/
.header-space {
    display: none;
}

.header-title {
    /*
      Rien au-dessus dans le cas courant : la marge n'apparaît que sous une
      étiquette d'écran, seul élément qui précède encore le titre à partir de
      lg. Dans le bandeau vert, c'est la puce qui pose son propre espacement.
    */
    margin-top: 0;

    font-family: var(--font-serif, ui-serif, Georgia, serif);
    font-size: 34px;
    line-height: 1.06;

    color: var(--ink);
}

.header-eyebrow + .header-title {
    margin-top: 8px;
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

    /*
      Sinon la grille du hero colle au titre de 34 px. `:empty` en plus du
      v-if : sur grand écran le tableau de bord remplit bien ce slot, mais
      son contenu y est masqué, et le conteneur laissait 18 px de vide.
    */
    .header-hero:not(:empty) {
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
@media (max-width: 1023.98px) {
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

    .header-space {
        display: inline-flex;

        margin-bottom: 7px;
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
