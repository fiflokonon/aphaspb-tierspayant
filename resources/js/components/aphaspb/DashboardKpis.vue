<script setup lang="ts">
import { Clock } from '@lucide/vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import { formatMillions } from '@/lib/millions';
import type { KpiTone } from '@/types/aphaspb';

/**
 * Les trois chiffres d'ouverture, rendus à deux endroits.
 *
 * Source unique pour deux emplacements : sur grand écran la rangée reste
 * après les bandes, parce que le rouge doit frapper en premier ; sous
 * 1024 px elle rejoint le bandeau vert de l'en-tête, parce que trois cartes
 * empilées y repousseraient les bandes hors de l'écran. L'ordre approuvé
 * diffère donc selon la largeur, et le slot `#hero` ne rend qu'à un endroit.
 *
 * Chaque emplacement est masqué à la largeur de l'autre par `display: none`,
 * qui retire aussi l'élément de l'arbre d'accessibilité : les trois chiffres
 * ne sont jamais annoncés deux fois.
 */
defineProps<{
    summary: {
        invoiced: number;
        received: number;
        recoveryRate: number | null;
        weightedDelayDays: number | null;
        insurers: number;
        declarations: number;
    };
    /**
     * `band` rend les cartes lisibles sur le bandeau vert.
     *
     * Nommée `surface` et non `tone` : `KpiCard` a déjà une prop `tone`, au
     * sens sémantique — neutre, bon, alerte — qui colore la valeur. Les deux
     * coexistent sur la même carte.
     */
    surface: 'light' | 'band';
}>();

const recoveryTone = (rate: number | null): KpiTone => {
    if (rate === null) {
        return 'neutral';
    }

    return rate >= 80 ? 'good' : rate >= 60 ? 'warn' : 'bad';
};

const delayTone = (days: number | null): KpiTone => {
    if (days === null) {
        return 'neutral';
    }

    return days <= 30 ? 'good' : days <= 60 ? 'warn' : 'bad';
};
</script>

<template>
    <KpiRow
        :columns="3"
        class="dashboard-kpis"
        :class="surface === 'band' ? 'on-band' : ''"
    >
        <div class="dashboard-kpi-wrapper">
            <div class="kpi-side-accent"></div>

            <KpiCard
                label="FACTURÉ SUR 12 MOIS"
                :value="formatMillions(summary.invoiced)"
                unit="FCFA"
                :surface="surface"
                :hint="`${summary.insurers} assureurs · ${summary.declarations} déclarations`"
            />

            <div class="kpi-icon">
                <span>₣</span>
            </div>
        </div>

        <div class="dashboard-kpi-wrapper">
            <div class="kpi-side-accent teal"></div>

            <KpiCard
                label="TAUX DE RECOUVREMENT"
                :value="summary.recoveryRate?.toLocaleString('fr-FR') ?? '—'"
                unit="%"
                :tone="recoveryTone(summary.recoveryRate)"
                :surface="surface"
                :hint="`${formatMillions(summary.received)} FCFA encaissés`"
            />

            <div class="kpi-icon teal">
                <span>✓</span>
            </div>
        </div>

        <div class="dashboard-kpi-wrapper">
            <div class="kpi-side-accent gold"></div>

            <KpiCard
                label="VOTRE DÉLAI MOYEN"
                :value="
                    summary.weightedDelayDays?.toLocaleString('fr-FR') ?? '—'
                "
                unit="jours"
                :tone="delayTone(summary.weightedDelayDays)"
                :surface="surface"
                hint="pondéré par les montants reçus"
            />

            <div class="kpi-icon gold">
                <Clock :size="16" />
            </div>
        </div>
    </KpiRow>
</template>

<style scoped>
.dashboard-kpis {
    margin-bottom: 22px;
}

.dashboard-kpi-wrapper {
    position: relative;

    overflow: hidden;

    border-radius: 16px;

    animation: cardAppear 0.55s ease both;

    transition:
        transform 0.3s ease,
        box-shadow 0.3s ease;
}

.dashboard-kpi-wrapper:nth-child(2) {
    animation-delay: 0.08s;
}

.dashboard-kpi-wrapper:nth-child(3) {
    animation-delay: 0.16s;
}

.dashboard-kpi-wrapper:hover {
    transform: translateY(-4px);

    box-shadow: var(--surface-shadow-raised);
}

.kpi-side-accent {
    position: absolute;

    z-index: 5;

    left: 0;
    top: 17px;
    bottom: 17px;

    width: 3px;

    border-radius: 0 5px 5px 0;

    background: var(--primary);
}

.kpi-side-accent.teal {
    background: var(--primary);
}

.kpi-side-accent.gold {
    background: var(--gold);
}

.kpi-icon {
    position: absolute;

    top: 16px;
    right: 16px;

    width: 37px;
    height: 37px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;

    background: var(--primary-soft);

    color: var(--primary);

    pointer-events: none;

    transition: transform 0.3s ease;
}

.kpi-icon.teal {
    background: var(--primary-soft);

    color: var(--primary);
}

.kpi-icon.gold {
    background: var(--gold-soft);

    color: var(--gold);
}

.dashboard-kpi-wrapper:hover .kpi-icon {
    transform: rotate(8deg) scale(1.08);
}

/*
  L'animation d'entrée vit ici parce que les cartes y vivent. Copiée telle
  quelle de Dashboard.vue lors de l'extraction : la première version de ce
  composant l'avait réécrite de mémoire, et y avait perdu l'animation, le
  survol, le rognage du wrapper et quatre valeurs de jeton.
*/
@keyframes cardAppear {
    from {
        opacity: 0;
        transform: translateY(10px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/*
  Sur le bandeau vert, les cartes s'effacent : le fond porte déjà la couleur,
  et un accent latéral coloré sur un aplat vert ne se lit plus.
*/
.on-band .kpi-side-accent,
.on-band .kpi-icon {
    display: none;
}

.on-band .dashboard-kpi-wrapper {
    overflow: visible;
    border-radius: 0;

    /* min-width: 0 — sans quoi le min-content d'un montant long déborde la
       piste de grille et fait défiler la page à 320 px. */
    min-width: 0;
}

.on-band .dashboard-kpi-wrapper:hover {
    transform: none;
    box-shadow: none;
}

/*
  Les trois chiffres côte à côte dans le bandeau, même à 320 px.

  KpiRow empile à une colonne sous 640 px, ce qui donnait un bandeau de
  450 px de haut et repoussait les bandes d'alerte hors de l'écran — le
  défaut même que ce bandeau existe pour éviter. KpiCard rétrécit son texte
  lui-même via sa prop `surface`, plutôt qu'un :deep() sur ses classes
  utilitaires Tailwind.
*/
.on-band {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;

    /* Le bandeau porte déjà son propre rembourrage. */
    margin-top: 0;
    margin-bottom: 0;
}

/*
  Le bandeau disparaît au-dessus de 1024 px, et cette règle vit ici plutôt
  que dans la page : les deux déclarations de `display` avaient la même
  spécificité dans deux fichiers, et seul l'ordre d'émission de Vite les
  départageait. Un changement de découpage aurait remis les chiffres du
  bandeau sur l'écran de bureau.
*/
@media (min-width: 1024px) {
    .on-band {
        display: none;
    }
}
</style>
