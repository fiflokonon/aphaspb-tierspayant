<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Row = {
    id: number;
    name: string;
    isActive: boolean;
    standardDelayDays: number;
    pharmacies: number;
};

defineProps<{
    insurers: Row[];
    anonymityMinimum: number;
    anonymityFloor: number;
}>();

const TEMPLATE = '2fr .8fr 1.1fr .9fr 1.2fr';
const COLUMNS = [
    'ASSUREUR',
    'OFFICINES (n)',
    'DÉLAI STANDARD',
    'ÉTAT',
    'ACTION',
];

const editing = ref<number | null>(null);
const draft = ref('');

function startEditing(row: Row) {
    editing.value = row.id;
    draft.value = row.name;
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

        <section class="insurers-intro">
            <div class="intro-content">
                <div class="intro-icon">
                    <span>◆</span>
                </div>

                <div class="intro-text">
                    <span class="intro-eyebrow">
                        ADMINISTRATION DU RÉSEAU
                    </span>

                    <h1>Assureurs & courtiers</h1>

                    <p>
                        Gérez les partenaires du réseau, leur disponibilité dans
                        les formulaires et les paramètres de calcul des
                        indicateurs.
                    </p>
                </div>
            </div>

            <div class="intro-badge">
                <span class="badge-dot"></span>

                <span>
                    {{ insurers.length }} partenaire{{
                        insurers.length > 1 ? 's' : ''
                    }}
                </span>
            </div>
        </section>

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
                            <span class="input-icon"> ◆ </span>

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
                    <div class="card-icon gold">◷</div>

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

    color: var(--apha-light);

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

    border: 1.5px solid var(--apha-border);

    border-radius: 8px;

    background: #ffffff;

    color: var(--apha-ink);

    font-size: 12px;

    font-weight: 600;

    outline: none;

    transition: border-color 0.2s ease;
}

.row-delay-input:focus {
    border-color: var(--apha-gold);
}

.row-delay-input:disabled {
    opacity: 0.6;
}

.row-delay-unit {
    font-size: 11px;

    color: var(--apha-muted);
}

.insurers-page {
    --apha-primary: #008f83;
    --apha-primary-dark: #006f68;
    --apha-primary-soft: #e8f6f3;

    --apha-gold: #d7a33d;
    --apha-gold-soft: #fff8e9;

    --apha-ink: #243333;
    --apha-muted: #788585;
    --apha-light: #a2adad;

    --apha-border: #e7eceb;

    --apha-background: #f7f9f9;

    width: 100%;

    min-height: 100vh;

    padding: 0 10px 60px;

    /* background:
        radial-gradient(
            circle at 95% 0%,
            rgba(0, 143, 131, .045),
            transparent 28%
        ); */
}

.insurers-header {
    position: relative;

    z-index: 5;
}

.insurers-intro {
    position: relative;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 25px;

    margin: 16px 0 22px;

    padding: 22px 25px;

    overflow: hidden;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: linear-gradient(110deg, #ffffff 0%, #f8fcfb 100%);
    /* 
    box-shadow:
        0 8px 30px
        rgba(35, 70, 68, .035); */

    animation: fadeUp 0.5s ease both;
}

.insurers-intro::after {
    content: '';

    position: absolute;

    right: -70px;

    top: -90px;

    width: 220px;

    height: 220px;

    border-radius: 50%;

    background: radial-gradient(
        circle,
        rgba(0, 143, 131, 0.09),
        transparent 68%
    );

    pointer-events: none;
}

.intro-content {
    position: relative;

    z-index: 1;

    display: flex;

    align-items: center;

    gap: 15px;
}

.intro-icon {
    width: 48px;

    height: 48px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 14px;

    background: linear-gradient(
        135deg,
        var(--apha-primary),
        var(--apha-primary-dark)
    );

    color: #ffffff;

    font-size: 15px;

    /* box-shadow:
        0 8px 18px
        rgba(0, 143, 131, .18); */
}

.intro-text {
    display: flex;

    flex-direction: column;
}

.intro-eyebrow {
    margin-bottom: 3px;

    color: var(--apha-primary);

    font-size: 8.5px;

    font-weight: 850;

    letter-spacing: 0.14em;
}

.intro-text h1 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 20px;

    font-weight: 800;

    letter-spacing: -0.025em;
}

.intro-text p {
    max-width: 760px;

    margin-top: 4px;

    color: var(--apha-muted);

    font-size: 10.5px;

    line-height: 1.55;
}

