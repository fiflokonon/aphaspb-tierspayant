<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import AmountField from '@/components/aphaspb/AmountField.vue';
import DateField from '@/components/aphaspb/DateField.vue';
import { formatFcfa } from '@/lib/fcfa';

export type Instalment = {
    amount: number;
    paidOn: string | null;
};

const props = defineProps<{
    /** Ce qui a été facturé, pour proposer le solde en un clic. */
    invoiced: number;
    minDate: string;
    maxDate: string;
    /** Les erreurs du serveur, indexées `payments.0.amount`. */
    errors: Record<string, string>;
}>();

const model = defineModel<Instalment[]>({ default: () => [] });

const total = computed(() =>
    model.value.reduce((sum, line) => sum + line.amount, 0),
);

const outstanding = computed(() => Math.max(0, props.invoiced - total.value));

/**
 * Le total des versements ne peut pas dépasser la facture — c'est ce que le
 * serveur refuse. Signalé ici sur le bloc, pas sur une ligne : aucune ligne
 * n'est fautive à elle seule, c'est leur somme qui l'est.
 */
const exceedsInvoiced = computed(
    () => props.invoiced > 0 && total.value > props.invoiced,
);

/**
 * Le solde restant, proposé sur la dernière ligne saisie.
 *
 * Le raccourci « Tout reçu » d'un versement unique devient « Le solde » dès
 * qu'il y en a plusieurs : c'est le montant que l'officine a sous les yeux sur
 * son relevé, pas le total de la facture.
 */
function remainingFor(index: number): number {
    const others = model.value.reduce(
        (sum, line, position) => (position === index ? sum : sum + line.amount),
        0,
    );

    return Math.max(0, props.invoiced - others);
}

function add(): void {
    model.value = [...model.value, { amount: 0, paidOn: null }];
}

function remove(index: number): void {
    model.value = model.value.filter((_, position) => position !== index);
}
</script>

<template>
    <section class="instalments">
        <div class="instalments-head">
            <div>
                <span class="instalments-eyebrow"> VERSEMENTS REÇUS </span>

                <p class="instalments-help">
                    Un assureur règle parfois un mois en plusieurs fois. Ajoutez
                    une ligne par virement : chacune porte sa date, et le délai
                    se compte jusqu'au versement le plus récent.
                </p>
            </div>
        </div>

        <p v-if="errors.payments" class="instalments-error">
            {{ errors.payments }}
        </p>

        <div v-if="model.length === 0" class="instalments-empty">
            Aucun versement enregistré pour ce mois. La facture est déclarée
            impayée tant qu'aucune ligne n'est ajoutée.
        </div>

        <ol v-else class="instalments-list">
            <li
                v-for="(line, index) in model"
                :key="index"
                class="instalment-row"
            >
                <span class="instalment-rank">{{ index + 1 }}</span>

                <div class="instalment-fields">
                    <AmountField
                        v-model="line.amount"
                        label="MONTANT VERSÉ"
                        :name="`payments[${index}][amount]`"
                        :shortcut="{
                            label: model.length > 1 ? 'Le solde' : 'Tout reçu',
                            value: remainingFor(index),
                        }"
                        :error="errors[`payments.${index}.amount`]"
                    />

                    <DateField
                        v-model="line.paidOn"
                        label="DATE DU VERSEMENT"
                        :name="`payments[${index}][paid_on]`"
                        required
                        :min="minDate"
                        :max="maxDate"
                        :error="errors[`payments.${index}.paid_on`]"
                    />
                </div>

                <button
                    type="button"
                    class="instalment-remove"
                    :aria-label="`Retirer le versement ${index + 1}`"
                    @click="remove(index)"
                >
                    <Trash2 class="size-[15px]" />
                </button>
            </li>
        </ol>

        <div class="instalments-foot">
            <button type="button" class="instalment-add" @click="add()">
                <Plus class="size-[15px]" />
                <span>Ajouter un versement</span>
            </button>

            <div class="instalments-totals">
                <span
                    class="instalments-total"
                    :class="{ 'total-exceeds': exceedsInvoiced }"
                >
                    {{ formatFcfa(total) }} FCFA reçus
                </span>

                <span v-if="!exceedsInvoiced" class="instalments-outstanding">
                    {{ formatFcfa(outstanding) }} FCFA restants
                </span>
            </div>
        </div>
    </section>
</template>

<style scoped>
.instalments {
    margin-top: 18px;

    padding-top: 16px;

    border-top: 1px solid rgb(36 51 51 / 0.09);
}

.instalments-eyebrow {
    font-family: var(--font-mono, ui-monospace, monospace);

    font-size: 10.5px;

    font-weight: 600;

    letter-spacing: 0.05em;

    color: rgb(36 51 51 / 0.5);
}

.instalments-help {
    margin: 6px 0 0;

    max-width: 46ch;

    font-size: 11.5px;

    line-height: 1.5;

    color: rgb(36 51 51 / 0.55);
}

.instalments-error {
    margin: 12px 0 0;

    font-size: 11px;

    line-height: 1.4;

    color: var(--terracotta-dark);
}

.instalments-empty {
    margin-top: 14px;

    padding: 14px;

    border: 1px dashed rgb(36 51 51 / 0.16);

    border-radius: 12px;

    font-size: 11.5px;

    line-height: 1.5;

    color: rgb(36 51 51 / 0.5);
}

.instalments-list {
    margin: 14px 0 0;

    padding: 0;

    list-style: none;

    display: flex;

    flex-direction: column;

    gap: 14px;
}

.instalment-row {
    display: flex;
    align-items: flex-start;

    gap: 10px;
}

.instalment-rank {
    flex-shrink: 0;

    margin-top: 22px;

    display: grid;

    place-items: center;

    width: 20px;
    height: 20px;

    border-radius: 999px;

    background: rgb(36 51 51 / 0.06);

    font-family: var(--font-mono, ui-monospace, monospace);

    font-size: 10px;

    font-weight: 700;

    color: rgb(36 51 51 / 0.55);
}

.instalment-fields {
    flex: 1;

    min-width: 0;

    display: flex;

    flex-direction: column;

    gap: 10px;
}

.instalment-remove {
    flex-shrink: 0;

    margin-top: 18px;

    display: grid;

    place-items: center;

    width: 28px;
    height: 28px;

    border-radius: 8px;

    color: rgb(36 51 51 / 0.4);

    transition:
        color 120ms ease,
        background 120ms ease;
}

.instalment-remove:hover {
    background: rgb(36 51 51 / 0.06);

    color: var(--terracotta-dark);
}

.instalments-foot {
    margin-top: 14px;

    display: flex;
    align-items: center;

    flex-wrap: wrap;

    gap: 10px;
}

.instalment-add {
    display: inline-flex;
    align-items: center;

    gap: 6px;

    padding: 8px 12px;

    border: 1px solid rgb(36 51 51 / 0.14);

    border-radius: 10px;

    font-size: 11.5px;

    font-weight: 650;

    color: var(--ink);
}

.instalment-add:hover {
    border-color: rgb(36 51 51 / 0.28);
}

.instalments-totals {
    margin-left: auto;

    display: flex;
    align-items: baseline;

    gap: 10px;

    font-size: 11.5px;
}

.instalments-total {
    font-weight: 700;

    color: var(--ink);
}

.instalments-total.total-exceeds {
    color: var(--terracotta-dark);
}

.instalments-outstanding {
    color: rgb(36 51 51 / 0.5);
}

@media (min-width: 640px) {
    .instalment-fields {
        flex-direction: row;
    }

    .instalment-fields > * {
        flex: 1;

        min-width: 0;
    }
}
</style>
