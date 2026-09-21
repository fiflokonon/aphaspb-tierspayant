<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Clock, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Row = {
    id: number;
    name: string;
    isActive: boolean;
    standardDelayDays: number;
    penaltyTriggerDays: number | null;
    penaltyRatePercent: number | null;
    pharmacies: number;
};

defineProps<{
    insurers: Row[];
    anonymityMinimum: number;
    anonymityFloor: number;
}>();

const TEMPLATE = '1.8fr .7fr 1fr 1.4fr .8fr 1fr';
const COLUMNS = [
    'ASSUREUR',
    'OFFICINES (n)',
    'DÉLAI STANDARD',
    'CLAUSE DE PÉNALITÉ',
    'ÉTAT',
    'ACTION',
];

const editing = ref<number | null>(null);
const draft = ref('');

function startEditing(row: Row) {
    editing.value = row.id;
    draft.value = row.name;
}

/**
 * N'envoyer la clause de pénalité que lorsqu'elle est entière, ou vidée.
 *
 * Les deux champs sont validés l'un par l'autre côté serveur : une moitié
 * seule est refusée. Sans ce garde, remplir le délai puis passer au taux
 * soumettrait la moitié du formulaire et ferait apparaître une erreur pendant
 * la frappe.
 */
function submitWhenComplete(event: Event, submit: () => void) {
    const control = event.currentTarget as HTMLElement;
    const values = [...control.querySelectorAll('input')].map((input) =>
        input.value.trim(),
    );

    const filled = values.filter((value) => value !== '').length;

    if (filled === 0 || filled === values.length) {
        submit();
    }
}
</script>

