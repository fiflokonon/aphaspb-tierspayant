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
    city: string | null;
    cities: string[];
    insurer: number | null;
    insurers: { id: number; name: string }[];
    /** The anonymity threshold as set, never a hardcoded five. */
    anonymityThreshold: number;
}>();

const period = ref(props.period);
const city = ref(props.city);
const insurer = ref(props.insurer);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

const insurerOptions = computed(() => [
    { value: null, label: 'Tous les assureurs' },
    ...props.insurers.map((one) => ({ value: one.id, label: one.name })),
]);

const insurerName = computed(
    () => props.insurers.find((one) => one.id === insurer.value)?.name,
);

/** Each link carries the filters, so the file matches the screen above it. */
const hrefFor = (format: 'csv' | 'xlsx' | 'pdf') => {
    const query = new URLSearchParams({ period: period.value, format });

    if (city.value) {
        query.set('city', city.value);
    }

    if (insurer.value) {
        query.set('insurer', String(insurer.value));
    }

    return `${props.downloadUrl}?${query.toString()}`;
};

const csvHref = computed(() => hrefFor('csv'));
const xlsxHref = computed(() => hrefFor('xlsx'));
const pdfHref = computed(() => hrefFor('pdf'));

function reload() {
    router.get(
        '/admin/csv-exports',
        { period: period.value, city: city.value, insurer: insurer.value },
        {
            only: ['period', 'periodLabel', 'city', 'insurer'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch([period, city, insurer], reload);
</script>

<template>
    <Head title="Exports CSV" />

    <div class="exports-page">
        <!-- =========================================================
             INTRODUCTION + FILTRES
        ========================================================== -->
        <br>
        <section class="exports-intro">
            <div class="intro-decoration intro-decoration-one"></div>
            <div class="intro-decoration intro-decoration-two"></div>

            <div class="intro-main">
                <div class="intro-content">
                    <div class="intro-icon" aria-hidden="true">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path
                                d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7Z"
                            />
                            <path d="M14 2v5h5" />
                            <path
                                d="M8 12h8M8 16h8M8 8h2"
                                stroke-linecap="round"
                            />
                        </svg>
                    </div>

                    <div class="intro-text">
                      

                        <h1>Exports CSV</h1>

                    

                        <!-- <div class="intro-period">
                            <span class="period-dot"></span>

                            <span>
                                {{ periodLabel }}
                                {{ city ? ` · ${city}` : ' · toutes les villes' }}
                                {{
                                    insurerName
                                        ? ` · ${insurerName}`
                                        : ' · tous les assureurs'
                                }}
                            </span>
                        </div> -->
                    </div>
                </div>

                <!-- FILTRES -->
                <div class="compact-filters">
    <div class="filter-item">
        <label for="period">Période</label>
        <FilterSelect
            id="period"
            v-model="period"
            :options="periods"
            aria-label="Choisir la période"
        />
    </div>

    <div class="filter-item">
        <label for="city">Ville</label>
        <FilterSelect
            id="city"
            v-model="city"
            :options="cityOptions"
            aria-label="Filtrer par ville"
        />
    </div>

    <div class="filter-item">
        <label for="insurer">Assureur</label>
        <FilterSelect
            id="insurer"
            v-model="insurer"
            :options="insurerOptions"
            aria-label="Filtrer par assureur"
        />
    </div>
</div>
            </div>
        </section>

        <!-- =========================================================
             CONTENU
        ========================================================== -->
        <div class="exports-body">
            <!-- =====================================================
                 EXPORT PRINCIPAL
            ====================================================== -->
            <section class="export-card">
                <div class="card-accent"></div>

                <div class="export-card-header">
                    <div class="card-heading">
                        <span class="section-label">
                            FICHIER D'EXPORT
                        </span>

                        <h2>
                            Statistiques agrégées par assureur
                        </h2>

                        <p class="heading-description">
                            Un export synthétique des indicateurs du réseau,
                            préparé pour l'analyse et le suivi des performances.
                        </p>
                    </div>

                    <div class="period-badge">
                        <span class="period-dot"></span>

                        <span>
                            {{ periodLabel }}
                            {{ city ? ` · ${city}` : ' · toutes les villes' }}
                            {{
                                insurerName
                                    ? ` · ${insurerName}`
                                    : ' · tous les assureurs'
                            }}
                        </span>
                    </div>
                </div>

                <!-- =================================================
                     INFORMATION
                ================================================== -->
                <div class="export-description">
                    <div class="description-icon">
                        i
                    </div>

                    <div class="description-content">
                        <span class="description-title">
                            Format des données
                        </span>

                        <p>
                            <strong>{{ periodLabel }}</strong>
                            {{
                                city
                                    ? ` · ${city}`
                                    : ' · toutes les villes'
                            }}
                            {{
                                insurerName
                                    ? ` · ${insurerName}`
                                    : ' · tous les assureurs'
                            }},
                            {{
                                insurerName
                                    ? 'une seule ligne.'
                                    : 'une ligne par assureur.'
                            }}
                            Le classeur Excel contient des cellules numériques
                            directement exploitables. Le CSV reste adapté au
                            réimport avec séparateur point-virgule, décimales à
                            la virgule et encodage UTF-8 avec BOM.
                        </p>
                    </div>
                </div>

                <!-- =================================================
                     TÉLÉCHARGEMENTS
                ================================================== -->
                <div class="download-area">
                    <div class="download-info">
                        <div class="file-icon">
                            <span>DATA</span>
                        </div>

                        <div class="file-details">
                            <span class="file-title">
                                Fichiers disponibles
                            </span>

                            <span class="file-subtitle">
                                Excel
                                <span class="separator">·</span>
                                PDF
                                <span class="separator">·</span>
                                CSV
                            </span>
                        </div>
                    </div>

                    <div class="download-actions">
                        <a
                            :href="xlsxHref"
                            class="download-button"
                        >
                            <span class="download-button-icon">
                                ↓
                            </span>

                            <span class="download-button-text">
                                Classeur Excel
                            </span>

                            <span class="download-button-arrow">
                                →
                            </span>
                        </a>

                        <a
                            :href="pdfHref"
                            class="download-button download-button-secondary"
                        >
                            <span class="download-button-icon">
                                ↓
                            </span>

                            <span class="download-button-text">
                                Rapport PDF
                            </span>
                        </a>

                        <a
                            :href="csvHref"
                            class="download-button download-button-secondary"
                        >
                            <span class="download-button-icon">
                                ↓
                            </span>

                            <span class="download-button-text">
                                CSV
                            </span>
                        </a>
                    </div>
                </div>

                <!-- =================================================
                     STRUCTURE DU FICHIER
                ================================================== -->
                <div class="columns-section">
                    <div class="columns-header">
                        <div class="columns-title-group">
                            <span class="section-label">
                                STRUCTURE DU FICHIER
                            </span>

                            <h3>
                                Colonnes exportées
                            </h3>
                        </div>

                        <span class="columns-count">
                            {{ columns.length }}
                            <span>colonnes</span>
                        </span>
                    </div>

                    <div class="columns-list">
                        <span
                            v-for="column in columns"
                            :key="column"
                            class="column-tag"
                        >
                            <span class="column-dot"></span>
                            {{ column }}
                        </span>
                    </div>
                </div>
            </section>

            <!-- =====================================================
                 PROTECTION DES DONNÉES
            ====================================================== -->
            <section class="privacy-card">
                <div class="privacy-icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >
                        <path
                            d="M12 3 5 6v5c0 4.8 2.9 8.5 7 10 4.1-1.5 7-5.2 7-10V6l-7-3Z"
                        />
                        <path
                            d="m9 12 2 2 4-4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>

                <div class="privacy-content">
                    <span class="privacy-label">
                        PROTECTION DES DONNÉES
                    </span>

                    <h3>
                        Ce que le fichier ne contient jamais
                    </h3>

                    <p>
                        Aucun nom d'officine, aucun montant individuel,
                        aucune note privée. Un assureur déclaré par moins de
                        <strong>{{ anonymityThreshold }} officines</strong>
                        apparaît avec une mention de données insuffisantes,
                        sans chiffre détaillé.
                    </p>
                </div>

                <div class="privacy-shield">
                    <span>{{ anonymityThreshold }}+</span>
                    <small>officines</small>
                </div>
            </section>

            <!-- =====================================================
                 NOTE DE BAS DE PAGE
            ====================================================== -->
            <p class="page-source">
                Les données exportées restent agrégées afin de préserver
                l'anonymat des officines participantes.
            </p>
        </div>
    </div>
</template>

<style scoped>
/* ================================================================
   PALETTE
================================================================ */

.exports-page {
    --page-bg: #f8faf9;
    --surface: #ffffff;
    --surface-soft: #fbfdfc;

    --ink: #243a32;
    --muted: #788780;
    --muted-light: #9da8a3;

    --primary: #00664c;
    --primary-dark: #005741;
    --primary-soft: #eef7f3;

    --gold: #b08a45;
    --gold-soft: #fbf7ef;

    --border: #e5ebe8;

    width: 100%;
    padding: 0 10px 60px;

    color: var(--ink);
    font-family:
        "Manrope",
        ui-sans-serif,
        system-ui,
        sans-serif;
}


.compact-filters {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    margin-top: 20px;
    padding-top: 16px;
    /* border-top: 1px solid var(--border); */
}

.filter-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
    width: 170px;
}

.filter-item label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
}

.filter-item :deep(select),
.filter-item :deep(button) {
    min-height: 36px;
    height: 36px;
    font-size: 13px;
    border-radius: 9px;
}

@media (max-width: 850px) {
    .compact-filters {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
    }

    .filter-item {
        width: 100%;
    }
}

@media (max-width: 600px) {
    .compact-filters {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 420px) {
    .compact-filters {
        grid-template-columns: 1fr;
    }
}

/* ================================================================
   INTRO
================================================================ */

.exports-intro {
    position: relative;
    overflow: hidden;
    margin-bottom: 22px;
    padding: 20px 22px;
    border: 1px solid var(--border);
    border-radius: 20px;

    background:
        linear-gradient(
            135deg,
            #ffffff 0%,
            #fbfdfc 55%,
            #f6faf8 100%
        );

    /* box-shadow:
        0 10px 28px rgba(30, 60, 48, 0.045),
        0 2px 6px rgba(30, 60, 48, 0.025); */

    isolation: isolate;

    animation: introAppear 0.5s ease both;
}

.exports-intro::before {
    content: "";

    position: absolute;
    top: 0;
    right: 0;
    left: 0;

    height: 3px;

    background:
        linear-gradient(
            90deg,
            var(--primary),
            #2b8c70 58%,
            var(--gold)
        );
}

.intro-main {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 35px;
}

.intro-content {
    display: flex;
    align-items: center;

    min-width: 0;

    gap: 17px;
}

.intro-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    flex: 0 0 50px;

    width: 50px;
    height: 50px;

    border-radius: 14px;

    color: #ffffff;

    background:
        linear-gradient(
            145deg,
            var(--primary),
            var(--primary-dark)
        );

    /* box-shadow:
        0 8px 18px rgba(0, 102, 76, 0.16); */
}

.intro-icon svg {
    width: 25px;
    height: 25px;
}

.intro-text {
    min-width: 0;
}

.intro-eyebrow {
    display: block;

    margin-bottom: 5px;

    color: var(--primary);

    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.14em;
}

.intro-text h1 {
    margin: 0;

    color: var(--ink);

    font-size: 21px;
    font-weight: 800;

    letter-spacing: -0.025em;
}

.intro-description {
    max-width: 620px;

    margin: 5px 0 8px;

    color: var(--muted);

    font-size: 12px;
    line-height: 1.55;
}

.intro-period {
    display: flex;
    align-items: center;

    gap: 7px;

    color: var(--muted);

    font-size: 10.5px;
    font-weight: 650;
}

.period-dot {
    width: 6px;
    height: 6px;

    flex: 0 0 6px;

    border-radius: 50%;

    background: var(--primary);
/* 
    box-shadow:
        0 0 0 3px rgba(0, 102, 76, 0.08); */
}

/* ================================================================
   DÉCORATIONS
================================================================ */

.intro-decoration {
    position: absolute;

    pointer-events: none;

    border: 1px solid rgba(0, 102, 76, 0.05);

    border-radius: 50%;
}

.intro-decoration-one {
    top: -90px;
    right: 20%;

    width: 190px;
    height: 190px;
}

.intro-decoration-two {
    right: -70px;
    bottom: -100px;

    width: 200px;
    height: 200px;

    border-color: rgba(176, 138, 69, 0.07);
}

/* ================================================================
   FILTRES
================================================================ */

.intro-filters {
    flex: 0 0 auto;

    min-width: 430px;

    padding-left: 26px;

    border-left: 1px solid var(--border);
}

.filter-heading {
    display: flex;
    align-items: center;

    gap: 7px;

    margin-bottom: 10px;

    color: var(--muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: 0.13em;
    text-transform: uppercase;
}

.filter-heading-line {
    width: 15px;
    height: 1px;

    background: var(--gold);
}

.filters-grid {
    display: grid;

    grid-template-columns:
        repeat(3, minmax(105px, 1fr));

    gap: 8px;
}



/* ================================================================
   BODY
================================================================ */

.exports-body {
    display: flex;
    flex-direction: column;

    gap: 18px;
}

/* ================================================================
   CARTE EXPORT
================================================================ */

.export-card {
    position: relative;
    overflow: hidden;

    padding: 27px 28px;

    border: 1px solid var(--border);
    border-radius: 20px;

    background: var(--surface);

    /* box-shadow:
        0 10px 28px rgba(30, 60, 48, 0.04),
        0 2px 5px rgba(30, 60, 48, 0.025); */

    animation: fadeUp 0.55s ease 0.05s both;
}

.card-accent {
    position: absolute;

    top: 0;
    right: 0;
    left: 0;

    height: 3px;

    background:
        linear-gradient(
            90deg,
            var(--primary),
            #2b8c70 60%,
            var(--gold)
        );
}

.export-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;

    gap: 25px;

    padding-bottom: 20px;

    border-bottom: 1px solid var(--border);
}

.card-heading {
    min-width: 0;
}

.section-label {
    display: block;

    margin-bottom: 5px;

    color: var(--primary);

    font-size: 13px;
    font-weight: 850;

    letter-spacing: 0.14em;
}

.card-heading h2 {
    margin: 0;

    color: var(--ink);

    font-size: 17px;
    font-weight: 800;

    letter-spacing: -0.02em;
}

.heading-description {
    max-width: 700px;

    margin: 5px 0 0;

    color: var(--muted);

    font-size: 13px;
    line-height: 1.55;
}

.period-badge {
    display: inline-flex;
    align-items: center;

    flex-shrink: 0;

    gap: 8px;

    min-height: 34px;

    padding: 0 12px;

    border: 1px solid rgba(0, 102, 76, 0.1);
    border-radius: 9px;

    background: var(--primary-soft);

    color: var(--primary-dark);

    font-size: 13px;
    font-weight: 750;

    white-space: nowrap;
}

/* ================================================================
   DESCRIPTION
================================================================ */

.export-description {
    display: flex;
    align-items: flex-start;

    gap: 12px;

    margin-top: 19px;
    padding: 14px 15px;

    border: 1px solid rgba(0, 102, 76, 0.05);
    border-radius: 13px;

    background: var(--surface-soft);
}

.description-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    flex: 0 0 22px;

    width: 22px;
    height: 22px;

    border-radius: 50%;

    background: var(--primary);

    color: #ffffff;

    font-size: 10px;
    font-weight: 850;
}

.description-content {
    min-width: 0;
}

.description-title {
    display: block;

    margin-bottom: 3px;

    color: var(--ink);

    /* font-size: 11px; */
    font-weight: 750;
}

.export-description p {
    margin: 0;

    color: var(--muted);

    /* font-size: 13px; */
    line-height: 1.55;
}

.export-description strong {
    color: var(--ink);
    font-weight: 800;
}

/* ================================================================
   DOWNLOAD
================================================================ */

.download-area {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;

    margin-top: 18px;
    padding: 14px;

    border: 1px solid var(--border);
    border-radius: 14px;

    background: var(--gold-soft);

    transition:
        box-shadow 0.2s ease,
        transform 0.2s ease;
}

.download-area:hover {
    /* box-shadow:
        0 8px 20px rgba(50, 65, 55, 0.05); */
}

.download-info {
    display: flex;
    align-items: center;

    gap: 11px;

    min-width: 0;
}

.file-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    flex: 0 0 43px;

    width: 43px;
    height: 43px;

    border: 1px solid rgba(0, 102, 76, 0.08);
    border-radius: 11px;

    background: var(--primary-soft);

    color: var(--primary-dark);
}

