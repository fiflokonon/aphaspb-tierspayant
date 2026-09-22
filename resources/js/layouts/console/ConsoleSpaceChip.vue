<script setup lang="ts">
/**
 * L'espace où la session est ouverte : une officine nommée, ou le réseau.
 *
 * Dans la coquille, pas dans l'en-tête des écrans. Ce contexte ne change
 * jamais d'un écran à l'autre : le répéter au-dessus de chaque titre coûtait
 * trois lignes et trois polices — mono capitales, sans capitales, serif —
 * empilées avant d'arriver au nom de l'écran.
 *
 * Il se lit donc là où vit déjà ce qui ne change pas : à côté du compte, dans
 * le bandeau supérieur à partir de lg, dans le bandeau vert en dessous, où ce
 * bandeau n'existe pas. Deux emplacements exclusifs, un seul composant.
 *
 * Il vient du shell et non d'une prop : tous les écrans l'affichent, et le
 * faire voyager en prop obligerait autant de contrôleurs à le répéter.
 */
import { computed } from 'vue';
import { useConsoleShell } from '@/composables/useConsoleShell';

const { account } = useConsoleShell();

/**
 * L'officine se nomme elle-même et se situe par sa ville ; l'espace réseau
 * n'a ni l'une ni l'autre et se nomme par ce qu'il est.
 *
 * @return {{ name: string, complement: string | null }|null}
 */
const space = computed(() => {
    const pharmacy = account.value?.pharmacy ?? null;

    if (pharmacy !== null) {
        return { name: pharmacy.name, complement: pharmacy.city };
    }

    if (account.value?.administrator === true) {
        return {
            name: 'Espace administrateur',
            complement: 'Réseau des officines',
        };
    }

    return null;
});
</script>

<template>
    <div v-if="space" class="space-chip">
        <span class="space-dot"></span>

        <span class="space-name">{{ space.name }}</span>

        <span v-if="space.complement" class="space-complement">
            · {{ space.complement }}
        </span>
    </div>
</template>

<style scoped>
.space-chip {
    display: inline-flex;
    align-items: center;

    gap: 7px;

    min-width: 0;

    padding: 0 13px;

    height: 36px;

    border-radius: 10px;

    background: var(--cream-state);

    font-size: 14.5px;
    font-weight: 700;

    /* 80 % : 8,9:1 sur --cream. Le repère se lit, il ne crie pas. */
    color: color-mix(in srgb, var(--ink) 80%, transparent);

    white-space: nowrap;
}

.space-dot {
    flex-shrink: 0;

    width: 6px;
    height: 6px;

    border-radius: 999px;

    background: var(--officine);
}

/*
  Les capitales viennent de la CSS, pas de la chaîne : le nom reste lisible tel
  qu'il a été saisi pour un lecteur d'écran, et la casse d'origine survit à un
  copier-coller. Elles ont besoin d'air, d'où le tracking.
*/
.space-name {
    overflow: hidden;

    letter-spacing: 0.02em;

    text-overflow: ellipsis;
    text-transform: uppercase;
}

/*
  Le complément recule d'une graisse et d'un contraste, pas d'un corps : c'est
  le nom qu'on cherche du regard, mais la ville distingue deux officines
  homonymes. 55 % ne donne que 3,84:1 sur --cream, sous le seuil AA de
  4,5:1 ; 62 % donne 4,79:1.
*/
.space-complement {
    flex-shrink: 0;

    font-weight: 500;

    color: color-mix(in srgb, var(--ink) 62%, transparent);
}

@media (min-width: 1024px) {
    /* La hauteur du déclencheur de compte, à côté duquel la puce se pose. */
    .space-chip {
        height: 40px;
    }
}

/*
  Dans le bandeau vert, la puce n'a plus de fond à elle : deux surfaces
  emboîtées sur 343 px feraient un timbre dans un cadre. Elle garde son point
  et sa hiérarchie, posée à même le vert.
*/
.space-chip.on-band {
    /*
      Du texte, pas une rangée. Le bandeau fait 343 px : un nom long y passe à
      la ligne plutôt que de se faire couper — l'ellipse convient au bandeau
      supérieur, où la puce partage une rangée avec le compte et la cloche,
      ici elle amputerait le nom de l'officine sans raison. Et en `flex`, la
      ville restait accrochée à la première ligne pendant que le nom se
      poursuivait sous elle.
    */
    display: block;

    height: auto;

    padding: 0;

    background: transparent;

    line-height: 1.25;

    white-space: normal;

    /* 0.92 donne 7,27:1 sur --officine-dark. */
    color: rgb(255 255 255 / 0.92);
}

.space-chip.on-band .space-name {
    overflow: visible;
}

.space-chip.on-band .space-dot {
    display: inline-block;

    /* Le `gap` ne s'applique plus hors flex, et un point ne s'aligne pas sur
       une ligne de base : les deux se posent à la main. */
    margin-right: 7px;

    transform: translateY(-2px);

    background: rgb(255 255 255 / 0.55);
}

/* 0.72 donne 5,14:1 ; en dessous on passe sous AA. */
.space-chip.on-band .space-complement {
    color: rgb(255 255 255 / 0.72);
}
</style>
