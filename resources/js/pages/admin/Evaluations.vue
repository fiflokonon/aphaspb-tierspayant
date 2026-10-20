<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateField from '@/components/aphaspb/DateField.vue';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Campaign = {
    id: number;
    year: number;
    opensOn: string;
    closesOn: string;
    state: 'upcoming' | 'open' | 'closed';
    participants: number;
};

type Criterion = {
    key: string;
    letter: string;
    label: string;
    maxPoints: number;
};

type ResultRow = {
    insurerId: number;
    insurerName: string;
    withheld: boolean;
    evaluations: number | null;
    total: number | null;
    criteria: Record<string, number> | null;
};

const props = defineProps<{
    campaigns: Campaign[];
    selectedYear: number | null;
    results: ResultRow[] | null;
    criteria: Criterion[];
    totalPoints: number;
    threshold: number;
    resultsShared: boolean;
}>();

const STATE_LABELS: Record<Campaign['state'], string> = {
    upcoming: 'À venir',
    open: 'Ouverte',
    closed: 'Close',
};

/** La campagne en cours d'édition, ou null pour en créer une. */
const editing = ref<number | null>(null);

const form = useForm({
    year: String(new Date().getFullYear() - 1),
    opens_on: null as string | null,
    closes_on: null as string | null,
});

const selected = computed(
    () =>
        props.campaigns.find(
            (campaign) => campaign.year === props.selectedYear,
        ) ?? null,
);

const frDate = (iso: string) => new Date(iso).toLocaleDateString('fr-FR');

const average = (value: number | null | undefined) =>
    value === null || value === undefined ? '—' : value.toLocaleString('fr-FR');

function startEditing(campaign: Campaign) {
    editing.value = campaign.id;
    form.clearErrors();
    form.year = String(campaign.year);
    form.opens_on = campaign.opensOn;
    form.closes_on = campaign.closesOn;
}

function resetEditor() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function submit() {
    const options = { preserveScroll: true, onSuccess: resetEditor };

    if (editing.value === null) {
        form.post('/admin/evaluations', options);
    } else {
        form.patch(`/admin/evaluations/${editing.value}`, options);
    }
}