.file-icon span {
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 0.04em;
}

.file-details {
    min-width: 0;
}

.file-title {
    display: block;

    color: var(--ink);

    /* font-size: 11px; */
    font-weight: 800;
}

.file-subtitle {
    display: block;

    margin-top: 3px;

    color: var(--muted);

    font-size: 13px;
}

.separator {
    margin: 0 3px;

    color: var(--muted-light);
}

.download-actions {
    display: flex;
    flex-wrap: wrap;

    justify-content: flex-end;

    gap: 8px;

    flex-shrink: 0;
}

.download-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    gap: 7px;

    min-height: 39px;

    padding: 0 12px;

    border: 1px solid var(--primary);
    border-radius: 9px;

    background: var(--primary);

    color: #ffffff;

    font-size: 12px;
    font-weight: 800;

    text-decoration: none;

    /* box-shadow:
        0 5px 14px rgba(0, 102, 76, 0.13); */

    transition:
        transform 0.2s ease,
        background 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.download-button:hover {
    transform: translateY(-1px);

    background: var(--primary-dark);
    border-color: var(--primary-dark);

    color: #ffffff;
/* 
    box-shadow:
        0 8px 18px rgba(0, 102, 76, 0.17); */
}

.download-button:active {
    transform: translateY(0);
}

