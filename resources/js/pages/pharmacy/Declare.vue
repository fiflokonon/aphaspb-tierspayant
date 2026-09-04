<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AmountField from '@/components/aphaspb/AmountField.vue';
import DateField from '@/components/aphaspb/DateField.vue';
import DerivedStatusNotice from '@/components/aphaspb/DerivedStatusNotice.vue';
import PeriodPicker from '@/components/aphaspb/PeriodPicker.vue';
import { formatFcfa } from '@/lib/fcfa';
import type { DeclarationStatus, SelectablePeriod } from '@/types/aphaspb';

type Declaration = {
    amount_invoiced: number;
    amount_received: number;
    status: DeclarationStatus;
    is_status_manual: boolean;
    invoice_deposited_on: string | null;
    paid_on: string | null;
    delay_days: number | null;
    private_note: string | null;
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
const received = ref(props.declaration?.amount_received ?? 0);
const depositedOn = ref<string | null>(
    props.declaration?.invoice_deposited_on ?? null,
);
const paidOn = ref<string | null>(props.declaration?.paid_on ?? null);
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
 * The server refuses this pair outright (amount_received lte amount_invoiced),
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
 * Mirrors Declaration::deriveDelayDays(). The delay is no longer typed in: it
 * is the distance between the two dates, and the server recomputes it on save.
 */
const delay = computed<number | null>(() => {
    if (depositedOn.value === null || paidOn.value === null) {
        return null;
    }

    const from = Date.parse(depositedOn.value);
    const to = Date.parse(paidOn.value);

    if (Number.isNaN(from) || Number.isNaN(to) || to < from) {
        return null;
    }

    return Math.round((to - from) / 86_400_000);
});

const beyondStandardDelay = computed(
    () => delay.value !== null && delay.value > props.insurer.standardDelayDays,
);

const isLast = computed(() => props.progress.current >= props.progress.total);
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
                <span>ESPACE OFFICINE</span>
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
                    <div class="panel-intro">
                        <div class="eyebrow">
                            {{ period.label }} ·
                            {{ insurer.name.toUpperCase() }}
                        </div>

                        <h1>
                            Combien avez-vous facturé, et combien avez-vous
                            <em>réellement reçu</em> ?
                        </h1>

                        <p class="intro-text">
                            Déclarez les montants correspondant à ce mois. Le
                            statut de votre règlement sera automatiquement
                            calculé.
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

                        <div class="amount-wrapper">
                            <div class="amount-number">02</div>

                            <AmountField
                                v-model="received"
                                label="MONTANT REÇU"
                                name="amount_received"
                                :shortcut="{
                                    label: 'Tout reçu',
                                    value: invoiced,
                                }"
                                :error="
                                    exceedsInvoiced
                                        ? 'Le montant reçu ne peut pas dépasser le montant facturé.'
                                        : errors.amount_received
                                "
                            />
                        </div>
                    </div>

                    <!--
                        Les deux dates tiennent avec les montants plutôt que
                        dans le panneau de synthèse : elles se saisissent, elles
                        ne se déduisent pas. Ce qui s'en déduit — le délai —
                        s'affiche à droite, près du statut auquel il appartient.
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

                        <DateField
                            v-if="carriesDelay"
                            v-model="paidOn"
                            class="date-field"
                            label="DATE DE PAIEMENT"
                            name="paid_on"
                            :min="depositedOn ?? dateBounds.earliest"
                            :max="dateBounds.latest"
                            :error="errors.paid_on"
                        />
                    </div>

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
                                <strong> Délai de règlement </strong>

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
                                Renseignez les deux dates : le délai s'en
                                déduit.
                            </template>

                            <template v-else-if="beyondStandardDelay">
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

    font-size: 11px;

    line-height: 1.45;

    color: var(--light);
}

.declare-page {
    --primary: #008f83;
    --primary-dark: #006f68;
    --primary-soft: #e8f6f3;

    --gold: #d7a33d;
    --gold-soft: #fff8e9;

    --ink: #243333;
    --muted: #788585;
    --light: #a2adad;

    --border: #e7eceb;
    --background: #f7f9f9;

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

    border: 1px solid rgba(0, 143, 131, 0.06);

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

    border: 1px solid rgba(215, 163, 61, 0.07);

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

    border-radius: 9px;

    background: rgba(255, 255, 255, 0.75);

    transition:
        background 0.2s ease,
        border-color 0.2s ease;
}

.back-link:hover .back-icon {
    background: var(--primary-soft);
    border-color: rgba(0, 143, 131, 0.18);
}

.header-context {
    display: flex;
    align-items: center;
    gap: 7px;

    font-family: monospace;

    font-size: 9px;
    font-weight: 700;

    letter-spacing: 0.12em;

    color: var(--light);
}

.header-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: var(--primary);

    box-shadow: 0 0 0 4px rgba(0, 143, 131, 0.08);

    animation: pulse 2.3s infinite;
}

.declare-shell {
    position: relative;
    z-index: 2;

    width: 100%;
    max-width: 1040px;

    margin: 0 auto;

    background: #ffffff;

    border: 1px solid var(--border);

    border-radius: 22px;

    overflow: hidden;

    /* box-shadow:
        0 20px 60px
        rgba(35,70,68,.055); */

    animation: shellAppear 0.6s ease both;
}

.progress-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;

    padding: 18px 24px;

    border-bottom: 1px solid var(--border);

    background: linear-gradient(100deg, #ffffff, #fbfdfc);
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

    background: linear-gradient(135deg, var(--primary), var(--primary-dark));

    color: #ffffff;

    font-size: 11px;
    font-weight: 800;

    box-shadow: 0 6px 15px rgba(0, 143, 131, 0.16);
}

