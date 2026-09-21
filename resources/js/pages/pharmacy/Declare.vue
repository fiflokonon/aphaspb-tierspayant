<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AmountField from '@/components/aphaspb/AmountField.vue';
import DateField from '@/components/aphaspb/DateField.vue';
import DerivedStatusNotice from '@/components/aphaspb/DerivedStatusNotice.vue';
import PaymentInstalments from '@/components/aphaspb/PaymentInstalments.vue';
import type { Instalment } from '@/components/aphaspb/PaymentInstalments.vue';
import PeriodPicker from '@/components/aphaspb/PeriodPicker.vue';
import { formatFcfa } from '@/lib/fcfa';
import type { DeclarationStatus, SelectablePeriod } from '@/types/aphaspb';
import type { ConsoleAccount } from '@/types/console';

type Payment = {
    amount: number;
    paid_on: string;
    delay_days: number | null;
};

type Revision = {
    recordedAt: string | null;
    authorName: string;
    amountInvoiced: number;
    amountReceived: number;
    statusLabel: string;
    invoiceDepositedOn: string | null;
    delayDays: number | null;
    payments: { amount: number; paid_on: string; delay_days: number | null }[];
};

type Declaration = {
    amount_invoiced: number;
    amount_received: number;
    status: DeclarationStatus;
    is_status_manual: boolean;
    invoice_deposited_on: string | null;
    paid_on: string | null;
    delay_days: number | null;
    private_note: string | null;
    payments: Payment[];
    revisions: Revision[];
};

const props = defineProps<{
    insurer: { id: number; name: string; standardDelayDays: number };
    progress: { current: number; total: number };
    period: { year: number; month: number; label: string };
    periods: SelectablePeriod[];
    dateBounds: { earliest: string; latest: string };
    declaration: Declaration | null;
}>();

// The declaration owns the phone screen; the rail returns at lg.
setLayoutProps({ focus: true });

const invoiced = ref(props.declaration?.amount_invoiced ?? 0);
const depositedOn = ref<string | null>(
    props.declaration?.invoice_deposited_on ?? null,
);

/**
 * Les versements reçus, une ligne par virement.
 *
 * Le montant reçu n'est plus saisi : c'est leur somme, ici comme sur le
 * serveur. Une déclaration qui n'a encore rien encaissé part sans aucune
 * ligne — l'officine en ajoute une quand l'argent arrive.
 */
const instalments = ref<Instalment[]>(
    (props.declaration?.payments ?? []).map((payment) => ({
        amount: payment.amount,
        paidOn: payment.paid_on,
    })),
);

const received = computed(() =>
    instalments.value.reduce((sum, line) => sum + line.amount, 0),
);
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

/**
 * The server refuses this outright (the instalments may not exceed the invoice),
 * so deriving a status from it would be a confident lie about what saving will
 * do. It is an input error, surfaced where the eye already is.
 */
const exceedsInvoiced = computed(
    () => invoiced.value > 0 && received.value > invoiced.value,
);

const excess = computed(() => Math.max(0, received.value - invoiced.value));

const settledShare = computed(() =>
    invoiced.value === 0 ? 0 : (received.value / invoiced.value) * 100,
);

const outstanding = computed(() =>
    Math.max(0, invoiced.value - received.value),
);

const carriesDelay = computed(
    () => status.value === 'paid' || status.value === 'partial',
);

/**
 * La date du versement le plus récent, celle sur laquelle le mois est jugé.
 */
const lastPaidOn = computed<string | null>(() => {
    const dates = instalments.value
        .map((line) => line.paidOn)
        .filter((date): date is string => date !== null && date !== '');

    return dates.length === 0
        ? null
        : dates.reduce((latest, date) => (date > latest ? date : latest));
});

/**
 * Mirrors Declaration::deriveDelayDays(). The delay is no longer typed in: it
 * is the distance between the deposit and the **last** transfer received, and
 * the server recomputes it on save.
 */
const delay = computed<number | null>(() => {
    if (depositedOn.value === null || lastPaidOn.value === null) {
        return null;
    }

    const from = Date.parse(depositedOn.value);
    const to = Date.parse(lastPaidOn.value);

    if (Number.isNaN(from) || Number.isNaN(to) || to < from) {
        return null;
    }

    return Math.round((to - from) / 86_400_000);
});

const beyondStandardDelay = computed(
    () => delay.value !== null && delay.value > props.insurer.standardDelayDays,
);

const isLast = computed(() => props.progress.current >= props.progress.total);

const revisions = computed(() => props.declaration?.revisions ?? []);

