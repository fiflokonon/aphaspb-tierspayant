<script setup lang="ts">
/**
 * La pénalité exigible à la date du jour, et le chemin qui y mène.
 *
 * Assureur → facture → chronologie : dépôt, déclenchement, chaque tranche
 * avec son calcul (montant facturé × taux), chaque versement avec le reste dû,
 * puis le solde ou la clôture. La base est fixe : un versement ne change pas
 * le montant d'une tranche, seul le solde arrête la course.
 *
 * Une facture qui doit encore une pénalité s'ouvre d'elle-même ; une facture
 * close reste là, repliée — la traçabilité ne s'efface pas.
 */
import {
    BadgeCheck,
    CalendarClock,
    ChevronRight,
    CircleSlash,
    FileText,
    HandCoins,
    TimerReset,
    TrendingUp,
} from '@lucide/vue';
import type { Component } from 'vue';
import { ref } from 'vue';
import { formatAmount } from '@/lib/fcfa';
import type {
    PenaltyTrace,
    PenaltyTraceEvent,
    PenaltyTraceInsurer,
    PenaltyTraceInvoice,
} from '@/types/aphaspb';

const props = defineProps<{ trace: PenaltyTrace }>();

const openInsurers = ref<Set<number>>(
    new Set(
        props.trace.insurers.filter((one) => one.due > 0).map((one) => one.id),
    ),
);

const openInvoices = ref<Set<number>>(
    new Set(
        props.trace.insurers.flatMap((one) =>
            one.invoices
                .filter((invoice) => invoice.due > 0)
                .map((invoice) => invoice.id),
        ),
    ),
);

/** Un ensemble neuf à chaque bascule : Vue ne suit pas les mutations d'un Set. */
function toggled(set: Set<number>, id: number): Set<number> {
    const next = new Set(set);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    return next;
}

const toggleInsurer = (id: number): void => {
    openInsurers.value = toggled(openInsurers.value, id);
};

const toggleInvoice = (id: number): void => {
    openInvoices.value = toggled(openInvoices.value, id);
};

const percent = (value: number): string =>
    `${value.toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`;

const ICONS: Record<PenaltyTraceEvent['kind'], Component> = {
    deposit: FileText,
    trigger: CalendarClock,
    tranche: TrendingUp,
    payment: HandCoins,
    paidOff: TimerReset,
    settled: BadgeCheck,
};

function title(event: PenaltyTraceEvent): string {
    switch (event.kind) {
        case 'deposit':
            return 'Facture déposée';
        case 'trigger':
            return 'Déclenchement de la pénalité';
        case 'tranche':
            return `Tranche n° ${event.number}`;
        case 'payment':
            return 'Versement reçu';
        case 'paidOff':
            return 'Facture soldée';
        case 'settled':
            return `Pénalité close : ${event.label?.toLowerCase()}`;
    }
}

function detail(
    event: PenaltyTraceEvent,
    invoice: PenaltyTraceInvoice,
    insurer: PenaltyTraceInsurer,
): string {
    switch (event.kind) {
        case 'deposit':
            return `Montant facturé : ${formatAmount(event.amount)} F`;
        case 'trigger':
            return `Dépôt + ${insurer.triggerDays} jours (délai de la convention)`;
        case 'tranche':
            return `${formatAmount(invoice.amountInvoiced)} × ${percent(insurer.ratePercent)}`;
        case 'payment':
            return `${formatAmount(event.amount)} F encaissés · reste dû ${formatAmount(event.remaining)} F`;
        case 'paidOff':
            return 'La pénalité cesse de courir';
        case 'settled':
            return `Montant clos : ${formatAmount(event.amount)} F`;
    }
}

function status(invoice: PenaltyTraceInvoice): { label: string; tone: string } {
    if (invoice.settlement) {
        return {
            label: `Pénalité ${invoice.settlement.label.toLowerCase()}`,
            tone: 'bg-cream-state text-ink/70',
        };
    }

    if (invoice.running) {
        return {
            label: 'Court encore',
            tone: 'bg-terracotta/10 text-terracotta-dark',
        };
    }

    return { label: 'Facture soldée', tone: 'bg-gold/15 text-gold-ink' };
}
</script>