.progress-label {
    font-size: 8px;
    font-weight: 800;

    letter-spacing: 0.13em;

    color: var(--primary);
}

.progress-title {
    margin-top: 3px;

    font-size: 11px;
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

    background: #eef3f2;
}

.progress-value {
    height: 100%;

    background: linear-gradient(90deg, var(--primary), #35a799, var(--gold));

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

    background: linear-gradient(145deg, #f9fcfb, #f5f9f8);
}

.eyebrow {
    display: inline-flex;
    align-items: center;

    padding: 6px 9px;

    border-radius: 7px;

    background: var(--gold-soft);

    color: #a97819;

    font-family: monospace;

    font-size: 8.5px;
    font-weight: 800;

    letter-spacing: 0.08em;
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

    font-size: 11px;

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

    font-size: 8px;
    font-weight: 800;

    transition:
        transform 0.25s ease,
        background 0.25s ease;
}

.amount-wrapper:hover .amount-number {
    transform: translateY(-2px) rotate(-3deg);

    background: rgba(0, 143, 131, 0.13);
}

.secondary-action {
    width: 100%;

    display: flex;
    align-items: center;

    gap: 9px;

    margin-top: 18px;

    padding: 11px 13px;

    border: 1px solid rgba(35, 70, 68, 0.08);

    border-radius: 11px;

    background: #fafcfc;

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
    border-color: rgba(0, 143, 131, 0.18);

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

    color: #a97819;

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

    font-size: 9px;
    font-weight: 500;
}

.note-content {
    margin-top: 10px;
}

.note-textarea {
    width: 100%;

    resize: vertical;

    min-height: 75px;

    border: 1px solid rgba(35, 70, 68, 0.12);

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
    border-color: rgba(0, 143, 131, 0.35);

    box-shadow: 0 0 0 4px rgba(0, 143, 131, 0.055);
}

.note-textarea::placeholder {
    color: #aab5b3;
}

.note-footer {
    display: flex;
    justify-content: space-between;

    margin-top: 5px;

    color: var(--light);

    font-size: 8.5px;
}

.field-error,
.general-error {
    margin-top: 6px;

    color: var(--terracotta-dark);

    font-size: 10px;
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

    font-size: 8px;
    font-weight: 800;

    letter-spacing: 0.13em;
}

.summary-header h2 {
    margin-top: 4px;

    color: var(--ink);

    font-size: 19px;
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

    border: 1px solid rgba(0, 143, 131, 0.1);

    border-radius: 14px;

    background: #ffffff;

    box-shadow: 0 7px 20px rgba(35, 70, 68, 0.035);
}

.status-card-glow {
    position: absolute;

    width: 100px;
    height: 100px;

    right: -50px;
    top: -50px;

    border-radius: 50%;

    background: rgba(0, 143, 131, 0.07);

    pointer-events: none;
}

.error-card {
    padding: 15px;

    border: 1px solid rgba(192, 71, 47, 0.2);

    border-radius: 14px;

    background: rgba(192, 71, 47, 0.055);

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

    background: #c0472f;

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

    font-size: 8.5px;
}

.error-card p {
    margin-top: 11px;

    color: var(--muted);

    font-size: 10px;

    line-height: 1.5;
}

.error-card p strong {
    color: #a8391f;
}

.delay-card {
    margin-top: 12px;

    padding: 14px;

    border: 1px solid rgba(215, 163, 61, 0.17);

    border-radius: 14px;

    background: linear-gradient(135deg, #ffffff, #fffcf5);

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

    color: #a97819;

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

    font-size: 8.5px;
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

    background: linear-gradient(135deg, var(--primary), var(--primary-dark));

    color: #ffffff;

    font-size: 12px;
    font-weight: 800;

    cursor: pointer;

    overflow: hidden;

    box-shadow: 0 9px 22px rgba(0, 143, 131, 0.18);

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        opacity 0.2s ease;
}

.submit-button::before {
    content: '';

    position: absolute;

    inset: 0;

    background: linear-gradient(
        110deg,
        transparent 20%,
        rgba(255, 255, 255, 0.18) 45%,
        transparent 70%
    );

    transform: translateX(-120%);

    transition: transform 0.65s ease;
}

.submit-button:hover::before {
    transform: translateX(120%);
}

.submit-button:hover:not(:disabled) {
    transform: translateY(-2px);

    box-shadow: 0 13px 28px rgba(0, 143, 131, 0.23);
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

    font-size: 8.5px;

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
        box-shadow: 0 0 0 0 rgba(0, 143, 131, 0.25);
    }

    70% {
        box-shadow: 0 0 0 5px rgba(0, 143, 131, 0);
    }

    100% {
        box-shadow: 0 0 0 0 rgba(0, 143, 131, 0);
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

    .declare-shell {
        border-radius: 17px;
    }

    .progress-header {
        padding: 14px 15px;
    }

    .later-link {
        font-size: 9px;
    }

    .form-panel {
        padding: 23px 17px;
    }

    .panel-intro h1 {
        font-size: 25px;
    }

    .intro-text {
        font-size: 10.5px;
    }

    .amounts {
        margin-top: 23px;
        gap: 13px;
    }

    .amount-number {
        width: 23px;
        height: 23px;

        font-size: 7px;
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

    .progress-title {
        font-size: 10px;
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

    .eyebrow {
        font-size: 7.5px;
    }

    .secondary-action {
        font-size: 9.5px;
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
</style>