.download-button-secondary {
    border-color: var(--border);

    background: rgba(0, 102, 76, 0.2);

    color: var(--ink);

    box-shadow: none;
}

.download-button-secondary:hover {
    border-color: rgba(0, 102, 76, 0.2);

    background: var(--primary-soft);

    color: var(--primary-dark);

    box-shadow: none;
}

.download-button-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 20px;
    height: 20px;

    border-radius: 6px;

    background: rgba(255, 255, 255, 0.14);

    font-size: 12px;
    font-weight: 900;
}

.download-button-secondary .download-button-icon {
    background: var(--primary-soft);
}

.download-button-arrow {
    margin-left: 1px;

    font-size: 12px;

    opacity: 0.7;

    transition: transform 0.2s ease;
}

.download-button:hover .download-button-arrow {
    transform: translateX(3px);
}

/* ================================================================
   COLONNES
================================================================ */

.columns-section {
    margin-top: 22px;
    padding-top: 20px;

    border-top: 1px solid var(--border);
}

.columns-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;
}

.columns-title-group h3 {
    margin: 0;

    color: var(--ink);
/* 
    font-size: 12px; */
    font-weight: 800;
}

.columns-count {
    display: inline-flex;
    align-items: center;

    gap: 4px;

    padding: 5px 9px;

    border: 1px solid var(--border);
    border-radius: 7px;

    background: var(--surface-soft);

    color: var(--ink);

    font-size: 10px;
    font-weight: 800;
}