/**
 * La première révision est l'état d'origine, pas une correction : une
 * déclaration enregistrée une fois puis laissée tranquille n'a pas été
 * « modifiée ».
 */
const correctionCount = computed(() => Math.max(0, revisions.value.length - 1));

const historyOpen = ref(false);

const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

function formatMoment(value: string | null): string {
    return value === null ? '—' : dateFormatter.format(new Date(value));
}

function formatDay(value: string | null): string {
    return value === null ? '—' : value.split('-').reverse().join('/');
}

/**
 * L'officine annoncée en haut à droite.
 *
 * Cet écran ne passe pas par ConsoleHeader — il a son propre en-tête — mais
 * il doit dire la même chose : c'est là qu'on saisit des montants au nom de
 * l'officine, et se tromper de session coûte cher.
 */
const officine = computed(() => {
    const account = (
        usePage().props.console as { account?: ConsoleAccount } | null
    )?.account;

    return account?.pharmacy?.name?.toUpperCase() ?? 'ESPACE OFFICINE';
});
</script>

<template>
    <Head :title="`Déclarer · ${insurer.name}`" />

    <!-- <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    /> -->

    <div class="declare-page">
        <div class="declare-header">
            <Link
                href="/pharmacy/history"
                class="back-link"
                aria-label="Retour à l'historique"
            >
                <span class="back-icon">←</span>
                <span>Historique</span>
            </Link>

            <div class="header-context">
                <span class="header-dot"></span>
                <span>{{ officine }}</span>
            </div>
        </div>

        <div class="declare-shell">
            <div class="progress-header">
                <div class="progress-left">
                    <div class="progress-badge">
                        <span>{{ progress.current }}</span>
                    </div>

                    <div>
                        <div class="progress-label">DÉCLARATION MENSUELLE</div>

                        <div class="progress-title">
                            Étape {{ progress.current }} sur
                            {{ progress.total }}
                        </div>
                    </div>
                </div>

                <Link href="/pharmacy/history" class="later-link">
                    Reprendre plus tard
                    <span>↗</span>
                </Link>
            </div>

            <div class="progress-track">
                <div
                    class="progress-value"
                    :style="{
                        width: `${(progress.current / progress.total) * 100}%`,
                    }"
                ></div>
            </div>

            <Form
                action="/pharmacy/declare"
                method="post"
                class="declare-form"
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

                <section class="form-panel form-panel-main">
                    <!--
                        L'assureur est le titre, pas la question.
                        On enchaîne les assureurs un à un avec « Assureur
                        suivant » : c'est lui qui change d'un écran à l'autre,
                        la question est la même à chaque fois. Se tromper
                        d'interlocuteur, c'est déclarer les mauvais chiffres.
                    -->
                    <div class="panel-intro">
                        <div class="eyebrow">{{ period.label }}</div>

                        <h1 class="insurer-title">{{ insurer.name }}</h1>

                        <p class="insurer-terms">
                            Délai de remboursement convenu :
                            <strong
                                >{{ insurer.standardDelayDays }} jours</strong
                            >
                        </p>

                        <p class="intro-text">
                            Combien avez-vous facturé, et combien avez-vous
                            <em>réellement reçu</em> ? Déclarez les montants
                            correspondant à ce mois : le statut de votre
                            règlement sera automatiquement calculé.
                        </p>

                        <PeriodPicker
                            :periods="periods"
                            :current="period"
                            class="mt-4"
                        />
                    </div>

                    <div class="amounts">
                        <div class="amount-wrapper">
                            <div class="amount-number">01</div>

                            <AmountField
                                v-model="invoiced"
                                label="MONTANT FACTURÉ"
                                name="amount_invoiced"
                                :error="errors.amount_invoiced"
                            />
                        </div>
                    </div>

                    <!--
                        Le dépôt de la facture tient avec le montant facturé :
                        c'est l'autre moitié de ce que l'officine a envoyé. Les
                        versements, eux, sont ce qui revient de l'assureur, et
                        forment leur propre bloc juste en dessous. Ce qui se
                        déduit des deux — le délai — s'affiche à droite, près
                        du statut auquel il appartient.
                    -->
                    <div class="dates">
                        <DateField
                            v-model="depositedOn"
                            class="date-field"
                            label="DÉPÔT DE LA FACTURE"
                            name="invoice_deposited_on"
                            :min="dateBounds.earliest"
                            :max="dateBounds.latest"
                            :error="errors.invoice_deposited_on"
                        />
                    </div>

                    <!--
                        Chaque ligne porte un `required` sur sa date : elle
                        n'existe que parce qu'un montant a été encaissé, et
                        c'est exactement le cas où SaveDeclarationRequest exige
                        une date. Le navigateur refuse l'envoi sur place, au
                        lieu d'un aller-retour serveur.
                    -->
                    <PaymentInstalments
                        v-model="instalments"
                        :invoiced="invoiced"
                        :min-date="depositedOn ?? dateBounds.earliest"
                        :max-date="dateBounds.latest"
                        :errors="errors"
                    />

                    <button
                        type="button"
                        class="secondary-action"
                        @click="rejected = !rejected"
                    >
                        <span class="secondary-icon">
                            {{ rejected ? '↩' : '!' }}
                        </span>

                        <span>
                            {{
                                rejected
                                    ? 'Revenir au statut calculé'
                                    : "L'assureur a rejeté la facture"
                            }}
                        </span>

                        <span class="secondary-arrow"> → </span>
                    </button>

                    <div class="private-note">
                        <button
                            type="button"
                            class="note-toggle"
                            @click="noteOpen = !noteOpen"
                        >
                            <span class="note-plus">
                                {{ noteOpen ? '−' : '+' }}
                            </span>

                            <span> Note privée </span>

                            <span class="note-description"> facultative </span>
                        </button>

                        <Transition name="note">
                            <div v-if="noteOpen" class="note-content">
                                <textarea
                                    v-model="note"
                                    name="private_note"
                                    rows="3"
                                    maxlength="150"
                                    placeholder="Ajoutez une précision visible uniquement par vous."
                                    class="note-textarea"
                                ></textarea>

                                <div class="note-footer">
                                    <span> Cette note reste privée. </span>

                                    <span> {{ note?.length ?? 0 }}/150 </span>
                                </div>

                                <p
                                    v-if="errors.private_note"
                                    class="field-error"
                                >
                                    {{ errors.private_note }}
                                </p>
                            </div>
                        </Transition>
                    </div>

                    <!--
                        L'historique tient sous la note, en bas du panneau de
                        saisie : il se consulte quand un chiffre est contesté,
                        pas à chaque déclaration. Replié par défaut, comme la
                        note privée juste au-dessus.
                    -->
                    <div v-if="correctionCount > 0" class="revision-history">
                        <button
                            type="button"
                            class="note-toggle"
                            @click="historyOpen = !historyOpen"
                        >
                            <span class="note-plus">
                                {{ historyOpen ? '−' : '+' }}
                            </span>

                            <span> Modifiée {{ correctionCount }} fois </span>

                            <span class="note-description">
                                dernière le
                                {{ formatMoment(revisions[0].recordedAt) }}
                            </span>
                        </button>

                        <Transition name="note">
                            <ol v-if="historyOpen" class="revision-list">
                                <li
                                    v-for="(revision, index) in revisions"
                                    :key="index"
                                    class="revision-item"
                                >
                                    <div class="revision-head">
                                        <span class="revision-moment">
                                            {{
                                                formatMoment(
                                                    revision.recordedAt,
                                                )
                                            }}
                                        </span>

                                        <span class="revision-author">
                                            {{ revision.authorName }}
                                        </span>

                                        <span
                                            v-if="
                                                index === revisions.length - 1
                                            "
                                            class="revision-origin"
                                        >
                                            déclaration initiale
                                        </span>
                                    </div>

                                    <div class="revision-figures">
                                        <span>
                                            {{
                                                formatFcfa(
                                                    revision.amountReceived,
                                                )
                                            }}
                                            reçus sur
                                            {{
                                                formatFcfa(
                                                    revision.amountInvoiced,
                                                )
                                            }}
                                        </span>

                                        <span class="revision-status">
                                            {{ revision.statusLabel }}
                                        </span>

                                        <span
                                            v-if="revision.delayDays !== null"
                                        >
                                            {{ revision.delayDays }} j
                                        </span>
                                    </div>

                                    <ul
                                        v-if="revision.payments.length > 0"
                                        class="revision-payments"
                                    >
                                        <li
                                            v-for="(
                                                payment, line
                                            ) in revision.payments"
                                            :key="line"
                                        >
                                            {{ formatFcfa(payment.amount) }}
                                            FCFA le
                                            {{ formatDay(payment.paid_on) }}
                                        </li>
                                    </ul>

                                    <p v-else class="revision-payments-empty">
                                        Aucun versement à cette date.
                                    </p>
                                </li>
                            </ol>
                        </Transition>
                    </div>
                </section>

                <aside class="form-panel form-panel-summary">
                    <div class="summary-header">
                        <div>
                            <span class="summary-eyebrow"> APERÇU </span>

                            <h2>Votre règlement</h2>
                        </div>

                        <div class="summary-orb">
                            <span>✓</span>
                        </div>
                    </div>

                    <Transition name="fade-slide">
                        <div v-if="exceedsInvoiced" class="error-card">
                            <div class="error-top">
                                <div class="error-icon">!</div>

                                <div>
                                    <strong> Montants incompatibles </strong>

                                    <span> Vérification nécessaire </span>
                                </div>
                            </div>

                            <p>
                                Vous avez saisi
                                <strong>{{ formatFcfa(excess) }} FCFA</strong>
                                de plus en reçu qu'en facturé. Corrigez l'un des
                                deux montants avant de continuer.
                            </p>
                        </div>
                    </Transition>

                    <div v-if="!exceedsInvoiced" class="status-card">
                        <div class="status-card-glow"></div>

                        <DerivedStatusNotice
                            :status="status"
                            :label="LABELS[status]"
                            :settled-share="settledShare"
                            :outstanding="outstanding"
                            :manual="rejected"
                        />
                    </div>

                    <div
                        v-if="carriesDelay && !exceedsInvoiced"
                        class="delay-card"
                    >
                        <div class="delay-card-header">
                            <div class="delay-icon">◷</div>

                            <div>
                                <strong>
                                    Délai de règlement
                                    <template v-if="instalments.length > 1">
                                        · {{ instalments.length }} versements
                                    </template>
                                </strong>

                                <span> Déduit des deux dates </span>
                            </div>

                            <div
                                class="delay-readout"
                                :class="{ 'delay-beyond': beyondStandardDelay }"
                            >
                                {{ delay ?? '—'
                                }}<span class="delay-readout-unit"> j</span>
                            </div>
                        </div>

                        <p class="delay-explanation">
                            <template v-if="delay === null">
                                Renseignez le dépôt et au moins un versement :
                                le délai s'en déduit.
                            </template>

                            <template v-else-if="beyondStandardDelay">
                                <template v-if="instalments.length > 1">
                                    Compté jusqu'au dernier versement.
                                </template>
                                Au-delà des
                                {{ insurer.standardDelayDays }} jours retenus
                                pour {{ insurer.name }}.
                            </template>

                            <template v-else>
                                Dans les
                                {{ insurer.standardDelayDays }} jours retenus
                                pour {{ insurer.name }}.
                            </template>
                        </p>
                    </div>

                    <p
                        v-if="errors.period || errors.insurer_id"
                        class="general-error"
                    >
                        {{ errors.period ?? errors.insurer_id }}
                    </p>

                    <div class="submit-area">
                        <button
                            type="submit"
                            :disabled="processing || exceedsInvoiced"
                            class="submit-button"
                        >
                            <span class="submit-label">
                                {{
                                    isLast
                                        ? 'Terminer le mois'
                                        : 'Assureur suivant'
                                }}
                            </span>

                            <span class="submit-arrow"> → </span>

                            <span
                                v-if="processing"
                                class="submit-loader"
                            ></span>
                        </button>

                        <div class="security-note">
                            <span class="security-icon"> ✓ </span>

                            <span>
                                Vos montants restent confidentiels. Seuls les
                                totaux réseau sont transmis.
                            </span>
                        </div>
                    </div>
                </aside>
            </Form>
        </div>
    </div>