function toggleSharing() {
    router.patch(
        '/admin/settings/evaluation-sharing',
        { shared: !props.resultsShared },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Évaluation des assureurs" />

    <div class="flex flex-col gap-6">
        <ConsoleHeader title="Évaluation des assureurs" />

        <p class="max-w-[720px] text-[14px]/[1.55] text-ink/60">
            Chaque année, les officines évaluent leurs assureurs selon les huit
            critères du canevas (total sur {{ totalPoints }}). Ouvrez une
            campagne pour l'année à évaluer. Les moyennes ne sont calculées
            qu'une fois la campagne close, et seulement pour les assureurs
            évalués par au moins {{ threshold }} officines.
        </p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,380px)_1fr]">
            <div class="flex flex-col gap-6 self-start">
                <form
                    class="flex flex-col gap-4 rounded-[var(--radius-card)] bg-card p-5 shadow-[var(--surface-shadow)]"
                    @submit.prevent="submit"
                >
                    <h2 class="text-[15px] font-bold text-ink">
                        {{
                            editing === null
                                ? 'Nouvelle campagne'
                                : 'Modifier la campagne'
                        }}
                    </h2>

                    <FormField label="ANNÉE ÉVALUÉE" :error="form.errors.year">
                        <TextInput
                            v-model="form.year"
                            :invalid="!!form.errors.year"
                            placeholder="2025"
                        />
                    </FormField>

                    <DateField
                        v-model="form.opens_on"
                        label="OUVERTURE"
                        name="opens_on"
                        :error="form.errors.opens_on"
                        required
                    />

                    <DateField
                        v-model="form.closes_on"
                        label="CLÔTURE"
                        name="closes_on"
                        :error="form.errors.closes_on"
                        hint="Les officines peuvent répondre jusqu'à ce jour inclus."
                        required
                    />

                    <div class="flex gap-2">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="flex h-[42px] flex-1 items-center justify-center rounded-[12px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground transition-opacity disabled:opacity-60"
                        >
                            {{
                                editing === null
                                    ? 'Ouvrir la campagne'
                                    : 'Enregistrer'
                            }}
                        </button>

                        <button
                            v-if="editing !== null"
                            type="button"
                            class="h-[42px] rounded-[12px] border border-ink/[0.13] px-4 text-[12.5px] font-semibold text-ink/70"
                            @click="resetEditor"
                        >
                            Annuler
                        </button>
                    </div>
                </form>

                <section
                    class="flex flex-col gap-3 rounded-[var(--radius-card)] bg-card p-5 shadow-[var(--surface-shadow)]"
                >
                    <h2 class="text-[15px] font-bold text-ink">
                        Partage avec les officines
                    </h2>
                    <p class="text-[13px]/[1.5] text-ink/60">
                        {{
                            resultsShared
                                ? 'Les officines voient les moyennes du réseau de la dernière campagne close.'
                                : 'Les moyennes ne sont visibles que de l’administration.'
                        }}
                        Le seuil d'anonymat s'applique dans tous les cas.
                    </p>
                    <button
                        type="button"
                        class="h-[38px] self-start rounded-[10px] border border-ink/[0.13] px-4 text-[12.5px] font-semibold text-ink/75"
                        @click="toggleSharing"
                    >
                        {{
                            resultsShared
                                ? 'Ne plus partager les moyennes'
                                : 'Partager les moyennes'
                        }}
                    </button>
                </section>
            </div>

            <div class="flex flex-col gap-6">
                <section class="flex flex-col gap-3">
                    <p
                        v-if="campaigns.length === 0"
                        class="rounded-[var(--radius-card)] border border-dashed border-ink/[0.15] p-6 text-[14px] text-ink/50"
                    >
                        Aucune campagne pour l'instant.
                    </p>

                    <article
                        v-for="campaign in campaigns"
                        :key="campaign.id"
                        class="flex flex-wrap items-center gap-3 rounded-[var(--radius-card)] border bg-card p-5 shadow-[var(--surface-shadow)]"
                        :class="
                            editing === campaign.id
                                ? 'border-primary/40'
                                : 'border-transparent'
                        "
                    >
                        <div class="flex-1">
                            <h3 class="text-[15px] font-bold text-ink">
                                Campagne {{ campaign.year }}
                                <span
                                    class="ml-2 rounded-full px-[10px] py-[3px] align-middle text-[12px] font-semibold"
                                    :class="
                                        campaign.state === 'open'
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-cream-state text-ink/60'
                                    "
                                >
                                    {{ STATE_LABELS[campaign.state] }}
                                </span>
                            </h3>
                            <p class="text-[13px] text-ink/50">
                                Du {{ frDate(campaign.opensOn) }} au
                                {{ frDate(campaign.closesOn) }} ·
                                {{ campaign.participants }} officine{{
                                    campaign.participants > 1 ? 's' : ''
                                }}
                                ayant répondu
                            </p>
                        </div>

                        <Link
                            v-if="campaign.state === 'closed'"
                            :href="`/admin/evaluations?year=${campaign.year}`"
                            preserve-scroll
                            class="h-[34px] rounded-[10px] border border-ink/[0.13] px-3 text-[12.5px]/[30px] font-semibold text-ink/70"
                        >
                            Résultats
                        </Link>

                        <button
                            type="button"
                            class="h-[34px] rounded-[10px] border border-ink/[0.13] px-3 text-[12.5px] font-semibold text-ink/70"
                            @click="startEditing(campaign)"
                        >
                            Modifier
                        </button>
                    </article>
                </section>

                <section
                    v-if="selected"
                    class="rounded-[var(--radius-card)] bg-card p-5 shadow-[var(--surface-shadow)]"
                >
                    <h2 class="text-[15px] font-bold text-ink">
                        Résultats — campagne {{ selected.year }}
                    </h2>

                    <p
                        v-if="results === null"
                        class="mt-2 text-[13px] text-ink/60"
                    >
                        Les moyennes seront calculées à la clôture, le
                        {{ frDate(selected.closesOn) }}.
                    </p>

                    <p
                        v-else-if="results.length === 0"
                        class="mt-2 text-[13px] text-ink/60"
                    >
                        Aucune évaluation pour cette campagne.
                    </p>

                    <div v-else class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[720px] text-[13px]">
                            <thead>
                                <tr
                                    class="border-b border-ink/[0.08] text-left tracking-[0.14em] text-[var(--text-label)] text-ink/45 uppercase"
                                >
                                    <th class="py-2 font-semibold">Assureur</th>
                                    <th class="py-2 text-right font-semibold">
                                        Officines
                                    </th>
                                    <th
                                        v-for="criterion in criteria"
                                        :key="criterion.key"
                                        class="py-2 text-right font-semibold"
                                        :title="criterion.label"
                                    >
                                        {{ criterion.letter }}
                                        <span class="text-ink/30"
                                            >/{{ criterion.maxPoints }}</span
                                        >
                                    </th>
                                    <th class="py-2 text-right font-semibold">
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in results"
                                    :key="row.insurerId"
                                    class="border-b border-ink/[0.05] last:border-b-0"
                                >
                                    <td class="py-2 text-ink">
                                        {{ row.insurerName }}
                                    </td>
                                    <template v-if="row.withheld">
                                        <td
                                            :colspan="criteria.length + 2"
                                            class="py-2 text-right text-ink/45"
                                        >
                                            retenu — moins de
                                            {{ threshold }} officines
                                        </td>
                                    </template>
                                    <template v-else>
                                        <td class="py-2 text-right text-ink/70">
                                            {{ row.evaluations }}
                                        </td>
                                        <td
                                            v-for="criterion in criteria"
                                            :key="criterion.key"
                                            class="py-2 text-right text-ink/70"
                                        >
                                            {{
                                                average(
                                                    row.criteria?.[
                                                        criterion.key
                                                    ],
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="py-2 text-right font-bold text-ink"
                                        >
                                            {{ average(row.total) }}
                                        </td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>

                        <ul class="mt-4 grid gap-1 text-[12.5px] text-ink/50">
                            <li
                                v-for="criterion in criteria"
                                :key="criterion.key"
                            >
                                {{ criterion.letter }}) {{ criterion.label }}
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
