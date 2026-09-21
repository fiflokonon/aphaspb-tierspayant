<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

const props = defineProps<{
    downloadUrl: string;
    columns: string[];
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    insurer: number | null;
    insurers: { id: number; name: string }[];
    pharmacyName: string;
}>();

const period = ref(props.period);
const insurer = ref(props.insurer);

const insurerOptions = computed(() => [
    { value: null, label: 'Tous mes assureurs' },
    ...props.insurers.map((one) => ({ value: one.id, label: one.name })),
]);

/** Chaque lien porte les filtres : le fichier couvre l'écran, pas autre chose. */
const hrefFor = (format: 'csv' | 'xlsx' | 'pdf') => {
    const query = new URLSearchParams({ period: period.value, format });

    if (insurer.value) {
        query.set('insurer', String(insurer.value));
    }

    return `${props.downloadUrl}?${query.toString()}`;
};

const pdfHref = computed(() => hrefFor('pdf'));
const xlsxHref = computed(() => hrefFor('xlsx'));
const csvHref = computed(() => hrefFor('csv'));

const FORMATS = [
    {
        key: 'pdf' as const,
        badge: 'PDF',
        title: 'Relevé mis en page',
        lede: "Vos totaux, le détail par assureur puis mois par mois, avec chaque versement et sa date. C'est le document à joindre à un dossier bancaire ou à emporter en réunion.",
        primary: true,
    },
    {
        key: 'xlsx' as const,
        badge: 'XLSX',
        title: 'Classeur Excel',
        lede: 'Une ligne par mois et par assureur, avec des cellules numériques : une colonne s’additionne sans conversion.',
        primary: false,
    },
    {
        key: 'csv' as const,
        badge: 'CSV',
        title: 'Fichier brut',
        lede: 'Les mêmes lignes pour un réimport : séparateur point-virgule, décimales à la virgule, UTF-8 avec BOM.',
        primary: false,
    },
];

function hrefOf(key: 'csv' | 'xlsx' | 'pdf'): string {
    return key === 'pdf'
        ? pdfHref.value
        : key === 'xlsx'
          ? xlsxHref.value
          : csvHref.value;
}