</template>

<style scoped>
.insurer-title {
    /* Un cran au-dessus du titre d'écran habituel : c'est le seul mot qui
       change quand on passe d'un assureur au suivant. */
    font-size: 27px;
    line-height: 1.15;
    letter-spacing: -0.4px;
}

.insurer-terms {
    margin-top: 6px;
    font-size: 12.5px;
    color: var(--muted);
}

.insurer-terms strong {
    font-weight: 700;
    color: var(--ink);
}

.dates {
    display: flex;

    flex-direction: column;

    gap: 12px;

    margin-top: 14px;
}

.date-field {
    flex: 1;

    min-width: 0;
}

@media (min-width: 640px) {
    .dates {
        flex-direction: row;
    }
}

.delay-readout {
    margin-left: auto;

    font-size: 17px;

    line-height: 1;

    font-weight: 700;

    color: var(--ink);
}

.delay-readout.delay-beyond {
    color: var(--terracotta-dark);
}

.delay-readout-unit {
    font-size: 11px;

    font-weight: 500;

    color: var(--muted);
}

.delay-explanation {
    margin-top: 10px;

    font-size: 14px;

    line-height: 1.45;

    color: var(--light);
}

.declare-page {
    /*
      --muted et --light sont ici des couleurs de TEXTE. Le thème réserve
      --muted à une surface (#faf8f3) : les retirer rendrait ce texte presque
      blanc.
    */
    --muted: color-mix(in srgb, var(--ink) 55%, transparent);
    --light: color-mix(in srgb, var(--ink) 38%, transparent);

    position: relative;
    min-height: 100vh;

    overflow: hidden;
}