<template>
    <Head title="Gestion des assureurs" />

    <div class="insurers-page">
        <ConsoleHeader
            eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
            title="Gestion des assureurs"
            class="insurers-header"
        />

        <section class="configuration-grid">
            <div class="configuration-card">
                <div class="card-header">
                    <div class="card-icon green">+</div>

                    <div>
                        <span class="card-eyebrow"> NOUVEAU PARTENAIRE </span>

                        <h2>Ajouter un assureur ou un courtier</h2>
                    </div>
                </div>

                <p class="card-description">
                    Ajoutez un nouveau partenaire qui pourra ensuite être
                    utilisé dans les déclarations du réseau.
                </p>

                <Form
                    action="/admin/insurers"
                    method="post"
                    reset-on-success
                    class="add-form"
                    #default="{ errors, processing }"
                >
                    <div class="form-row">
                        <div class="input-wrapper">
                            <ShieldCheck class="input-icon" :size="14" />

                            <input
                                name="name"
                                type="text"
                                placeholder="Nom de l'assureur"
                                aria-label="Nom du nouvel assureur"
                                class="modern-input"
                            />
                        </div>

                        <div class="input-wrapper delay-input-wrapper">
                            <input
                                name="standard_delay_days"
                                type="number"
                                min="1"
                                max="365"
                                value="30"
                                aria-label="Délai standard en jours"
                                class="modern-input"
                            />

                            <span class="input-suffix"> j </span>
                        </div>

                        <button
                            type="submit"
                            :disabled="processing"
                            class="primary-button"
                        >
                            <span> + </span>

                            Ajouter
                        </button>
                    </div>

                    <p v-if="errors.name" class="form-error">
                        {{ errors.name }}
                    </p>
                </Form>
            </div>

            <div class="configuration-card threshold-card">
                <div class="card-header">
                    <div class="card-icon gold"><Clock :size="16" /></div>

                    <div>
                        <span class="card-eyebrow gold"> MODE DE CALCUL </span>

                        <h2>Le délai est propre à chaque assureur</h2>
                    </div>
                </div>

                <p class="card-description">
                    La part réglée « dans les délais » compare chaque
                    déclaration au délai inscrit sur la ligne de son assureur,
                    et non plus à un seuil unique pour tout le réseau. Un
                    assureur créé depuis une officine démarre à 30 jours, à
                    corriger ici dès que la convention est connue.
                </p>

                <Form
                    action="/admin/settings/anonymity"
                    method="patch"
                    class="threshold-form"
                    #default="{ errors, processing }"
                >
                    <div class="threshold-control">
                        <div class="number-input-wrapper">
                            <input
                                name="minimum"
                                type="number"
                                :min="anonymityFloor"
                                max="100"
                                :value="anonymityMinimum"
                                aria-label="Seuil d'anonymat, en officines"
                                class="number-input"
                            />
                        </div>

                        <span class="days-label"> officines minimum </span>

                        <button
                            type="submit"
                            :disabled="processing"
                            class="primary-button threshold-button"
                        >
                            Enregistrer
                        </button>
                    </div>

                    <p v-if="errors.minimum" class="form-error">
                        {{ errors.minimum }}
                    </p>
                </Form>

                <div class="anonymity-info">
                    <div class="anonymity-icon">i</div>

                    <p>
                        En dessous de

                        <strong> {{ anonymityFloor }} officines </strong>

                        les indicateurs d'un assureur sont ceux d'une seule
                        officine, donc identifiables : le seuil ne descend pas
                        plus bas, quelle que soit la valeur saisie ici.
                    </p>
                </div>
            </div>
        </section>
        <br />

        <section class="insurers-table-section">
            <div class="section-top-line"></div>

            <DataTable
                title="Assureurs et courtiers"
                :columns="COLUMNS"
                :template="TEMPLATE"
                :footer="`${insurers.length} entrées · désactiver un assureur le retire des formulaires sans toucher à ses déclarations`"
                class="insurers-table"
            >
                <DataTableRow
                    v-for="row in insurers"
                    :key="row.id"
                    :template="TEMPLATE"
                    :tone="row.isActive ? 'default' : 'muted'"
                    class="insurer-row"
                >
                    <div class="insurer-name-cell">
                        <div
                            class="insurer-avatar"
                            :class="{ inactive: !row.isActive }"
                        >
                            {{ row.name?.charAt(0)?.toUpperCase() }}
                        </div>

                        <div class="insurer-name-content">
                            <Form
                                v-if="editing === row.id"
                                :action="`/admin/insurers/${row.id}`"
                                method="patch"
                                class="edit-form"
                                @success="editing = null"
                            >
                                <input
                                    v-model="draft"
                                    name="name"
                                    type="text"
                                    aria-label="Nouveau nom de l'assureur"
                                    class="edit-input"
                                />

                                <input
                                    type="hidden"
                                    name="is_active"
                                    :value="row.isActive ? 1 : 0"
                                />

                                <button type="submit" class="edit-confirm">
                                    ✓
                                </button>

                                <button
                                    type="button"
                                    class="edit-cancel"
                                    @click="editing = null"
                                >
                                    ×
                                </button>
                            </Form>

                            <template v-else>
                                <span
                                    class="insurer-name"
                                    :class="{
                                        inactive: !row.isActive,
                                    }"
                                >
                                    {{ row.name }}
                                </span>

                                <span
                                    class="insurer-subtitle"
                                    :class="{
                                        inactive: !row.isActive,
                                    }"
                                >
                                    <span
                                        class="mini-status-dot"
                                        :class="{
                                            inactive: !row.isActive,
                                        }"
                                    ></span>

                                    {{
                                        row.isActive
                                            ? 'Partenaire actif'
                                            : 'Partenaire désactivé'
                                    }}
                                </span>
                            </template>
                        </div>
                    </div>

                    <div class="pharmacy-count">
                        <span class="count-number">
                            {{ row.pharmacies }}
                        </span>

                        <span class="count-label">
                            officine{{ row.pharmacies > 1 ? 's' : '' }}
                        </span>
                    </div>

                    <!--
                        Saisi, pas incrémenté : un administrateur qui recopie
                        une convention connaît le chiffre. Enregistré au
                        changement, donc la ligne ne porte pas de bouton.
                    -->
                    <div>
                        <Form
                            :action="`/admin/insurers/${row.id}`"
                            method="patch"
                            #default="{ submit, processing, errors }"
                        >
                            <div class="row-delay-control">
                                <input
                                    :value="row.standardDelayDays"
                                    name="standard_delay_days"
                                    type="number"
                                    min="1"
                                    max="365"
                                    :disabled="processing"
                                    :aria-label="`Délai standard de ${row.name} en jours`"
                                    class="row-delay-input"
                                    @change="submit"
                                />

                                <span class="row-delay-unit"> j </span>
                            </div>

                            <p
                                v-if="errors.standard_delay_days"
                                class="form-error"
                            >
                                {{ errors.standard_delay_days }}
                            </p>
                        </Form>
                    </div>

                    <!--
                        Les deux champs dans un seul formulaire, contrairement
                        au délai standard : un déclenchement sans taux
                        n'accumule rien, et required_with refuserait la moitié
                        d'une clause. Les deux vides l'effacent.
                    -->
                    <div>
                        <Form
                            :action="`/admin/insurers/${row.id}`"
                            method="patch"
                            #default="{ submit, processing, errors }"
                        >
                            <!--
                                Soumis depuis le conteneur, et seulement quand
                                les deux champs s'accordent : sur @change de
                                chaque input, renseigner le délai puis quitter
                                le champ enverrait une demi-clause et
                                afficherait une erreur required_with en pleine
                                saisie, avant même qu'on ait tapé le taux.
                            -->
                            <div
                                class="row-penalty-control"
                                @change="submitWhenComplete($event, submit)"
                            >
                                <input
                                    :value="row.penaltyTriggerDays ?? ''"
                                    name="penalty_trigger_days"
                                    type="number"
                                    min="1"
                                    max="365"
                                    placeholder="—"
                                    :disabled="processing"
                                    :aria-label="`Déclenchement de la pénalité de ${row.name}, en jours`"
                                    class="row-delay-input"
                                />

                                <span class="row-delay-unit"> j · </span>

                                <input
                                    :value="row.penaltyRatePercent ?? ''"
                                    name="penalty_rate_percent"
                                    type="number"
                                    min="0.01"
                                    max="100"
                                    step="0.01"
                                    placeholder="—"
                                    :disabled="processing"
                                    :aria-label="`Taux de pénalité de ${row.name}, en pourcent`"
                                    class="row-delay-input"
                                />

                                <span class="row-delay-unit"> % </span>
                            </div>

                            <p
                                v-if="errors.penalty_trigger_days"
                                class="form-error"
                            >
                                {{ errors.penalty_trigger_days }}
                            </p>

                            <p
                                v-if="errors.penalty_rate_percent"
                                class="form-error"
                            >
                                {{ errors.penalty_rate_percent }}
                            </p>
                        </Form>
                    </div>

                    <div>
                        <span v-if="row.isActive" class="status-badge active">
                            <span class="status-badge-dot"></span>

                            ACTIF
                        </span>

                        <span v-else class="status-badge inactive">
                            <span class="status-badge-dot"></span>

                            INACTIF
                        </span>
                    </div>

                    <div class="actions-cell">
                        <button
                            type="button"
                            class="action-button rename"
                            @click="startEditing(row)"
                        >
                            <span class="action-icon"> ✎ </span>

                            <span> Renommer </span>
                        </button>

                        <Form
                            :action="`/admin/insurers/${row.id}`"
                            method="patch"
                            class="contents"
                        >
                            <input
                                type="hidden"
                                name="name"
                                :value="row.name"
                            />

                            <input
                                type="hidden"
                                name="is_active"
                                :value="row.isActive ? 0 : 1"
                            />

                            <button
                                type="submit"
                                class="action-button"
                                :class="
                                    row.isActive ? 'deactivate' : 'activate'
                                "
                            >
                                <span class="action-icon">
                                    {{ row.isActive ? '−' : '✓' }}
                                </span>

                                <span>
                                    {{
                                        row.isActive
                                            ? 'Désactiver'
                                            : 'Réactiver'
                                    }}
                                </span>
                            </button>
                        </Form>
                    </div>
                </DataTableRow>
            </DataTable>
        </section>

        <div class="page-footnote">
            <div class="footnote-icon">i</div>

            <p>
                Désactiver un assureur le retire uniquement des formulaires
                disponibles. Ses déclarations et données historiques restent
                conservées.
            </p>
        </div>
    </div>