.intro-badge {
    position: relative;

    z-index: 2;

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 8px 12px;

    border: 1px solid rgba(0, 143, 131, 0.08);

    border-radius: 30px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);

    font-size: 9px;

    font-weight: 700;

    white-space: nowrap;
}

.badge-dot {
    width: 7px;

    height: 7px;

    border-radius: 50%;

    background: var(--apha-primary);

    box-shadow: 0 0 0 4px rgba(0, 143, 131, 0.07);
}

.insurers-table-section {
    position: relative;

    width: 100%;

    padding: 4px;

    overflow: hidden;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: #ffffff;

    box-shadow: 0 8px 30px rgba(35, 70, 68, 0.035);

    animation: fadeUp 0.6s ease 0.05s both;
}

.section-top-line {
    position: absolute;

    left: 0;

    top: 0;

    width: 100%;

    height: 3px;

    background: linear-gradient(
        90deg,
        var(--apha-primary),
        #35a799,
        var(--apha-gold)
    );
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

    border: 1px solid rgba(0, 143, 131, 0.08);

    border-radius: 11px;

    background: linear-gradient(135deg, #e6f6f2, #f3faf8);

    color: var(--apha-primary-dark);

    font-size: 11px;

    font-weight: 850;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.insurer-avatar.inactive {
    background: #f1f3f3;

    border-color: rgba(36, 51, 51, 0.07);

    color: var(--apha-muted);
}

.insurer-row:hover .insurer-avatar:not(.inactive) {
    transform: scale(1.06);

    box-shadow: 0 5px 12px rgba(0, 143, 131, 0.1);
}

.insurer-name-content {
    min-width: 0;

    display: flex;

    flex-direction: column;

    gap: 3px;
}

.insurer-name {
    color: var(--apha-ink);

    font-size: 11.5px;

    font-weight: 700;
}

.insurer-name.inactive {
    color: rgba(36, 51, 51, 0.48);
}

.insurer-subtitle {
    display: inline-flex;

    align-items: center;

    gap: 5px;

    color: var(--apha-light);

    font-size: 8px;

    font-weight: 550;
}

.insurer-subtitle.inactive {
    color: rgba(36, 51, 51, 0.35);
}

.mini-status-dot {
    width: 5px;

    height: 5px;

    border-radius: 50%;

    background: var(--apha-primary);
}

.mini-status-dot.inactive {
    background: #a5aeae;
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

    border: 1px solid rgba(0, 143, 131, 0.28);

    border-radius: 8px;

    outline: none;

    color: var(--apha-ink);

    background: #ffffff;

    font-size: 11px;
}

.edit-input:focus {
    border-color: var(--apha-primary);

    box-shadow: 0 0 0 3px rgba(0, 143, 131, 0.07);
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
    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);
}

.edit-cancel {
    background: #f3f4f4;

    color: var(--apha-muted);
}

.pharmacy-count {
    display: flex;

    align-items: baseline;

    gap: 5px;
}

.count-number {
    color: var(--apha-ink);

    font-size: 12px;

    font-weight: 750;
}

.count-label {
    color: var(--apha-light);

    font-size: 8.5px;
}

.status-badge {
    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 6px 9px;

    border-radius: 7px;

    font-family:
        ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

    font-size: 8.5px;

    font-weight: 750;

    letter-spacing: 0.04em;
}

.status-badge.active {
    border: 1px solid rgba(0, 143, 131, 0.1);

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);
}

.status-badge.inactive {
    border: 1px solid rgba(36, 51, 51, 0.07);

    background: #f2f4f4;

    color: rgba(36, 51, 51, 0.48);
}

.status-badge-dot {
    width: 5px;

    height: 5px;

    border-radius: 50%;
}

.status-badge.active .status-badge-dot {
    background: var(--apha-primary);
}

.status-badge.inactive .status-badge-dot {
    background: #9ba5a5;
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

    font-size: 9.5px;

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
    border-color: rgba(0, 143, 131, 0.1);

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);
}

.action-button.rename:hover {
    border-color: rgba(0, 143, 131, 0.2);

    background: #dff3ef;
}

.action-button.deactivate {
    border-color: rgba(197, 82, 69, 0.1);

    background: rgba(197, 82, 69, 0.045);

    color: #b34e43;
}

.action-button.deactivate:hover {
    background: rgba(197, 82, 69, 0.09);
}

.action-button.activate {
    border-color: rgba(0, 143, 131, 0.1);

    background: #ffffff;

    color: var(--apha-primary-dark);
}

.action-button.activate:hover {
    background: var(--apha-primary-soft);
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

    border: 1px solid var(--apha-border);

    border-radius: 16px;

    background: #ffffff;

    box-shadow: 0 7px 25px rgba(35, 70, 68, 0.03);
}