.declare-page::before {
    content: '';

    position: absolute;

    width: 420px;
    height: 420px;

    right: -180px;
    top: 180px;

    border-radius: 50%;

    border: 1px solid color-mix(in srgb, var(--officine) 6%, transparent);

    pointer-events: none;
}

.declare-page::after {
    content: '';

    position: absolute;

    width: 260px;
    height: 260px;

    left: -140px;
    bottom: 100px;

    border-radius: 50%;

    border: 1px solid color-mix(in srgb, var(--gold-mid) 7%, transparent);

    pointer-events: none;
}

.declare-header {
    position: relative;
    z-index: 2;

    max-width: 1040px;

    margin: 0 auto 18px;

    display: flex;
    align-items: center;
    justify-content: space-between;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    color: var(--muted);

    font-size: 11px;
    font-weight: 700;

    text-decoration: none;

    transition:
        color 0.2s ease,
        transform 0.2s ease;
}

.back-link:hover {
    color: var(--primary);
    transform: translateX(-2px);
}

.back-icon {
    width: 28px;
    height: 28px;

    display: grid;
    place-items: center;

    border: 1px solid var(--border);

    border-radius: var(--radius-nav);

    background: rgba(255, 255, 255, 0.75);

    transition:
        background 0.2s ease,
        border-color 0.2s ease;
}