.columns-count span {
    color: var(--muted);

    font-weight: 600;
}

.columns-list {
    display: flex;
    flex-wrap: wrap;

    gap: 7px;

    margin-top: 13px;
}

.column-tag {
    display: inline-flex;
    align-items: center;

    gap: 6px;

    min-height: 27px;

    padding: 0 9px;

    border: 1px solid var(--border);
    border-radius: 7px;

    background: var(--surface-soft);

    color: var(--ink);

  

    font-size: 12px;

    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;
}

.column-tag:hover {
    transform: translateY(-1px);

    border-color: rgba(0, 102, 76, 0.18);

    background: var(--primary-soft);

    color: var(--primary-dark);
}

.column-dot {
    width: 4px;
    height: 4px;

    border-radius: 50%;

    background: var(--primary);

    opacity: 0.55;
}

/* ================================================================
   PRIVACY
================================================================ */

.privacy-card {
    position: relative;

    display: flex;
    align-items: center;

    gap: 15px;

    padding: 17px 19px;

    border: 1px solid rgba(176, 138, 69, 0.13);
    border-radius: 16px;

    background:
        linear-gradient(
            135deg,
            #fbf7ef,
            #fffdf9
        );
/* 
    box-shadow:
        0 7px 20px rgba(75, 65, 40, 0.035); */

    animation: fadeUp 0.6s ease 0.1s both;
}

