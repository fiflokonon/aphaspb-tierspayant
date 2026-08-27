<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AmountField from '@/components/aphaspb/AmountField.vue';
import DelayStepper from '@/components/aphaspb/DelayStepper.vue';
import DerivedStatusNotice from '@/components/aphaspb/DerivedStatusNotice.vue';
import WizardProgress from '@/components/aphaspb/WizardProgress.vue';
import type { DeclarationStatus } from '@/types/aphaspb';

type Declaration = {
    amount_invoiced: number;
    amount_received: number;
    status: DeclarationStatus;
    is_status_manual: boolean;
    delay_days: number | null;
    private_note: string | null;
};

const props = defineProps<{
    insurer: { id: number; name: string };
    progress: { current: number; total: number };
    period: { year: number; month: number; label: string };
    declaration: Declaration | null;
}>();

const invoiced = ref(props.declaration?.amount_invoiced ?? 0);
const received = ref(props.declaration?.amount_received ?? 0);
const delay = ref<number | null>(props.declaration?.delay_days ?? null);
const note = ref(props.declaration?.private_note ?? '');
const noteOpen = ref(!!props.declaration?.private_note);
const rejected = ref(props.declaration?.status === 'rejected');

const LABELS: Record<DeclarationStatus, string> = {
    paid: 'Payé',
    partial: 'Paiement partiel',
    unpaid: 'Non payé',
    rejected: 'Facture rejetée',
};

/**
 * Mirrors DeclarationStatus::derive() so the pharmacist sees the consequence as
 * they type. The server recomputes it on save — the client never decides.
 */
const status = computed<DeclarationStatus>(() => {
    if (rejected.value) {
        return 'rejected';
    }

    if (received.value === 0) {
        return 'unpaid';
    }

    return received.value >= invoiced.value ? 'paid' : 'partial';
});

const settledShare = computed(() =>
    invoiced.value === 0 ? 0 : (received.value / invoiced.value) * 100,
);

const outstanding = computed(() =>
    Math.max(0, invoiced.value - received.value),
);

const carriesDelay = computed(
    () => status.value === 'paid' || status.value === 'partial',
);

const isLast = computed(() => props.progress.current >= props.progress.total);
</script>