.back-link:hover .back-icon {
    background: var(--primary-soft);
    border-color: color-mix(in srgb, var(--officine) 18%, transparent);
}

.header-context {
    display: flex;
    align-items: center;
    gap: 7px;

    font-family: monospace;

    font-size: 9.5px;
    font-weight: 700;

    letter-spacing: 0.14em;

    color: var(--light);
}

.header-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: var(--primary);

    box-shadow: 0 0 0 4px color-mix(in srgb, var(--officine) 8%, transparent);

    animation: pulse 2.3s infinite;
}

.declare-shell {
    position: relative;
    z-index: 2;

    width: 100%;
    max-width: 1040px;

    margin: 0 auto;

    background: #ffffff;

    border-radius: var(--radius-card);

    overflow: hidden;

    box-shadow: var(--surface-shadow);

    animation: shellAppear 0.6s ease both;
}

.progress-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;

    padding: 18px 24px;

    border-bottom: 1px solid var(--border);

    background: #fff;
}

.progress-left {
    display: flex;
    align-items: center;
    gap: 11px;
}

.progress-badge {
    width: 34px;
    height: 34px;

    display: grid;
    place-items: center;

    border-radius: 10px;

    background: var(--primary);

    color: #ffffff;

    font-size: 11px;
    font-weight: 800;

    box-shadow: 0 6px 15px color-mix(in srgb, var(--officine) 16%, transparent);
}

.progress-label {
    font-size: 9.5px;
    font-weight: 800;

    letter-spacing: 0.14em;

    color: var(--primary);
}

.progress-title {
    margin-top: 3px;

    font-size: 12.5px;
    font-weight: 700;

    color: var(--ink);
}

.later-link {
    display: flex;
    align-items: center;
    gap: 6px;

    color: var(--muted);

    font-size: 10px;
    font-weight: 700;

    text-decoration: none;

    transition:
        color 0.2s ease,
        gap 0.2s ease;
}

.later-link:hover {
    color: var(--primary);
    gap: 9px;
}

.progress-track {
    height: 3px;

    background: var(--cream-state);
}

.progress-value {
    height: 100%;

    background: var(--primary);

    transition: width 0.5s ease;
}

.declare-form {
    display: grid;

    grid-template-columns:
        minmax(0, 1.08fr)
        minmax(320px, 0.92fr);
}

.form-panel {
    padding: 34px 34px;
}

.form-panel-main {
    background: #ffffff;
}

.form-panel-summary {
    position: relative;

    border-left: 1px solid var(--border);

    background: var(--cream-state);
}