.privacy-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    flex: 0 0 39px;

    width: 39px;
    height: 39px;

    border: 1px solid rgba(176, 138, 69, 0.17);
    border-radius: 11px;

    background: rgba(176, 138, 69, 0.1);

    color: var(--gold);

    font-size: 14px;
}

.privacy-icon svg {
    width: 20px;
    height: 20px;
}

.privacy-content {
    flex: 1;
    min-width: 0;
}

.privacy-label {
    display: block;

    margin-bottom: 3px;

    color: var(--gold);

    font-size: 12px;
    font-weight: 850;

    letter-spacing: 0.14em;
}

.privacy-content h3 {
    margin: 0;

    color: var(--ink);
/* 
    font-size: 12px; */
    font-weight: 800;
}

.privacy-content p {
    margin: 5px 0 0;

    color: var(--muted);

    font-size: 13px;
    line-height: 1.55;
}

.privacy-content strong {
    color: #876a32;

    font-weight: 850;
}

.privacy-shield {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;

    flex: 0 0 58px;

    width: 58px;
    height: 58px;

    border: 1px solid rgba(176, 138, 69, 0.2);
    border-radius: 50%;

    background: rgba(255, 255, 255, 0.7);

    color: var(--gold);
}

.privacy-shield span {
    font-size: 13px;
    font-weight: 900;
}

