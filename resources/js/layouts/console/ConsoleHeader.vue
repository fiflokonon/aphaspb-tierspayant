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
    display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px;
}
.header-band {
    min-width: 0;
    font-family: 'Manrope', sans-serif;
}

.header-eyebrow {
    font-family: 'Manrope', sans-serif;
    font-size: 11px;
    line-height: 1;
    font-weight: 700;
    letter-spacing: .10em;
    text-transform: uppercase;
    color: var(--officine);
}

.header-space {
    display: none;
}

.header-title {
    margin-top: 6px;
    font-family: 'Manrope', sans-serif;
    font-size: 21px;
    line-height: 1.12;
    font-weight: 700;
    color: var(--ink);
    letter-spacing: -.025em;
}

.header-eyebrow + .header-title {
    margin-top: 8px;
}

.header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.header-hero {
    margin-top: 16px;
}

@media (min-width: 1024px) {
    .console-header { flex-direction: row; align-items: flex-end; justify-content: space-between; gap: 24px; }
    .header-actions { flex-shrink: 0; justify-content: flex-end; }
    .header-hero:not(:empty) { margin-top: 18px; }
}

@media (max-width: 1023.98px) {
    .header-band { padding: 20px 20px 18px; border-radius: 18px; background: linear-gradient(135deg, var(--officine-deep), #0d4c40); box-shadow: 0 16px 34px -22px rgb(7 61 51 / .55); }
    .header-eyebrow { color: #8ed9cc; }
    .header-space { display: inline-flex; margin-bottom: 8px; }
    .header-title { font-size: 28px; line-height: 1.08; color: #fff; }
    .header-hero { margin-top: 15px; }
}
</style>