.eyebrow {
    display: inline-flex;
    align-items: center;

    padding: 6px 9px;

    border-radius: 7px;

    background: var(--gold-soft);

    color: var(--gold-ink);

    font-family: monospace;

    font-size: 9.5px;
    font-weight: 800;

    letter-spacing: 0.14em;
}

.panel-intro h1 {
    max-width: 570px;

    margin-top: 16px;

    color: var(--ink);

    font-size: 25px;
    font-weight: 750;

    line-height: 1.12;

    letter-spacing: -0.035em;
}

.panel-intro h1 em {
    color: var(--primary);

    font-style: normal;

    position: relative;
}

.panel-intro h1 em::after {
    content: '';

    position: absolute;

    left: 0;
    right: 0;
    bottom: -3px;

    height: 2px;

    background: var(--gold);

    border-radius: 4px;

    transform: scaleX(0.65);

    transform-origin: left;

    transition: transform 0.3s ease;
}

.panel-intro:hover h1 em::after {
    transform: scaleX(1);
}

.intro-text {
    max-width: 510px;

    margin-top: 12px;

    color: var(--muted);

    font-size: 14px;

    line-height: 1.55;
}

.amounts {
    display: flex;
    flex-direction: column;

    gap: 16px;

    margin-top: 28px;
}

.amount-wrapper {
    position: relative;

    display: flex;
    align-items: flex-start;

    gap: 10px;
}

.amount-number {
    width: 27px;
    height: 27px;

    flex-shrink: 0;

    display: grid;
    place-items: center;

    margin-top: 8px;

    border-radius: 8px;

    background: var(--primary-soft);

    color: var(--primary);

    font-family: monospace;

    font-size: 12.5px;
    font-weight: 800;

    transition:
        transform 0.25s ease,
        background 0.25s ease;
}

.amount-wrapper:hover .amount-number {
    transform: translateY(-2px) rotate(-3deg);

    background: color-mix(in srgb, var(--officine) 13%, transparent);
}

.secondary-action {
    width: 100%;

    display: flex;
    align-items: center;

    gap: 9px;

    margin-top: 18px;

    padding: 11px 13px;

    border: 1px solid color-mix(in srgb, var(--ink) 8%, transparent);

    border-radius: 11px;

    background: var(--cream-state);

    color: var(--muted);

    font-size: 10.5px;
    font-weight: 700;

    text-align: left;

    cursor: pointer;

    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;
}

.secondary-action:hover {
    border-color: color-mix(in srgb, var(--officine) 18%, transparent);

    background: var(--primary-soft);

    color: var(--primary-dark);

    transform: translateY(-1px);
}

.secondary-icon {
    width: 23px;
    height: 23px;

    display: grid;
    place-items: center;

    border-radius: 7px;

    background: var(--gold-soft);

    color: var(--gold-ink);

    font-weight: 800;
}

.secondary-arrow {
    margin-left: auto;

    transition: transform 0.2s ease;
}

.secondary-action:hover .secondary-arrow {
    transform: translateX(3px);
}

.private-note {
    margin-top: 18px;
}

.note-toggle {
    display: flex;
    align-items: center;
    gap: 7px;

    border: 0;

    background: transparent;

    padding: 0;

    color: var(--ink);

    font-size: 10.5px;
    font-weight: 700;

    cursor: pointer;
}

.note-plus {
    width: 21px;
    height: 21px;

    display: grid;
    place-items: center;

    border-radius: 7px;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 13px;
}

.note-description {
    color: var(--light);

    font-size: 12.5px;
    font-weight: 500;
}

.note-content {
    margin-top: 10px;
}

.note-textarea {
    width: 100%;

    resize: vertical;

    min-height: 75px;

    border: 1px solid color-mix(in srgb, var(--ink) 12%, transparent);

    border-radius: 12px;

    background: #ffffff;

    padding: 11px 12px;

    color: var(--ink);

    font-size: 11px;

    line-height: 1.5;

    outline: none;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.note-textarea:focus {
    border-color: color-mix(in srgb, var(--officine) 35%, transparent);

    box-shadow: 0 0 0 4px color-mix(in srgb, var(--officine) 5.5%, transparent);
}

.note-textarea::placeholder {
    color: var(--light);
}

.note-footer {
    display: flex;
    justify-content: space-between;

    margin-top: 5px;

    color: var(--light);

    font-size: 12.5px;
}

.field-error,
.general-error {
    margin-top: 6px;

    color: var(--terracotta-dark);

    font-size: 12.5px;
}

.summary-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    margin-bottom: 22px;
}