.privacy-shield small {
    margin-top: 1px;

    font-size: 8px;
    font-weight: 750;
}

/* ================================================================
   NOTE
================================================================ */

.page-source {
    margin: -2px 0 0;

    color: var(--muted);

    /* font-size: 10px; */
    line-height: 1.5;
}

/* ================================================================
   ANIMATIONS
================================================================ */

@keyframes introAppear {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(9px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ================================================================
   RESPONSIVE — 1000px
================================================================ */

@media (max-width: 1000px) {
    .intro-main {
        align-items: flex-start;
        flex-direction: column;

        gap: 22px;
    }

    .intro-filters {
        width: 100%;
        min-width: 0;

        padding-top: 18px;
        padding-left: 0;

        border-top: 1px solid var(--border);
        border-left: 0;
    }

    .filters-grid {
        max-width: 650px;
    }
}

/* ================================================================
   RESPONSIVE — 850px
================================================================ */

@media (max-width: 850px) {
    .exports-page {
        padding-right: 7px;
        padding-left: 7px;
    }

    .export-card {
        padding: 23px 21px;
    }

    .download-area {
        align-items: flex-start;
        flex-direction: column;
    }

    .download-actions {
        width: 100%;
        justify-content: flex-start;
    }
}

/* ================================================================
   RESPONSIVE — 700px
================================================================ */

@media (max-width: 700px) {
    .exports-page {
        padding-right: 4px;
        padding-left: 4px;
    }

    .exports-intro {
        padding: 22px 20px;

        border-radius: 17px;
    }

    .intro-content {
        align-items: flex-start;
    }

    .intro-icon {
        flex-basis: 44px;

        width: 44px;
        height: 44px;

        border-radius: 12px;
    }

    .intro-icon svg {
        width: 22px;
        height: 22px;
    }

    .intro-text h1 {
        font-size: 19px;
    }

    .intro-description {
        font-size: 10.5px;
    }

    .filters-grid {
        grid-template-columns: 1fr;

        max-width: none;
    }

    .export-card {
        border-radius: 17px;
    }

    .export-card-header {
        align-items: flex-start;
        flex-direction: column;

        gap: 13px;
    }

    .period-badge {
        width: fit-content;
    }

    .download-actions {
        flex-direction: column;
    }

    .download-button {
        width: 100%;
    }

    .privacy-card {
        align-items: flex-start;
    }
}

/* ================================================================
   RESPONSIVE — 480px
================================================================ */

@media (max-width: 480px) {
    .exports-page {
        padding: 0 2px 40px;
    }

    .exports-intro {
        padding: 19px 15px;

        border-radius: 15px;
    }

    .intro-content {
        gap: 11px;
    }

    .intro-icon {
        flex-basis: 40px;

        width: 40px;
        height: 40px;
    }

    .intro-text h1 {
        font-size: 17px;
    }

    .intro-description {
        font-size: 10px;
    }

    .intro-period {
        font-size: 9px;
    }

    .export-card {
        padding: 17px 15px;

        border-radius: 15px;
    }

    .card-heading h2 {
        font-size: 15px;
    }

    .heading-description {
        font-size: 10.5px;
    }

    .period-badge {
        width: 100%;

        justify-content: center;

        white-space: normal;

        text-align: center;
    }

    .export-description {
        padding: 12px;
    }

    .download-area {
        padding: 12px;
    }

    .download-info {
        align-items: flex-start;
    }

    .file-icon {
        flex-basis: 39px;

        width: 39px;
        height: 39px;
    }

    .columns-header {
        align-items: flex-start;
        flex-direction: column;

        gap: 8px;
    }

    .privacy-card {
        padding: 14px;
    }

    .privacy-shield {
        display: none;
    }
}

/* ================================================================
   ACCESSIBILITÉ
================================================================ */

@media (prefers-reduced-motion: reduce) {
    .exports-page *,
    .exports-page *::before,
    .exports-page *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>