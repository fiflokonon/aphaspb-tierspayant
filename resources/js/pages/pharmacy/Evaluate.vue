<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Criterion = {
    key: string;
    letter: string;
    label: string;
    maxPoints: number;
};

type Suggestions = {
    paymentDelay: {
        points: number | null;
        share: number | null;
        standardDelayDays: number;
        unavailableReason: string | null;
    };
    rejections: { declarations: number; rejected: number };
    penalties: {
        hasClause: boolean;
        due: number;
        paid: number;
        waived: number;
    };
};

const props = defineProps<{
    campaign: { year: number; closesOn: string };
    insurer: { id: number; name: string };
    criteria: Criterion[];
    totalPoints: number;
    scores: Record<string, number> | null;
    authorName: string | null;
    suggestions: Suggestions;
    paymentDelayScale: string;
    submitUrl: string;
    backUrl: string;
}>();

/**
 * Une évaluation déjà enregistrée se rouvre telle quelle. Sinon, seul le
 * délai de règlement part pré-rempli, de la note proposée : les sept autres
 * critères attendent l'officine, et un zéro par défaut serait une note
 * qu'elle n'a pas donnée.
 */
const form = useForm<Record<string, number | null>>(
    Object.fromEntries(
        props.criteria.map((criterion) => [
            criterion.key,
            props.scores?.[criterion.key] ??
                (criterion.key === 'payment_delay'
                    ? props.suggestions.paymentDelay.points
                    : null),
        ]),
    ),
);

const total = computed(() =>
    props.criteria.reduce(
        (sum, criterion) => sum + (form[criterion.key] ?? 0),
        0,
    ),
);

const remaining = computed(
    () =>
        props.criteria.filter((criterion) => form[criterion.key] === null)
            .length,
);

const closesOn = computed(() =>
    new Date(props.campaign.closesOn).toLocaleDateString('fr-FR'),
);

const delay = computed(() => props.suggestions.paymentDelay);

const overridesSuggestion = computed(
    () =>
        delay.value.points !== null &&
        form.payment_delay !== null &&
        form.payment_delay !== delay.value.points,
);

function points(criterion: Criterion): number[] {
    return Array.from({ length: criterion.maxPoints + 1 }, (_, i) => i);
}

function plural(count: number, word: string): string {
    return `${count} ${word}${count > 1 ? 's' : ''}`;
}

