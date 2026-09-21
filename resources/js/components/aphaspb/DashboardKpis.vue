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
    position: relative;
}

.dashboard-kpi-wrapper {
    position: relative;
}

.kpi-side-accent {
    position: absolute;
    left: 0;
    top: 12px;
    bottom: 12px;

    width: 3px;

    border-radius: 0 3px 3px 0;

    background: var(--primary);
}

.kpi-side-accent.teal {
    background: var(--officine-dark);
}

.kpi-side-accent.gold {
    background: var(--gold-mid);
}

.kpi-icon {
    position: absolute;
    top: 14px;
    right: 14px;

    display: flex;
    align-items: center;
    justify-content: center;

    width: 30px;
    height: 30px;

    border-radius: 9px;

    background: var(--officine-soft);

    color: var(--primary);

    font-size: 14px;
    font-weight: 700;
}

.kpi-icon.gold {
    background: var(--gold-soft);

    color: var(--gold-dark);
}

/*
  Sur le bandeau vert, les cartes s'effacent : le fond porte déjà la couleur,
  et un accent latéral coloré sur un aplat vert ne se lit plus.
*/
.on-band .kpi-side-accent,
.on-band .kpi-icon {
    display: none;
}

.on-band {
    margin-top: 0;
}

/*
  Les trois chiffres côte à côte dans le bandeau, même à 390 px.

  KpiRow empile à une colonne sous 640 px, ce qui donnait un bandeau de
  450 px de haut et repoussait les bandes d'alerte hors de l'écran — le
  défaut même que ce bandeau existe pour éviter. La maquette les met sur une
  rangée ; KpiCard rétrécit son texte lui-même via sa prop `surface`, plutôt
  qu'un :deep() sur ses classes utilitaires — viser un utilitaire Tailwind
  casse au premier changement de classe.
*/
.on-band {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}
</style>