<template>
    <Head :title="`Déclarer · ${insurer.name}`" />

    <!--
        Two shapes, one flow. On a phone the artboard's single column is the
        right answer. On a wide screen the same column would be a ribbon in a
        void, so the pair splits: what you type on the left, what it means on
        the right. The reading order is identical, only the axis changes.
    -->
    <div
        class="mx-auto flex w-full max-w-[440px] flex-col lg:h-full lg:max-w-[920px] lg:justify-center"
    >
        <div
            class="overflow-hidden lg:rounded-[14px] lg:border lg:border-border lg:bg-card lg:shadow-sm"
        >
            <div
                class="flex items-center gap-[10px] px-1 pt-2 lg:border-b lg:border-ink/[0.08] lg:bg-card lg:px-6 lg:py-4"
            >
                <Link
                    href="/pharmacy/history"
                    class="grid size-11 shrink-0 place-items-center text-[13px] text-ink/50 transition-colors hover:text-ink lg:hidden"
                    aria-label="Quitter la déclaration"
                >
                    ✕
                </Link>
                <WizardProgress
                    :total="progress.total"
                    :current="progress.current"
                />
                <Link
                    href="/pharmacy/history"
                    class="hidden shrink-0 text-[11.5px] font-semibold text-ink/50 transition-colors hover:text-ink lg:block"
                >
                    Reprendre plus tard
                </Link>
            </div>

            <Form
                action="/pharmacy/declare"
                method="post"
                class="lg:grid lg:grid-cols-[1.1fr_1fr] lg:items-stretch"
                #default="{ errors, processing }"
            >
                <input type="hidden" name="insurer_id" :value="insurer.id" />
                <input type="hidden" name="period_year" :value="period.year" />
                <input
                    type="hidden"
                    name="period_month"
                    :value="period.month"
                />
                <input
                    v-if="rejected"
                    type="hidden"
                    name="status"
                    value="rejected"
                />

                <div class="px-2 pt-6 lg:px-6 lg:py-7">
                    <div
                        class="font-mono text-[10.5px]/none font-semibold tracking-[0.08em] text-gold-dark"
                    >
                        {{ period.label }} · {{ insurer.name.toUpperCase() }}
                    </div>
                    <h1
                        class="mt-[13px] font-serif text-[26px]/[1.18] text-ink lg:text-[30px]/[1.15]"
                    >
                        Combien avez-vous facturé, et combien avez-vous
                        <em>réellement reçu</em> ?
                    </h1>

                    <div class="mt-6 flex flex-col gap-3">
                        <AmountField
                            v-model="invoiced"
                            label="MONTANT FACTURÉ"
                            name="amount_invoiced"
                            :error="errors.amount_invoiced"
                        />
                        <AmountField
                            v-model="received"
                            label="MONTANT REÇU"
                            name="amount_received"
                            :shortcut="{ label: 'Tout reçu', value: invoiced }"
                            :error="errors.amount_received"
                        />
                    </div>

                    <button
                        type="button"
                        class="mt-4 text-[11.5px] font-semibold text-ink/60 underline decoration-ink/20 underline-offset-4 transition-colors hover:text-ink"
                        @click="rejected = !rejected"
                    >
                        {{
                            rejected
                                ? 'Revenir au statut calculé'
                                : "L'assureur a rejeté la facture"
                        }}
                    </button>

                    <div class="mt-4">
                        <button
                            type="button"
                            class="text-[11.5px] font-semibold text-ink/60 transition-colors hover:text-ink"
                            @click="noteOpen = !noteOpen"
                        >
                            {{ noteOpen ? '− Note privée' : '+ Note privée' }}
                        </button>
                        <div v-if="noteOpen" class="mt-2">
                            <textarea
                                v-model="note"
                                name="private_note"
                                rows="2"
                                maxlength="150"
                                placeholder="Jamais vue par l'APhaSPB"
                                class="w-full rounded-xl border-[1.5px] border-ink/[0.13] bg-card px-3 py-[10px] text-[13px] text-ink outline-none focus:border-gold-mid/[0.55]"
                            />
                            <p
                                v-if="errors.private_note"
                                class="mt-[5px] text-[11px] text-terracotta-dark"
                            >
                                {{ errors.private_note }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="mt-4 flex flex-col gap-3 px-2 pb-6 lg:mt-0 lg:border-l lg:border-ink/[0.08] lg:bg-cream-header/60 lg:px-6 lg:py-7"
                >
                    <DerivedStatusNotice
                        :status="status"
                        :label="LABELS[status]"
                        :settled-share="settledShare"
                        :outstanding="outstanding"
                        :manual="rejected"
                    />

                    <DelayStepper
                        v-if="carriesDelay"
                        v-model="delay"
                        name="delay_days"
                        hint="Compté depuis le dépôt de la facture."
                        :error="errors.delay_days"
                    />

                    <p
                        v-if="errors.period || errors.insurer_id"
                        class="text-[11px]/[1.4] text-terracotta-dark"
                    >
                        {{ errors.period ?? errors.insurer_id }}
                    </p>

                    <!--
                        No mt-auto: with no delay stepper to show the panel is
                        short, and pinning the button to the bottom of a
                        stretched column opens a hole between it and the summary
                        it belongs to.
                    -->
                    <div class="pt-1">
                        <button
                            type="submit"
                            :disabled="processing"
                            class="flex h-13 w-full items-center justify-center rounded-xl bg-ink text-[14.5px] font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
                        >
                            {{
                                isLast
                                    ? 'Terminer le mois →'
                                    : 'Assureur suivant →'
                            }}
                        </button>
                        <p
                            class="mt-3 text-center text-[11px]/[1.45] text-ink/[0.45]"
                        >
                            Les montants restent dans votre espace. L'APhaSPB ne
                            reçoit que des totaux réseau.
                        </p>
                    </div>
                </div>
            </Form>
        </div>
    </div>
</template>