<template>
    <section class="flex flex-col gap-4">
        <!-- EXIGIBLE AU JOUR -->
        <div
            class="flex flex-wrap items-end justify-between gap-4 rounded-[var(--radius-card)] bg-card px-5 py-4 shadow-[var(--surface-shadow)]"
        >
            <div>
                <p
                    class="font-mono text-label tracking-[0.14em] text-ink/60 uppercase"
                >
                    Pénalité exigible au {{ trace.asOf }}
                </p>
                <p class="mt-1 text-figure font-bold text-ink tabular-nums">
                    {{ formatAmount(trace.due) }}
                    <span class="text-body font-semibold text-ink/60"
                        >FCFA</span
                    >
                </p>
            </div>

            <ul v-if="trace.insurers.length > 1" class="flex flex-wrap gap-2">
                <li
                    v-for="insurer in trace.insurers"
                    :key="insurer.id"
                    class="rounded-full bg-cream-state px-3 py-1 text-meta text-ink/80"
                >
                    {{ insurer.name }}
                    <strong class="ml-1 text-ink tabular-nums">{{
                        formatAmount(insurer.due)
                    }}</strong>
                </li>
            </ul>
        </div>

        <p
            v-if="trace.insurers.length === 0"
            class="rounded-[var(--radius-card)] bg-card px-5 py-6 text-center text-body text-ink/70 shadow-[var(--surface-shadow)]"
        >
            Aucune pénalité n'a couru sur vos factures à ce jour.
        </p>

        <!-- PAR ASSUREUR -->
        <article
            v-for="insurer in trace.insurers"
            :key="insurer.id"
            class="overflow-hidden rounded-[var(--radius-card)] bg-card shadow-[var(--surface-shadow)]"
        >
            <button
                type="button"
                class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-5 py-4 text-left hover:bg-cream-header"
                :aria-expanded="openInsurers.has(insurer.id)"
                @click="toggleInsurer(insurer.id)"
            >
                <ChevronRight
                    class="size-4 shrink-0 text-ink/50 transition-transform"
                    :class="{ 'rotate-90': openInsurers.has(insurer.id) }"
                    aria-hidden="true"
                />
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="text-section font-bold text-ink">{{
                        insurer.name
                    }}</span>
                    <span class="text-meta text-ink/60">
                        Pénalité à partir de {{ insurer.triggerDays }} jours
                        après le dépôt · {{ percent(insurer.ratePercent) }} du
                        montant facturé par tranche de
                        {{ insurer.trancheDays }} jours
                    </span>
                </span>
                <span class="flex flex-col items-end">
                    <span
                        class="font-mono text-label tracking-[0.14em] text-ink/60 uppercase"
                    >
                        Exigible
                    </span>
                    <span class="text-section font-bold text-ink tabular-nums">
                        {{ formatAmount(insurer.due) }} F
                    </span>
                </span>
            </button>

            <div
                v-if="openInsurers.has(insurer.id)"
                class="flex flex-col gap-3 border-t border-border px-3 py-3 sm:px-5"
            >
                <!-- PAR FACTURE -->
                <div
                    v-for="invoice in insurer.invoices"
                    :key="invoice.id"
                    class="rounded-[var(--radius-band)] border border-border"
                >
                    <button
                        type="button"
                        class="flex w-full flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 text-left hover:bg-cream-header"
                        :aria-expanded="openInvoices.has(invoice.id)"
                        @click="toggleInvoice(invoice.id)"
                    >
                        <ChevronRight
                            class="size-4 shrink-0 text-ink/50 transition-transform"
                            :class="{
                                'rotate-90': openInvoices.has(invoice.id),
                            }"
                            aria-hidden="true"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="font-semibold text-ink"
                                >Facture {{ invoice.periodLabel }}</span
                            >
                            <span class="text-meta text-ink/60">
                                {{ formatAmount(invoice.amountInvoiced) }} F
                                facturés · déposée le
                                {{ invoice.depositedOn }}
                            </span>
                        </span>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-meta font-semibold"
                            :class="status(invoice).tone"
                        >
                            {{ status(invoice).label }}
                        </span>
                        <span
                            class="w-28 text-right font-bold text-ink tabular-nums"
                        >
                            {{ formatAmount(invoice.due) }} F
                        </span>
                    </button>

                    <div
                        v-if="openInvoices.has(invoice.id)"
                        class="border-t border-border"
                    >
                        <!-- LE CALCUL EN UNE LIGNE -->
                        <p
                            class="bg-cream-state px-4 py-2.5 text-meta text-ink/80"
                        >
                            {{ invoice.tranches }} tranche{{
                                invoice.tranches > 1 ? 's' : ''
                            }}
                            × {{ formatAmount(invoice.trancheAmount) }} F
                            <span class="text-ink/60">
                                ({{ formatAmount(invoice.amountInvoiced) }} ×
                                {{ percent(insurer.ratePercent) }})
                            </span>
                            =
                            <strong class="text-ink"
                                >{{ formatAmount(invoice.accrued) }} F</strong
                            >
                            de pénalité courue
                            <template v-if="invoice.settlement">
                                · close «
                                {{ invoice.settlement.label.toLowerCase() }} »
                                <template v-if="invoice.settlement.on"
                                    >le {{ invoice.settlement.on }}</template
                                >
                            </template>
                        </p>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[640px] text-[13px]">
                                <thead>
                                    <tr
                                        class="text-left font-mono text-label tracking-[0.1em] text-ink/60 uppercase"
                                    >
                                        <th class="px-4 py-2 font-semibold">
                                            Date
                                        </th>
                                        <th class="px-4 py-2 font-semibold">
                                            Événement
                                        </th>
                                        <th class="px-4 py-2 font-semibold">
                                            Détail
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-semibold"
                                        >
                                            Pénalité
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-semibold"
                                        >
                                            Cumul
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(event, index) in invoice.events"
                                        :key="index"
                                        class="border-t border-border/70"
                                        :class="{
                                            'text-ink/70':
                                                event.kind !== 'tranche',
                                        }"
                                    >
                                        <td
                                            class="px-4 py-2 whitespace-nowrap tabular-nums"
                                        >
                                            {{ event.on }}
                                        </td>
                                        <td class="px-4 py-2">
                                            <span
                                                class="inline-flex items-center gap-2 font-semibold text-ink"
                                            >
                                                <component
                                                    :is="ICONS[event.kind]"
                                                    class="size-[14px] shrink-0"
                                                    :class="
                                                        event.kind === 'tranche'
                                                            ? 'text-terracotta'
                                                            : 'text-officine'
                                                    "
                                                    :stroke-width="2"
                                                    aria-hidden="true"
                                                />
                                                {{ title(event) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2">
                                            {{
                                                detail(event, invoice, insurer)
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right font-semibold tabular-nums"
                                        >
                                            <template
                                                v-if="event.kind === 'tranche'"
                                            >
                                                +
                                                {{ formatAmount(event.amount) }}
                                            </template>
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            <template
                                                v-if="event.kind === 'tranche'"
                                            >
                                                {{
                                                    formatAmount(
                                                        event.cumulative,
                                                    )
                                                }}
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr
                                        class="border-t-2 border-border bg-cream-state/60"
                                    >
                                        <td
                                            colspan="3"
                                            class="px-4 py-2.5 font-bold text-ink"
                                        >
                                            Exigible au {{ trace.asOf }}
                                            <span
                                                v-if="invoice.settlement"
                                                class="ml-1 inline-flex items-center gap-1 font-normal text-ink/60"
                                            >
                                                <CircleSlash
                                                    class="size-3"
                                                    aria-hidden="true"
                                                />
                                                pénalité close, plus rien à
                                                réclamer
                                            </span>
                                        </td>
                                        <td
                                            colspan="2"
                                            class="px-4 py-2.5 text-right font-bold text-ink tabular-nums"
                                        >
                                            {{ formatAmount(invoice.due) }} F
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <p
                            v-if="invoice.nextTrancheOn"
                            class="px-4 py-2.5 text-meta text-ink/60"
                        >
                            Sans solde d'ici là, une nouvelle tranche de
                            {{ formatAmount(invoice.trancheAmount) }} F
                            s'ajoutera le {{ invoice.nextTrancheOn }}.
                        </p>
                    </div>
                </div>
            </div>
        </article>
    </section>
</template>