function reload() {
    router.get(
        '/pharmacy/exports',
        { period: period.value, insurer: insurer.value },
        {
            only: ['period', 'periodLabel', 'insurer'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch([period, insurer], reload);
</script>

<template>
    <Head title="Exporter mes données" />

    <ConsoleHeader title="Exporter mes données" class="exports-header">
        <template #filters>
            <FilterSelect
                v-model="period"
                :options="periods"
                label="Période"
                aria-label="Filtrer par période"
            />
            <FilterSelect
                v-model="insurer"
                :options="insurerOptions"
                label="Assureur"
                aria-label="Filtrer par assureur"
            />
        </template>
    </ConsoleHeader>

    <div class="exports-page">
        <section class="exports-intro">
            <div class="intro-copy">
                <span class="intro-eyebrow"> VOS DONNÉES </span>

                <h1>{{ pharmacyName }}</h1>

                <p>
                    <strong>{{ periodLabel }}</strong
                    >{{
                        insurer
                            ? ` · ${insurers.find((one) => one.id === insurer)?.name}`
                            : ' · tous mes assureurs'
                    }}. Ces fichiers ne contiennent que vos propres
                    déclarations.
                </p>
            </div>

            <div class="privacy-badge">
                <span class="privacy-dot"></span>

                <span> Vos notes privées y figurent </span>
            </div>
        </section>

        <section class="formats">
            <article
                v-for="format in FORMATS"
                :key="format.key"
                class="format-card"
                :class="{ 'format-card-primary': format.primary }"
            >
                <div class="format-badge">{{ format.badge }}</div>

                <div class="format-copy">
                    <h2>{{ format.title }}</h2>

                    <p>{{ format.lede }}</p>
                </div>

                <a
                    :href="hrefOf(format.key)"
                    class="format-action"
                    :class="{ 'format-action-primary': format.primary }"
                >
                    <span>Télécharger</span>
                    <span class="format-arrow">→</span>
                </a>
            </article>
        </section>

        <section class="columns-section">
            <div class="columns-header">
                <span class="section-label"> STRUCTURE DU TABLEAU </span>

                <span class="columns-count">
                    {{ columns.length }} <span>colonnes</span>
                </span>
            </div>

            <p class="columns-lede">
                Colonnes du CSV et du classeur. Les versements d'un mois
                tiennent dans une seule cellule, séparés par « | », pour que le
                fichier reste à une ligne par mois et par assureur.
            </p>

            <ul class="columns-list">
                <li v-for="column in columns" :key="column">{{ column }}</li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.exports-page {
    padding: 22px 0 40px;
}

.exports-intro {
    display: flex;
    align-items: flex-start;

    flex-wrap: wrap;

    gap: 16px;

    padding-bottom: 20px;

    border-bottom: 1px solid var(--border);
}

.intro-eyebrow {
    font-family: var(--font-mono, ui-monospace, monospace);

    font-size: 9.5px;

    font-weight: 600;

    letter-spacing: 0.14em;

    color: var(--primary);
}

.intro-copy h1 {
    margin: 6px 0 0;

    font-size: 24px;

    line-height: 1.2;
}

.intro-copy p {
    margin: 8px 0 0;

    max-width: 62ch;

    font-size: 12.5px;

    line-height: 1.6;

    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.privacy-badge {
    display: inline-flex;
    align-items: center;

    gap: 7px;

    margin-left: auto;

    padding: 7px 12px;

    border: 1px solid var(--border);

    border-radius: 999px;

    font-size: 11px;

    font-weight: 600;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    white-space: nowrap;
}

.privacy-dot {
    width: 6px;
    height: 6px;

    border-radius: 999px;

    background: var(--gold-mid);
}

.formats {
    display: flex;

    flex-direction: column;

    gap: 12px;

    margin-top: 22px;
}

.format-card {
    display: flex;
    align-items: center;

    flex-wrap: wrap;

    gap: 16px;

    padding: 16px 18px;

    border-radius: var(--radius-card);

    box-shadow: var(--surface-shadow);

    background: var(--card);
}

.format-badge {
    flex-shrink: 0;

    display: grid;

    place-items: center;

    width: 46px;
    height: 46px;

    border-radius: 11px;

    background: var(--cream-state);

    font-family: var(--font-mono, ui-monospace, monospace);

    font-size: 10.5px;

    font-weight: 700;

    color: var(--ink);
}

.format-card-primary .format-badge {
    background: var(--primary-soft);

    color: var(--primary-dark);
}

.format-copy {
    flex: 1;

    min-width: 220px;
}

.format-copy h2 {
    margin: 0;

    font-size: 17px;
}

.format-copy p {
    margin: 4px 0 0;

    max-width: 68ch;

    font-size: 12.5px;

    line-height: 1.55;

    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.format-action {
    display: inline-flex;
    align-items: center;

    gap: 8px;

    padding: 10px 16px;

    border: 1px solid var(--border);

    border-radius: 10px;

    font-size: 12px;

    font-weight: 650;

    color: var(--ink);

    white-space: nowrap;
}

.format-action:hover {
    border-color: var(--ink);
}

.format-action-primary {
    border-color: transparent;

    background: var(--primary);

    color: #fff;
}

.format-action-primary:hover {
    background: var(--primary-dark);

    border-color: transparent;
}

.columns-section {
    margin-top: 26px;

    padding-top: 20px;

    border-top: 1px solid var(--border);
}

.columns-header {
    display: flex;
    align-items: baseline;

    gap: 12px;
}

.section-label {
    font-family: var(--font-mono, ui-monospace, monospace);

    font-size: 9.5px;

    font-weight: 600;

    letter-spacing: 0.14em;

    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.columns-count {
    margin-left: auto;

    font-size: 13px;

    font-weight: 700;
}

.columns-count span {
    font-size: 11px;

    font-weight: 500;

    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.columns-lede {
    margin: 8px 0 0;

    max-width: 68ch;

    font-size: 12.5px;

    line-height: 1.55;

    color: color-mix(in srgb, var(--ink) 55%, transparent);
}

.columns-list {
    display: flex;

    flex-wrap: wrap;

    gap: 6px;

    margin: 14px 0 0;

    padding: 0;

    list-style: none;
}

.columns-list li {
    padding: 5px 10px;

    border: 1px solid var(--border);

    border-radius: 7px;

    background: var(--cream-state);

    font-family: var(--font-mono, ui-monospace, monospace);

    font-size: 10.5px;

    color: var(--ink);
}
</style>