function submit() {
    form.put(props.submitUrl, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Évaluer ${insurer.name}`" />

    <div class="flex flex-col gap-6">
        <Link
            :href="backUrl"
            class="flex items-center gap-1 self-start text-[13px] font-semibold text-ink/60 hover:text-ink"
        >
            <ArrowLeft :size="14" aria-hidden="true" />
            Évaluer mes assureurs
        </Link>

        <ConsoleHeader
            :eyebrow="`CAMPAGNE ${campaign.year}`"
            :title="insurer.name"
        />

        <p class="max-w-[720px] text-[14px]/[1.55] text-ink/60">
            Notez les prestations de l'assureur sur l'année
            {{ campaign.year }}, critère par critère. La note du délai de
            règlement est proposée à partir de vos déclarations : validez-la ou
            corrigez-la. Vous pouvez modifier votre évaluation jusqu'au
            {{ closesOn }}.
            <template v-if="authorName">
                Dernière modification par {{ authorName }}.
            </template>
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <article
                v-for="criterion in criteria"
                :key="criterion.key"
                class="rounded-[var(--radius-card)] bg-card p-5 shadow-[var(--surface-shadow)]"
            >
                <div class="flex flex-wrap items-start gap-4">
                    <div class="min-w-[240px] flex-1">
                        <h2 class="text-[15px] font-bold text-ink">
                            {{ criterion.letter }}) {{ criterion.label }}
                        </h2>
                        <p class="text-[12.5px] text-ink/50">
                            Sur {{ criterion.maxPoints }} points
                        </p>
                    </div>

                    <div
                        class="flex gap-[6px]"
                        role="radiogroup"
                        :aria-label="criterion.label"
                    >
                        <button
                            v-for="value in points(criterion)"
                            :key="value"
                            type="button"
                            role="radio"
                            :aria-checked="form[criterion.key] === value"
                            class="h-[38px] min-w-[38px] rounded-[10px] border-[1.5px] px-2 text-[13px] font-bold transition-colors"
                            :class="
                                form[criterion.key] === value
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-ink/[0.13] text-ink/70 hover:bg-cream-state'
                            "
                            @click="form[criterion.key] = value"
                        >
                            {{ value }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="criterion.key === 'payment_delay'"
                    class="mt-4 rounded-[10px] bg-cream-state px-4 py-3 text-[13px]/[1.5] text-ink/75"
                >
                    <p
                        v-if="delay.points !== null"
                        class="flex items-start gap-2"
                    >
                        <Sparkles
                            :size="14"
                            class="mt-[3px] shrink-0 text-gold-dark"
                            aria-hidden="true"
                        />
                        <span>
                            Note proposée :
                            <strong
                                >{{ delay.points }}/{{
                                    criterion.maxPoints
                                }}</strong
                            >. En {{ campaign.year }},
                            {{ delay.share?.toLocaleString('fr-FR') }} % du
                            montant facturé a été versé dans le délai
                            conventionnel de {{ delay.standardDelayDays }}
                            jours.
                            <span
                                v-if="overridesSuggestion"
                                class="font-semibold text-terracotta-dark"
                            >
                                Vous avez modifié la note proposée.
                            </span>
                        </span>
                    </p>
                    <p v-else>
                        Pas de note proposée : {{ delay.unavailableReason }}
                    </p>
                    <p class="mt-1 text-[12.5px] text-ink/50">
                        Barème : {{ paymentDelayScale }}
                    </p>
                </div>

                <p
                    v-else-if="criterion.key === 'rejection_reasons'"
                    class="mt-4 rounded-[10px] bg-cream-state px-4 py-3 text-[13px]/[1.5] text-ink/75"
                >
                    Pour information : en {{ campaign.year }},
                    {{ plural(suggestions.rejections.rejected, 'facture') }}
                    rejetée{{ suggestions.rejections.rejected > 1 ? 's' : '' }}
                    sur
                    {{
                        plural(
                            suggestions.rejections.declarations,
                            'déclaration',
                        )
                    }}. Le système ne sait pas si le motif vous a été notifié.
                </p>

                <p
                    v-else-if="criterion.key === 'claims_handling'"
                    class="mt-4 rounded-[10px] bg-cream-state px-4 py-3 text-[13px]/[1.5] text-ink/75"
                >
                    <template v-if="suggestions.penalties.hasClause">
                        Pour information, sur les mois de
                        {{ campaign.year }} : pénalité de retard payée sur
                        {{ suggestions.penalties.paid }} mois, annulée sur
                        {{ suggestions.penalties.waived }}, encore due sur
                        {{ suggestions.penalties.due }}.
                    </template>
                    <template v-else>
                        Pour information : aucune clause de pénalité de retard
                        n'est enregistrée avec cet assureur.
                    </template>
                </p>

                <p
                    v-if="form.errors[criterion.key]"
                    class="mt-2 text-[13px] text-terracotta-dark"
                >
                    {{ form.errors[criterion.key] }}
                </p>
            </article>

            <div
                class="sticky bottom-4 flex flex-wrap items-center gap-4 rounded-[var(--radius-card)] bg-card p-4 shadow-[var(--surface-shadow-raised)]"
            >
                <p class="flex-1 text-[14px] text-ink/70">
                    Total :
                    <span class="font-serif text-[26px] text-ink">{{
                        total
                    }}</span>
                    / {{ totalPoints }}
                    <span
                        v-if="remaining > 0"
                        class="ml-2 text-[12.5px] text-ink/50"
                    >
                        · {{ plural(remaining, 'critère') }} à noter
                    </span>
                </p>

                <button
                    type="submit"
                    :disabled="form.processing || remaining > 0"
                    class="flex h-[42px] items-center justify-center rounded-[12px] bg-primary px-5 text-[12.5px] font-bold text-primary-foreground transition-opacity disabled:opacity-60"
                >
                    Valider l'évaluation
                </button>
            </div>
        </form>
    </div>
</template>