</template>

<style scoped>
.row-penalty-control {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.input-wrapper.delay-input-wrapper {
    flex: 0 0 auto;

    width: 104px;
}

.delay-input-wrapper .modern-input {
    padding-right: 26px;
}

.input-suffix {
    position: absolute;

    right: 12px;

    font-size: 11px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);

    pointer-events: none;
}

.row-delay-control {
    display: flex;

    align-items: center;

    gap: 6px;
}

.row-delay-input {
    width: 62px;

    height: 32px;

    padding: 0 8px;

    border: 1.5px solid var(--border);

    border-radius: 8px;

    background: #ffffff;

    color: var(--ink);

    font-size: 12px;

    font-weight: 600;

    outline: none;

    transition: border-color 0.2s ease;
}

.row-delay-input:focus {
    border-color: var(--gold-mid);
}

.row-delay-input:disabled {
    opacity: 0.6;
}

.row-delay-unit {
    font-size: 11px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.insurers-page {
    /* La palette vient de :root — voir resources/css/app.css. */

    width: 100%;

    min-height: 100vh;

    padding: 0 10px 60px;
}

.insurers-header {
    position: relative;

    z-index: 5;
}

.insurers-table-section {
    position: relative;

    width: 100%;

    padding: 4px;

    overflow: hidden;

    border-radius: var(--radius-card);

    background: #ffffff;

    box-shadow: var(--surface-shadow);

    animation: fadeUp 0.6s ease 0.05s both;
}

.section-top-line {
    position: absolute;

    left: 0;

    top: 0;

    width: 100%;

    height: 3px;

    background: var(--primary);
}

.insurers-table {
    border-radius: 14px;
}

.insurer-name-cell {
    display: flex;

    align-items: center;

    gap: 10px;

    min-width: 190px;
}

.insurer-avatar {
    width: 36px;

    height: 36px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid color-mix(in srgb, var(--officine) 8%, transparent);

    border-radius: 11px;

    background: var(--primary-soft);

    color: var(--primary-dark);

    font-size: 11px;

    font-weight: 850;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.insurer-avatar.inactive {
    background: var(--cream-state);

    border-color: color-mix(in srgb, var(--ink) 7%, transparent);

    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.insurer-row:hover .insurer-avatar:not(.inactive) {
    transform: scale(1.06);

    box-shadow: 0 5px 12px color-mix(in srgb, var(--officine) 10%, transparent);
}

.insurer-name-content {
    min-width: 0;

    display: flex;

    flex-direction: column;

    gap: 3px;
}

.insurer-name {
    color: var(--ink);

    font-size: 11.5px;

    font-weight: 700;
}

.insurer-name.inactive {
    color: color-mix(in srgb, var(--ink) 48%, transparent);
}

.insurer-subtitle {
    display: inline-flex;

    align-items: center;

    gap: 5px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-size: 12.5px;

    font-weight: 550;
}

.insurer-subtitle.inactive {
    color: color-mix(in srgb, var(--ink) 35%, transparent);
}

.mini-status-dot {
    width: 5px;

    height: 5px;

    border-radius: 50%;

    background: var(--primary);
}

.mini-status-dot.inactive {
    background: color-mix(in srgb, var(--ink) 38%, transparent);
}

.edit-form {
    display: flex;

    align-items: center;

    gap: 5px;

    width: 100%;
}

.edit-input {
    width: 100%;

    min-width: 0;

    height: 32px;

    padding: 0 9px;

    border: 1px solid color-mix(in srgb, var(--officine) 28%, transparent);

    border-radius: 8px;

    outline: none;

    color: var(--ink);

    background: #ffffff;

    font-size: 11px;
}

.edit-input:focus {
    border-color: var(--primary);

    box-shadow: 0 0 0 3px color-mix(in srgb, var(--officine) 7%, transparent);
}

.edit-confirm,
.edit-cancel {
    width: 29px;

    height: 29px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: none;

    border-radius: 7px;

    cursor: pointer;

    font-size: 12px;

    font-weight: 800;
}

.edit-confirm {
    background: var(--primary-soft);

    color: var(--primary-dark);
}

.edit-cancel {
    background: var(--cream-state);

    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.pharmacy-count {
    display: flex;

    align-items: baseline;

    gap: 5px;
}

.count-number {
    color: var(--ink);

    font-size: 12px;

    font-weight: 750;
}

.count-label {
    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-size: 12.5px;
}

.status-badge {
    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 6px 9px;

    border-radius: 7px;

    font-family:
        ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

    font-size: 12.5px;

    font-weight: 750;

    letter-spacing: 0.04em;
}

.status-badge.active {
    border: 1px solid color-mix(in srgb, var(--officine) 10%, transparent);

    background: var(--primary-soft);

    color: var(--primary-dark);
}

.status-badge.inactive {
    border: 1px solid color-mix(in srgb, var(--ink) 7%, transparent);

    background: var(--cream-state);

    color: color-mix(in srgb, var(--ink) 48%, transparent);
}

.status-badge-dot {
    width: 5px;

    height: 5px;

    border-radius: 50%;
}

.status-badge.active .status-badge-dot {
    background: var(--primary);
}

.status-badge.inactive .status-badge-dot {
    background: color-mix(in srgb, var(--ink) 38%, transparent);
}

.actions-cell {
    display: flex;

    align-items: center;

    gap: 6px;

    white-space: nowrap;
}

.action-button {
    height: 30px;

    display: inline-flex;

    align-items: center;

    gap: 5px;

    padding: 0 9px;

    border: 1px solid transparent;

    border-radius: 7px;

    background: transparent;

    font-size: 12.5px;

    font-weight: 700;

    cursor: pointer;

    transition:
        background 0.2s ease,
        border-color 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;
}

.action-button:hover {
    transform: translateY(-1px);
}

.action-button.rename {
    border-color: color-mix(in srgb, var(--officine) 10%, transparent);

    background: var(--primary-soft);

    color: var(--primary-dark);
}

.action-button.rename:hover {
    border-color: color-mix(in srgb, var(--officine) 20%, transparent);

    background: var(--officine-soft);
}

.action-button.deactivate {
    border-color: color-mix(in srgb, var(--terracotta) 10%, transparent);

    background: color-mix(in srgb, var(--terracotta) 4.5%, transparent);

    color: var(--terracotta-dark);
}

.action-button.deactivate:hover {
    background: color-mix(in srgb, var(--terracotta) 9%, transparent);
}

.action-button.activate {
    border-color: color-mix(in srgb, var(--officine) 10%, transparent);

    background: #ffffff;

    color: var(--primary-dark);
}

.action-button.activate:hover {
    background: var(--primary-soft);
}

.action-icon {
    font-size: 11px;

    line-height: 1;
}

.configuration-grid {
    display: grid;

    grid-template-columns: repeat(2, minmax(0, 1fr));

    gap: 14px;

    margin-top: 15px;
}

.configuration-card {
    position: relative;

    padding: 20px;

    overflow: hidden;

    border-radius: var(--radius-card);

    background: #ffffff;

    box-shadow: var(--surface-shadow);
}

.card-header {
    position: relative;

    z-index: 1;

    display: flex;

    align-items: center;

    gap: 11px;
}

.card-icon {
    width: 39px;

    height: 39px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 11px;

    font-size: 17px;

    font-weight: 700;
}

.card-icon.green {
    background: var(--primary-soft);

    color: var(--primary-dark);
}

.card-icon.gold {
    background: var(--gold-soft);

    color: var(--gold-mid);
}

.card-eyebrow {
    display: block;

    margin-bottom: 2px;

    color: var(--primary);

    font-size: 9.5px;

    font-weight: 850;

    letter-spacing: 0.14em;
}

.card-eyebrow.gold {
    color: var(--gold-mid);
}

.card-header h2 {
    margin: 0;

    color: var(--ink);

    font-size: 17px;

    font-weight: 750;

    letter-spacing: -0.015em;
}

.card-description {
    position: relative;

    z-index: 1;

    margin: 12px 0 15px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;

    line-height: 1.55;
}

.add-form {
    position: relative;

    z-index: 2;
}

.form-row {
    display: flex;

    align-items: center;

    gap: 8px;
}

.input-wrapper {
    position: relative;

    flex: 1;
}

.input-icon {
    position: absolute;

    left: 12px;

    top: 50%;

    transform: translateY(-50%);

    color: var(--primary);

    pointer-events: none;
}

.modern-input {
    width: 100%;

    height: 42px;

    padding: 0 12px 0 29px;

    border: 1px solid color-mix(in srgb, var(--ink) 12%, transparent);

    border-radius: 10px;

    outline: none;

    background: #ffffff;

    color: var(--ink);

    font-size: 11px;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.modern-input::placeholder {
    color: color-mix(in srgb, var(--ink) 38%, transparent);
}

.modern-input:focus {
    border-color: color-mix(in srgb, var(--officine) 35%, transparent);

    box-shadow: 0 0 0 3px color-mix(in srgb, var(--officine) 6%, transparent);
}

.primary-button {
    position: relative;

    z-index: 2;

    height: 42px;

    flex-shrink: 0;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 6px;

    padding: 0 15px;

    border: none;

    border-radius: 10px;

    background: var(--primary);

    color: #ffffff;

    font-size: 10.5px;

    font-weight: 750;

    cursor: pointer;

    box-shadow: 0 6px 15px color-mix(in srgb, var(--officine) 13%, transparent);

    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease,
        opacity 0.2s ease;
}

.primary-button:hover {
    transform: translateY(-1px);

    box-shadow: 0 8px 18px color-mix(in srgb, var(--officine) 18%, transparent);
}

.primary-button:disabled {
    cursor: not-allowed;

    opacity: 0.55;

    transform: none;
}

.threshold-form {
    position: relative;

    z-index: 2;
}

.threshold-control {
    display: flex;

    align-items: center;

    gap: 8px;
}

.number-input-wrapper {
    width: 90px;
}

.number-input {
    width: 100%;

    height: 42px;

    padding: 0 12px;

    border: 1px solid color-mix(in srgb, var(--ink) 12%, transparent);

    border-radius: 10px;

    outline: none;

    background: #ffffff;

    color: var(--ink);

    font-size: 12px;

    font-weight: 700;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.number-input:focus {
    border-color: color-mix(in srgb, var(--gold-mid) 45%, transparent);

    box-shadow: 0 0 0 3px color-mix(in srgb, var(--gold-mid) 7%, transparent);
}

.days-label {
    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 10.5px;

    font-weight: 600;
}

.threshold-button {
    margin-left: auto;
}

.form-error {
    margin-top: 7px;

    color: var(--terracotta-dark);

    font-size: 12.5px;

    line-height: 1.4;
}

.anonymity-info {
    position: relative;

    z-index: 2;

    display: flex;

    align-items: flex-start;

    gap: 8px;

    margin-top: 15px;

    padding: 11px;

    border-radius: var(--radius-card);

    background: color-mix(in srgb, var(--gold-mid) 5.5%, transparent);

    box-shadow: var(--surface-shadow);
}

.anonymity-icon {
    width: 18px;

    height: 18px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: var(--gold-mid);

    color: #ffffff;

    font-size: 9px;

    font-weight: 850;
}

.anonymity-info p {
    margin: 0;

    color: color-mix(in srgb, var(--ink) 60%, transparent);

    font-size: 12.5px;

    line-height: 1.5;
}

.anonymity-info strong {
    color: color-mix(in srgb, var(--ink) 78%, transparent);
}

.page-footnote {
    display: flex;

    align-items: flex-start;

    gap: 9px;

    margin-top: 13px;

    padding: 11px 14px;

    border-radius: var(--radius-card);

    background: color-mix(in srgb, var(--officine) 2.5%, transparent);

    box-shadow: var(--surface-shadow);
}

.footnote-icon {
    width: 18px;

    height: 18px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: var(--primary);

    color: #ffffff;

    font-size: 9px;

    font-weight: 850;
}

.page-footnote p {
    margin: 0;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;

    line-height: 1.5;
}

@keyframes fadeUp {
    from {
        opacity: 0;

        transform: translateY(10px);
    }

    to {
        opacity: 1;

        transform: translateY(0);
    }
}

@media (max-width: 950px) {
    .configuration-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .insurers-page {
        padding: 0 4px 50px;
    }

    .insurers-table-section {
        border-radius: 15px;

        padding: 2px;

        overflow-x: auto;
    }

    .actions-cell {
        flex-wrap: wrap;
    }

    .configuration-card {
        padding: 16px;
    }

    .form-row {
        flex-direction: column;

        align-items: stretch;
    }

    .primary-button {
        width: 100%;
    }

    .threshold-control {
        flex-wrap: wrap;
    }

    .threshold-button {
        width: 100%;

        margin-left: 0;
    }
}

@media (max-width: 450px) {
    .insurer-name-cell {
        min-width: 160px;
    }

    .insurer-avatar {
        width: 32px;

        height: 32px;

        border-radius: 9px;
    }

    .actions-cell {
        align-items: flex-start;

        flex-direction: column;
    }

    .action-button {
        width: 100%;

        justify-content: center;
    }
}

@media (prefers-reduced-motion: reduce) {
    .insurers-page *,
    .insurers-page *::before,
    .insurers-page *::after {
        animation-duration: 0.01ms !important;

        animation-iteration-count: 1 !important;

        transition-duration: 0.01ms !important;
    }
}
</style>