.summary-eyebrow {
    display: block;

    color: var(--primary);

    font-family: monospace;

    font-size: 9.5px;
    font-weight: 800;

    letter-spacing: 0.14em;
}

.summary-header h2 {
    margin-top: 4px;

    color: var(--ink);

    font-size: 17px;
    font-weight: 750;

    letter-spacing: -0.025em;
}

.summary-orb {
    width: 42px;
    height: 42px;

    display: grid;
    place-items: center;

    border-radius: 13px;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 15px;
    font-weight: 800;

    animation: orbFloat 3s ease-in-out infinite;
}

.status-card {
    position: relative;

    overflow: hidden;

    padding: 14px;

    border-radius: var(--radius-card);

    background: #ffffff;

    box-shadow: var(--surface-shadow);
}

.status-card-glow {
    position: absolute;

    width: 100px;
    height: 100px;

    right: -50px;
    top: -50px;

    border-radius: 50%;

    background: color-mix(in srgb, var(--officine) 7%, transparent);

    pointer-events: none;
}

.error-card {
    padding: 15px;

    border-radius: var(--radius-card);

    box-shadow: var(--surface-shadow);

    background: color-mix(in srgb, var(--terracotta) 5.5%, transparent);

    animation: errorAppear 0.35s ease both;
}

.error-top {
    display: flex;
    align-items: center;
    gap: 9px;
}

.error-icon {
    width: 27px;
    height: 27px;

    display: grid;
    place-items: center;

    border-radius: 8px;

    background: var(--terracotta);

    color: #ffffff;

    font-size: 12px;
    font-weight: 800;
}

.error-top strong {
    display: block;

    color: var(--ink);

    font-size: 11px;
}

.error-top span {
    display: block;

    margin-top: 2px;

    color: var(--muted);

    font-size: 12.5px;
}

.error-card p {
    margin-top: 11px;

    color: var(--muted);

    font-size: 14px;

    line-height: 1.5;
}

.error-card p strong {
    color: var(--terracotta-dark);
}

.delay-card {
    margin-top: 12px;

    padding: 14px;

    border-radius: var(--radius-card);

    box-shadow: var(--surface-shadow);

    background: #fff;

    animation: cardAppear 0.45s 0.1s ease both;
}

.delay-card-header {
    display: flex;
    align-items: center;

    gap: 9px;

    margin-bottom: 11px;
}

.delay-icon {
    width: 27px;
    height: 27px;

    display: grid;
    place-items: center;

    border-radius: 8px;

    background: var(--gold-soft);

    color: var(--gold-ink);

    font-size: 13px;
}

.delay-card-header strong {
    display: block;

    color: var(--ink);

    font-size: 10.5px;
}

.delay-card-header span {
    display: block;

    margin-top: 2px;

    color: var(--muted);

    font-size: 12.5px;
}

.submit-area {
    margin-top: 22px;
}

.submit-button {
    position: relative;

    width: 100%;
    height: 52px;

    display: flex;
    align-items: center;
    justify-content: center;

    gap: 10px;

    border: 0;

    border-radius: 13px;

    background: var(--primary);

    color: #ffffff;

    font-size: 12px;
    font-weight: 800;

    cursor: pointer;

    overflow: hidden;

    box-shadow: 0 9px 22px color-mix(in srgb, var(--officine) 18%, transparent);

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        opacity 0.2s ease;
}

/*
  Le balayage lumineux du bouton est retiré, pas aplati : sa seule substance
  visible était le dégradé. Un aplat blanc à 18 % glissant sur le bouton
  serait un rectangle gris, pire que rien. Le pseudo-élément et sa règle de
  survol partent avec lui.
*/

.submit-button:hover:not(:disabled) {
    transform: translateY(-2px);

    box-shadow: 0 13px 28px color-mix(in srgb, var(--officine) 23%, transparent);
}

.submit-button:active:not(:disabled) {
    transform: translateY(0);
}

.submit-button:disabled {
    cursor: not-allowed;

    opacity: 0.55;

    box-shadow: none;
}

.submit-arrow {
    font-size: 17px;

    transition: transform 0.2s ease;
}

.submit-button:hover .submit-arrow {
    transform: translateX(4px);
}

.submit-loader {
    position: absolute;

    width: 17px;
    height: 17px;

    border: 2px solid rgba(255, 255, 255, 0.35);

    border-top-color: #ffffff;

    border-radius: 50%;

    animation: spin 0.7s linear infinite;
}

