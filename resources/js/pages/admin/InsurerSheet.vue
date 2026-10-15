<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type PharmacyRow = { name: string; slug: string; city: string | null };

const props = defineProps<{
    insurer: {
        id: number;
        name: string;
        isActive: boolean;
        standardDelayDays: number;
        penaltyTriggerDays: number | null;
        penaltyRatePercent: number | null;
    };
    partners: PharmacyRow[];
    creditors: PharmacyRow[];
    outstanding: {
        withheld: boolean;
        withheldReason: 'too-few' | 'city-share' | null;
        required: number;
        amount: number | null;
    };
}>();

const amount = computed(() =>
    props.outstanding.amount === null
        ? null
        : new Intl.NumberFormat('fr-FR').format(props.outstanding.amount) +
          ' FCFA',
);

const withheldExplanation = computed(() =>
    props.outstanding.withheldReason === 'city-share'
        ? `Retenu : les officines créancières de villes peu représentées sont moins de ${props.outstanding.required}.`
        : `Retenu : moins de ${props.outstanding.required} officines créancières.`,
);

const clause = computed(() =>
    props.insurer.penaltyTriggerDays === null
        ? 'Aucune clause de pénalité'
        : `${props.insurer.penaltyRatePercent} % après ${props.insurer.penaltyTriggerDays} j`,
);
</script>

<template>
    <Head :title="insurer.name" />

    <div class="flex flex-col gap-6">
        <ConsoleHeader eyebrow="FICHE ASSUREUR" :title="insurer.name" />

        <Link
            href="/admin/insurers"
            class="flex items-center gap-1 self-start text-[13px] font-semibold text-ink/60 hover:text-ink"
        >
            <ArrowLeft :size="14" aria-hidden="true" />
            Gestion des assureurs
        </Link>

        <section class="grid gap-4 sm:grid-cols-3">
            <div
                class="rounded-[var(--radius-card)] border border-ink/[0.06] bg-card p-5 shadow-[var(--surface-shadow)]"
            >
                <div
                    class="font-mono text-[10.5px] font-semibold tracking-[0.05em] text-ink/45"
                >
                    RESTE DÛ AUX OFFICINES
                </div>
                <div
                    v-if="amount !== null"
                    class="mt-2 text-[24px] font-bold text-ink"
                >
                    {{ amount }}
                </div>
                <template v-else>
                    <div class="mt-2 text-[20px] font-bold text-ink/40">
                        Non publié
                    </div>
                    <p class="mt-1 text-[13px] text-ink/50">
                        {{ withheldExplanation }}
                    </p>
                </template>
            </div>

            <div
                class="rounded-[var(--radius-card)] border border-ink/[0.06] bg-card p-5 shadow-[var(--surface-shadow)]"
            >
                <div
                    class="font-mono text-[10.5px] font-semibold tracking-[0.05em] text-ink/45"
                >
                    DÉLAI STANDARD
                </div>
                <div class="mt-2 text-[24px] font-bold text-ink">
                    {{ insurer.standardDelayDays }} j
                </div>
            </div>

            <div
                class="rounded-[var(--radius-card)] border border-ink/[0.06] bg-card p-5 shadow-[var(--surface-shadow)]"
            >
                <div
                    class="font-mono text-[10.5px] font-semibold tracking-[0.05em] text-ink/45"
                >
                    CLAUSE DE PÉNALITÉ
                </div>
                <div class="mt-2 text-[16px] font-bold text-ink">
                    {{ clause }}
                </div>
                <div class="mt-1 text-[13px] text-ink/50">
                    {{
                        insurer.isActive
                            ? 'Partenaire actif'
                            : 'Partenaire désactivé'
                    }}
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section
                v-for="block in [
                    {
                        title: 'Officines auxquelles il doit de l’argent',
                        rows: creditors,
                        empty: 'Aucune créance en cours.',
                    },
                    {
                        title: 'Officines qui travaillent avec lui',
                        rows: partners,
                        empty: 'Aucune officine ne l’a déclaré.',
                    },
                ]"
                :key="block.title"
                class="rounded-[var(--radius-card)] border border-ink/[0.06] bg-card shadow-[var(--surface-shadow)]"
            >
                <h2
                    class="flex items-baseline gap-2 border-b border-ink/[0.06] px-5 py-4 text-[15px] font-bold text-ink"
                >
                    {{ block.title }}
                    <span class="text-[13px] font-semibold text-ink/45">
                        {{ block.rows.length }}
                    </span>
                </h2>

                <p
                    v-if="block.rows.length === 0"
                    class="px-5 py-4 text-[14px] text-ink/50"
                >
                    {{ block.empty }}
                </p>

                <ul v-else class="max-h-[420px] overflow-y-auto">
                    <li
                        v-for="row in block.rows"
                        :key="row.slug"
                        class="flex items-center gap-3 border-t border-ink/[0.05] px-5 py-3 first:border-t-0"
                    >
                        <Link
                            :href="`/admin/pharmacies/${row.slug}`"
                            class="flex-1 text-[14px] font-semibold text-ink hover:text-primary"
                        >
                            {{ row.name }}
                        </Link>
                        <span class="text-[13px] text-ink/50">
                            {{ row.city ?? '—' }}
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <p class="text-[13px] text-ink/45">
            Aucun montant n'est affiché par officine. Le total n'est publié qu'à
            partir de {{ outstanding.required }} officines créancières.
        </p>
    </div>
</template>