.configuration-card::after {
    content: '';

    position: absolute;

    right: -55px;

    bottom: -70px;

    width: 150px;

    height: 150px;

    border-radius: 50%;

    background: radial-gradient(
        circle,
        rgba(0, 143, 131, 0.045),
        transparent 68%
    );

    pointer-events: none;
}

.threshold-card::after {
    background: radial-gradient(
        circle,
        rgba(215, 163, 61, 0.06),
        transparent 68%
    );
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
    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);
}

.card-icon.gold {
    background: var(--apha-gold-soft);

    color: var(--apha-gold);
}

.card-eyebrow {
    display: block;

    margin-bottom: 2px;

    color: var(--apha-primary);

    font-size: 7.5px;

    font-weight: 850;

    letter-spacing: 0.12em;
}

.card-eyebrow.gold {
    color: var(--apha-gold);
}

.card-header h2 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 13px;

    font-weight: 750;

    letter-spacing: -0.015em;
}

.card-description {
    position: relative;

    z-index: 1;

    margin: 12px 0 15px;

    color: var(--apha-muted);

    font-size: 10px;

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

    color: var(--apha-primary);

    font-size: 8px;

    pointer-events: none;
}

.modern-input {
    width: 100%;

    height: 42px;

    padding: 0 12px 0 29px;

    border: 1px solid rgba(36, 51, 51, 0.12);

    border-radius: 10px;

    outline: none;

    background: #ffffff;

    color: var(--apha-ink);

    font-size: 11px;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.modern-input::placeholder {
    color: rgba(36, 51, 51, 0.38);
}

.modern-input:focus {
    border-color: rgba(0, 143, 131, 0.35);

    box-shadow: 0 0 0 3px rgba(0, 143, 131, 0.06);
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

    background: linear-gradient(
        135deg,
        var(--apha-primary),
        var(--apha-primary-dark)
    );

    color: #ffffff;

    font-size: 10.5px;

    font-weight: 750;

    cursor: pointer;

    box-shadow: 0 6px 15px rgba(0, 143, 131, 0.13);

    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease,
        opacity 0.2s ease;
}

.primary-button:hover {
    transform: translateY(-1px);

    box-shadow: 0 8px 18px rgba(0, 143, 131, 0.18);
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

    border: 1px solid rgba(36, 51, 51, 0.12);

    border-radius: 10px;

    outline: none;

    background: #ffffff;

    color: var(--apha-ink);

    font-size: 12px;

    font-weight: 700;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.number-input:focus {
    border-color: rgba(215, 163, 61, 0.45);

    box-shadow: 0 0 0 3px rgba(215, 163, 61, 0.07);
}

.days-label {
    color: var(--apha-muted);

    font-size: 10.5px;

    font-weight: 600;
}

.threshold-button {
    margin-left: auto;
}

.form-error {
    margin-top: 7px;

    color: #b34e43;

    font-size: 9.5px;

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

    border: 1px solid rgba(215, 163, 61, 0.15);

    border-radius: 10px;

    background: rgba(215, 163, 61, 0.055);
}

.anonymity-icon {
    width: 18px;

    height: 18px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: var(--apha-gold);

    color: #ffffff;

    font-size: 9px;

    font-weight: 850;
}

.anonymity-info p {
    margin: 0;

    color: rgba(36, 51, 51, 0.6);

    font-size: 9px;

    line-height: 1.5;
}

.anonymity-info strong {
    color: rgba(36, 51, 51, 0.78);
}

.page-footnote {
    display: flex;

    align-items: flex-start;

    gap: 9px;

    margin-top: 13px;

    padding: 11px 14px;

    border: 1px solid rgba(0, 143, 131, 0.07);

    border-radius: 11px;

    background: rgba(0, 143, 131, 0.025);
}

.footnote-icon {
    width: 18px;

    height: 18px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: var(--apha-primary);

    color: #ffffff;

    font-size: 9px;

    font-weight: 850;
}

.page-footnote p {
    margin: 0;

    color: var(--apha-muted);

    font-size: 9px;

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

    .insurers-intro {
        align-items: flex-start;

        flex-direction: column;

        margin-top: 10px;

        padding: 17px;

        border-radius: 15px;
    }

    .intro-content {
        align-items: flex-start;
    }

    .intro-icon {
        width: 41px;

        height: 41px;

        border-radius: 11px;
    }

    .intro-text h1 {
        font-size: 17px;
    }

    .intro-text p {
        font-size: 9.5px;
    }

    .intro-badge {
        width: 100%;

        justify-content: center;
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
