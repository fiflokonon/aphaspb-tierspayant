<script setup lang="ts">
/**
 * Le journal mois par mois : la ligne du total, dépliable par assureur.
 *
 * Une ligne retenue reste une ligne : absente, elle se lirait « rien couru ».
 * Un mois futur de la période n'en a pas — il n'y a encore rien à y écrire.
 *
 * Payée, annulée et due suivent l'horloge « couru ». Sept colonnes : la table
 * défile à l'horizontale sur écran étroit plutôt que d'en cacher une.
 */
import { computed, ref } from 'vue';
import { formatAmount } from '@/lib/fcfa';
import type { PenaltyLedger, PenaltyLedgerMonth } from '@/types/aphaspb';

const props = defineProps<{ ledger: PenaltyLedger }>();

const open = ref<Set<string>>(new Set());

function toggle(month: string): void {
    const next = new Set(open.value);

    if (next.has(month)) {
        next.delete(month);
    } else {
        next.add(month);
    }

    open.value = next;
}

const canExpand = computed(() => props.ledger.insurers.length > 1);

const rows = computed(() =>
    props.ledger.total.months
        .map((month, index) => ({ month, index }))
        .filter(({ month }) => !month.future),
);

type AmountKey =
    | 'accrued'
    | 'accruedCumulative'
    | 'declared'
    | 'accruedPaid'
    | 'accruedWaived'
    | 'accruedDue';

const COLUMNS: { key: AmountKey; label: string }[] = [
    { key: 'accrued', label: 'Pénalité courue' },
    { key: 'accruedCumulative', label: 'Cumul couru' },
    { key: 'declared', label: 'Factures du mois' },
    { key: 'accruedPaid', label: 'Dont payée' },
    { key: 'accruedWaived', label: 'Dont annulée' },
    { key: 'accruedDue', label: 'Reste due' },
];

const cell = (
    month: PenaltyLedgerMonth | undefined,
    key: AmountKey,
): string => {
    if (month === undefined) {
        return '—';
    }

    if (month.withheld) {
        return 'retenu';
    }

    // Un cumul vide sur un mois publié vient d'un mois retenu plus tôt :
    // « — » dirait « pas de convention », ce qui serait faux.
    if (key === 'accruedCumulative' && month[key] === null && !month.future) {
        return 'interrompu';
    }

    return formatAmount(month[key]);
};
</script>

<template>
    <div
        class="overflow-x-auto rounded-[var(--radius-card)] bg-card shadow-[var(--surface-shadow)]"
    >
        <table class="w-full min-w-[880px] text-[13px]">
            <thead>
                <tr
                    class="text-left font-mono text-label tracking-[0.14em] text-ink/60 uppercase"
                >
                    <th class="px-4 py-3 font-semibold">Mois</th>
                    <th
                        v-for="column in COLUMNS"
                        :key="column.key"
                        class="px-4 py-3 text-right font-semibold"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <template v-for="{ month, index } in rows" :key="month.month">
                    <tr
                        class="border-t border-border"
                        :class="{
                            'cursor-pointer hover:bg-cream-header': canExpand,
                        }"
                        :aria-expanded="
                            canExpand ? open.has(month.month) : undefined
                        "
                        @click="canExpand && toggle(month.month)"
                    >
                        <td class="px-4 py-2.5 font-semibold text-ink">
                            {{ month.label }}
                            <span
                                v-if="month.current"
                                class="ml-2 text-meta font-normal text-ink/60"
                                >en cours</span
                            >
                            <span
                                v-if="month.withheld"
                                class="ml-2 text-meta font-normal text-ink/60"
                                >sous le seuil</span
                            >
                        </td>
                        <td
                            v-for="column in COLUMNS"
                            :key="column.key"
                            class="px-4 py-2.5 text-right tabular-nums"
                        >
                            {{ cell(month, column.key) }}
                        </td>
                    </tr>

                    <template v-if="open.has(month.month)">
                        <tr
                            v-for="series in ledger.insurers"
                            :key="`${month.month}-${series.insurerId}`"
                            class="bg-cream-header/40 text-ink/75"
                        >
                            <td class="py-2 pr-4 pl-8">{{ series.name }}</td>
                            <td
                                v-for="column in COLUMNS"
                                :key="column.key"
                                class="px-4 py-2 text-right tabular-nums"
                            >
                                {{ cell(series.months[index], column.key) }}
                            </td>
                        </tr>
                    </template>
                </template>
            </tbody>
        </table>
    </div>
</template>