.security-note {
    display: flex;
    align-items: flex-start;

    gap: 7px;

    margin-top: 11px;

    color: var(--muted);

    font-size: 12.5px;

    line-height: 1.45;

    text-align: center;
}

.security-icon {
    width: 17px;
    height: 17px;

    flex-shrink: 0;

    display: grid;
    place-items: center;

    border-radius: 50%;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 8px;
    font-weight: 800;
}

.note-enter-active,
.note-leave-active {
    transition:
        opacity 0.25s ease,
        transform 0.25s ease,
        max-height 0.25s ease;
}

.note-enter-from,
.note-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

.fade-slide-enter-active,
.fade-slide-leave-active {
    transition:
        opacity 0.25s ease,
        transform 0.25s ease;
}

.fade-slide-enter-from,
.fade-slide-leave-to {
    opacity: 0;
    transform: translateY(8px);
}

@keyframes shellAppear {
    from {
        opacity: 0;
        transform: translateY(15px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes cardAppear {
    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes errorAppear {
    from {
        opacity: 0;
        transform: scale(0.98) translateY(5px);
    }

    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

@keyframes orbFloat {
    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-3px);
    }
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 color-mix(in srgb, var(--officine) 25%, transparent);
    }

    70% {
        box-shadow: 0 0 0 5px transparent;
    }

    100% {
        box-shadow: 0 0 0 0 transparent;
    }
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 900px) {
    .declare-form {
        grid-template-columns: 1fr;
    }

    .form-panel-summary {
        border-left: 0;

        border-top: 1px solid var(--border);
    }

    .form-panel {
        padding: 28px 26px;
    }
}

@media (max-width: 640px) {
    .declare-page {
        padding: 12px 12px 45px;
    }

    .declare-header {
        margin-bottom: 12px;
    }

    .header-context {
        display: none;
    }

    .progress-header {
        padding: 14px 15px;
    }

    .later-link {
        font-size: 12.5px;
    }

    .form-panel {
        padding: 23px 17px;
    }

    .panel-intro h1 {
        font-size: 25px;
    }

    .amounts {
        margin-top: 23px;
        gap: 13px;
    }

    .amount-number {
        width: 23px;
        height: 23px;

        font-size: 12.5px;
    }

    .form-panel-summary {
        padding-top: 24px;
    }

    .summary-header {
        margin-bottom: 17px;
    }

    .submit-button {
        height: 50px;
    }
}

@media (max-width: 420px) {
    .declare-page {
        padding: 8px 8px 35px;
    }

    .later-link span {
        display: none;
    }

    .form-panel {
        padding: 20px 14px;
    }

    .panel-intro h1 {
        font-size: 23px;
    }

    .secondary-action {
        font-size: 12.5px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .declare-page *,
    .declare-page *::before,
    .declare-page *::after {
        animation-duration: 0.01ms !important;

        animation-iteration-count: 1 !important;

        transition-duration: 0.01ms !important;
    }
}

.revision-history {
    margin-top: 16px;

    padding-top: 14px;

    border-top: 1px solid color-mix(in srgb, var(--ink) 9%, transparent);
}

.revision-list {
    margin: 12px 0 0;

    padding: 0;

    list-style: none;

    display: flex;

    flex-direction: column;

    gap: 10px;
}

.revision-item {
    padding: 10px 12px;

    border: 1px solid color-mix(in srgb, var(--ink) 10%, transparent);

    border-radius: 10px;

    font-size: 11px;

    line-height: 1.5;
}

.revision-head {
    display: flex;
    align-items: baseline;

    flex-wrap: wrap;

    gap: 8px;
}

.revision-moment {
    font-weight: 700;

    color: var(--ink);
}

.revision-author {
    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.revision-origin {
    margin-left: auto;

    padding: 1px 7px;

    border-radius: 999px;

    background: color-mix(in srgb, var(--ink) 6%, transparent);

    font-size: 12.5px;

    font-weight: 650;

    letter-spacing: 0.03em;

    color: color-mix(in srgb, var(--ink) 50%, transparent);
}

.revision-figures {
    margin-top: 4px;

    display: flex;
    align-items: baseline;

    flex-wrap: wrap;

    gap: 10px;

    color: color-mix(in srgb, var(--ink) 70%, transparent);
}

.revision-status {
    font-weight: 650;

    color: var(--ink);
}

.revision-payments,
.revision-payments-empty {
    margin: 6px 0 0;

    padding: 0 0 0 14px;

    color: color-mix(in srgb, var(--ink) 50%, transparent);
}

.revision-payments-empty {
    padding-left: 0;

    font-style: italic;
}
</style>
