<script setup lang="ts">
/**
 * Ce que chaque assureur doit encore au réseau en pénalités, à ce jour.
 *
 * Aucune rétention n'est décidée ici : un assureur ou ses montants arrivent
 * déjà retenus (NetworkPenaltyClaims reprend les décisions de l'export
 * réseau) et s'écrivent « retenu », avec la raison. Le seul total est celui
 * des lignes publiées : un total réseau, moins elles, rendrait les retenues.
 */
import { EyeOff } from '@lucide/vue';
import { formatAmount } from '@/lib/fcfa';
import type {
    NetworkPenaltyClaim,
    NetworkPenaltyClaims,
} from '@/types/aphaspb';

defineProps<{ claims: NetworkPenaltyClaims }>();

const percent = (value: number | null): string =>
    value === null
        ? '—'
        : `${value.toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`;

const money = (claim: NetworkPenaltyClaim, value: number | null): string =>
    claim.withheld !== null ? 'retenu' : `${formatAmount(value)} F`;

const delay = (claim: NetworkPenaltyClaim): string => {
    if (claim.withheld === 'insurer') {
        return 'retenu';
    }

    return claim.longestDelayDays === null
        ? '—'
        : `${claim.longestDelayDays} j`;
};
</script>

<template>
    <section class="flex flex-col gap-4">
        <div
            class="flex flex-wrap items-end justify-between gap-4 rounded-[var(--radius-card)] bg-card px-5 py-4 shadow-[var(--surface-shadow)]"
        >
            <div>
                <p
                    class="font-mono text-label tracking-[0.14em] text-ink/60 uppercase"
                >
                    Pénalité exigible au {{ claims.asOf }}
                </p>
                <p class="mt-1 text-figure font-bold text-ink tabular-nums">
                    {{ formatAmount(claims.publishedDue) }}
                    <span class="text-body font-semibold text-ink/60"
                        >FCFA</span
                    >
                </p>
                <p class="mt-1 text-meta text-ink/60">
                    Somme des assureurs publiés, sur les factures de la période.
                </p>
            </div>
        </div>

        <p
            v-if="claims.insurers.length === 0"
            class="rounded-[var(--radius-card)] bg-card px-5 py-6 text-center text-body text-ink/70 shadow-[var(--surface-shadow)]"
        >
            Aucun assureur sous convention n'a de facture sur ce périmètre.
        </p>

        <div
            v-else
            class="overflow-x-auto rounded-[var(--radius-card)] bg-card shadow-[var(--surface-shadow)]"
        >
            <table class="w-full min-w-[640px] text-body">
                <thead>
                    <tr
                        class="border-b border-border text-left font-mono text-label tracking-[0.14em] text-ink/60 uppercase"
                    >
                        <th class="px-5 py-3 font-semibold">Assureur</th>
                        <th class="px-3 py-3 font-semibold">Convention</th>
                        <th class="px-3 py-3 text-right font-semibold">
                            Exigible
                        </th>
                        <th class="px-3 py-3 text-right font-semibold">
                            Déjà payée
                        </th>
                        <th class="px-3 py-3 text-right font-semibold">
                            Abandonnée
                        </th>
                        <th class="px-5 py-3 text-right font-semibold">
                            Pire retard
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="claim in claims.insurers"
                        :key="claim.id"
                        class="border-b border-border last:border-b-0"
                    >
                        <td class="px-5 py-3 align-top">
                            <span class="font-semibold text-ink">{{
                                claim.name
                            }}</span>
                            <span
                                v-if="claim.note"
                                class="mt-0.5 flex items-start gap-1.5 text-meta text-ink/60"
                            >
                                <EyeOff
                                    class="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ claim.note }}
                            </span>
                        </td>
                        <td
                            class="px-3 py-3 align-top whitespace-nowrap text-ink/70"
                        >
                            {{ claim.triggerDays }} j ·
                            {{ percent(claim.ratePercent) }} / 30 j
                        </td>
                        <td
                            class="px-3 py-3 text-right align-top font-bold whitespace-nowrap text-ink tabular-nums"
                        >
                            {{ money(claim, claim.due) }}
                        </td>
                        <td
                            class="px-3 py-3 text-right align-top whitespace-nowrap text-ink/80 tabular-nums"
                        >
                            {{ money(claim, claim.recovered) }}
                        </td>
                        <td
                            class="px-3 py-3 text-right align-top whitespace-nowrap text-ink/80 tabular-nums"
                        >
                            {{ money(claim, claim.waived) }}
                        </td>
                        <td
                            class="px-5 py-3 text-right align-top whitespace-nowrap text-ink/80 tabular-nums"
                        >
                            {{ delay(claim) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-meta text-ink/60">
            Pire retard : l'âge de la plus ancienne facture encore due, ou le
            plus long délai de paiement. Un assureur, ou ses montants, sont
            retenus quand ils reposeraient sur moins de
            {{ claims.threshold }} officines.
        </p>
    </section>
</template>
