<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type EvaluableInsurer = {
    id: number;
    name: string;
    total: number | null;
    authorName: string | null;
    updatedOn: string | null;
    url: string;
};

type NetworkRow = {
    insurerName: string;
    withheld: boolean;
    total: number | null;
    ownTotal: number | null;
};

const props = defineProps<{
    campaign: { year: number; closesOn: string } | null;
    insurers: EvaluableInsurer[];
    history: {
        year: number;
        insurers: { name: string; total: number }[];
    }[];
    network: { year: number; insurers: NetworkRow[] } | null;
    totalPoints: number;
}>();

const frDate = (iso: string) => new Date(iso).toLocaleDateString('fr-FR');

const evaluatedCount = computed(
    () => props.insurers.filter((insurer) => insurer.total !== null).length,
);
</script>

<template>
    <Head title="Évaluer mes assureurs" />

    <div class="flex flex-col gap-6">
        <ConsoleHeader title="Évaluer mes assureurs" />

        <section v-if="campaign" class="flex flex-col gap-3">
            <p class="max-w-[720px] text-[14px]/[1.55] text-ink/60">
                La campagne d'évaluation {{ campaign.year }} est ouverte
                jusqu'au {{ frDate(campaign.closesOn) }}.
                {{ evaluatedCount }} assureur{{
                    evaluatedCount > 1 ? 's' : ''
                }}
                évalué{{ evaluatedCount > 1 ? 's' : '' }} sur
                {{ insurers.length }}. Vos notes ne sont jamais publiées sous le
                nom de votre officine.
            </p>

            <p
                v-if="insurers.length === 0"
                class="rounded-[var(--radius-card)] border border-dashed border-ink/[0.15] p-6 text-[14px] text-ink/50"
            >
                Aucun assureur à évaluer : cochez vos assureurs dans « Mes
                assureurs ».
            </p>

            <Link
                v-for="insurer in insurers"
                :key="insurer.id"
                :href="insurer.url"
                class="flex items-center gap-4 rounded-[var(--radius-card)] bg-card p-5 shadow-[var(--surface-shadow)] transition-shadow hover:shadow-[var(--surface-shadow-raised)]"
            >
                <div class="flex-1">
                    <h2 class="text-[15px] font-bold text-ink">
                        {{ insurer.name }}
                    </h2>
                    <p class="text-[12.5px] text-ink/50">
                        <template v-if="insurer.total !== null">
                            Évalué le {{ frDate(insurer.updatedOn!) }} par
                            {{ insurer.authorName }}
                        </template>
                        <template v-else>À évaluer</template>
                    </p>
                </div>

                <span
                    v-if="insurer.total !== null"
                    class="font-serif text-[24px] text-ink"
                >
                    {{ insurer.total
                    }}<span class="text-[14px] text-ink/45"
                        >/{{ totalPoints }}</span
                    >
                </span>
                <span
                    v-else
                    class="rounded-full bg-gold/20 px-3 py-[4px] text-[12.5px] font-semibold text-gold-ink"
                >
                    Évaluer
                </span>

                <ChevronRight
                    :size="16"
                    class="text-ink/40"
                    aria-hidden="true"
                />
            </Link>
        </section>

        <p
            v-else
            class="max-w-[720px] rounded-[var(--radius-card)] border border-dashed border-ink/[0.15] p-6 text-[14px] text-ink/60"
        >
            Aucune campagne d'évaluation n'est ouverte. L'APhaSPB ouvre une
            campagne par an : vous pourrez alors noter chacun de vos assureurs.
        </p>

        <section
            v-if="network"
            class="rounded-[var(--radius-card)] bg-card p-5 shadow-[var(--surface-shadow)]"
        >
            <h2 class="text-[15px] font-bold text-ink">
                Résultats du réseau — campagne {{ network.year }}
            </h2>
            <p class="mb-3 text-[12.5px] text-ink/50">
                Moyenne des évaluations des officines, publiée seulement quand
                assez d'officines ont évalué l'assureur.
            </p>

            <table class="w-full text-[13px]">
                <thead>
                    <tr
                        class="border-b border-ink/[0.08] text-left tracking-[0.14em] text-[var(--text-label)] text-ink/45 uppercase"
                    >
                        <th class="py-2 font-semibold">Assureur</th>
                        <th class="py-2 text-right font-semibold">Réseau</th>
                        <th class="py-2 text-right font-semibold">
                            Votre note
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in network.insurers"
                        :key="row.insurerName"
                        class="border-b border-ink/[0.05] last:border-b-0"
                    >
                        <td class="py-2 text-ink">{{ row.insurerName }}</td>
                        <td class="py-2 text-right">
                            <span v-if="row.withheld" class="text-ink/45">
                                retenu
                            </span>
                            <span v-else class="font-semibold text-ink">
                                {{ row.total?.toLocaleString('fr-FR') }}/{{
                                    totalPoints
                                }}
                            </span>
                        </td>
                        <td class="py-2 text-right text-ink/70">
                            {{
                                row.ownTotal === null
                                    ? '—'
                                    : `${row.ownTotal}/${totalPoints}`
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-if="history.length > 0" class="flex flex-col gap-3">
            <h2 class="text-[15px] font-bold text-ink">
                Mes évaluations passées
            </h2>

            <article
                v-for="campaignHistory in history"
                :key="campaignHistory.year"
                class="rounded-[var(--radius-card)] bg-card p-5 shadow-[var(--surface-shadow)]"
            >
                <h3 class="mb-2 text-[13px] font-bold text-ink/70">
                    Campagne {{ campaignHistory.year }}
                </h3>
                <ul class="flex flex-wrap gap-[6px]">
                    <li
                        v-for="row in campaignHistory.insurers"
                        :key="row.name"
                        class="rounded-full bg-cream-state px-[10px] py-[4px] text-[12.5px] font-medium text-ink/75"
                    >
                        {{ row.name }} · {{ row.total }}/{{ totalPoints }}
                    </li>
                </ul>
            </article>
        </section>
    </div>
</template>
