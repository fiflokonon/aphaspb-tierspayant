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
        <!-- INTRO -->
        <section class="exports-intro">
            <div class="intro-main">
                <div class="intro-icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <path
                            d="M12 3v12m0 0 4-4m-4 4-4-4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <path
                            d="M5 20h14"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>

                <div class="intro-copy">
                    <span class="intro-eyebrow">
                        EXPORT & DONNÉES
                    </span>

                    <h1>{{ pharmacyName }}</h1>

                    <p>
                        Exportez vos déclarations pour
                        <strong>{{ periodLabel }}</strong>
                        {{
                            insurer
                                ? ` · ${insurers.find((one) => one.id === insurer)?.name}`
                                : ' · tous vos assureurs'
                        }}.
                    </p>
                </div>
            </div>

            <div class="privacy-badge">
                <span class="privacy-icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <rect
                            x="5"
                            y="10"
                            width="14"
                            height="10"
                            rx="2"
                        />
                        <path
                            d="M8 10V7a4 4 0 0 1 8 0v3"
                            stroke-linecap="round"
                        />
                    </svg>
                </span>

                <span>
                    Données privées
                </span>
            </div>
        </section>

        <!-- FORMATS -->
        <section class="formats-section">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">
                        FORMATS DISPONIBLES
                    </span>

                    <h2>
                        Choisissez votre format
                    </h2>

                    <p>
                        Téléchargez vos données dans le format adapté à
                        votre besoin.
                    </p>
                </div>
            </div>

            <div class="formats">
                <article
                    v-for="format in FORMATS"
                    :key="format.key"
                    class="format-card"
                    :class="{
                        'format-card-primary': format.primary,
                    }"
                >
                    <div class="format-left">
                        <div
                            class="format-badge"
                            :class="{
                                'format-badge-primary': format.primary,
                            }"
                        >
                            <span>{{ format.badge }}</span>
                        </div>

                        <div class="format-copy">
                            <div class="format-title-row">
                                <h3>{{ format.title }}</h3>

                                <span
                                    v-if="format.primary"
                                    class="recommended-badge"
                                >
                                    Recommandé
                                </span>
                            </div>

                            <p>{{ format.lede }}</p>
                        </div>
                    </div>

                    <a
                        :href="hrefOf(format.key)"
                        class="format-action"
                        :class="{
                            'format-action-primary': format.primary,
                        }"
                    >
                        <span>Télécharger</span>

                        <span class="format-arrow">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path
                                    d="M12 4v11"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="m8 11 4 4 4-4"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                                <path
                                    d="M5 20h14"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </span>
                    </a>
                </article>
            </div>
        </section>

        <!-- STRUCTURE DES DONNÉES -->
<section class="columns-section">
    <div class="columns-header">
        <div class="columns-heading">
            <div class="columns-icon">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                >
                    <rect
                        x="3.5"
                        y="4"
                        width="17"
                        height="16"
                        rx="2"
                    />
                    <path d="M3.5 9h17" />
                    <path d="M9 9v11" />
                    <path d="M15 9v11" />
                </svg>
            </div>

            <div>
                <span class="section-kicker">
                    STRUCTURE DES DONNÉES
                </span>

                <h2>Contenu de vos exports</h2>
            </div>
        </div>

        <div class="columns-count">
            <strong>{{ columns.length }}</strong>
            <span>colonnes</span>
        </div>
    </div>

    <p class="columns-lede">
        Les mêmes informations sont disponibles dans les exports CSV et
        Excel. Chaque ligne correspond à un mois et à un assureur.
    </p>

    <div class="columns-list">
        <span
            v-for="(column, index) in columns"
            :key="column"
            class="column-chip"
        >
            <span class="column-index">
                {{ String(index + 1).padStart(2, '0') }}
            </span>

            {{ column }}
        </span>
    </div>
</section>

        <!-- PRIVACY NOTE -->
        <section class="privacy-note">
            <div class="privacy-note-icon">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                >
                    <circle cx="12" cy="12" r="9" />
                    <path
                        d="M12 10v6"
                        stroke-linecap="round"
                    />
                    <circle
                        cx="12"
                        cy="7"
                        r=".7"
                        fill="currentColor"
                        stroke="none"
                    />
                </svg>
            </div>

            <div>
                <strong>Vos données restent privées</strong>

                <p>
                    Les exports disponibles ici concernent uniquement les
                    déclarations de votre pharmacie et ne donnent accès
                    qu’à vos propres données.
                </p>
            </div>
        </section>
    </div>
</template>

<style scoped>
/* =========================================================
   STRUCTURE DES DONNÉES
========================================================= */

.columns-section {
    margin-top: 30px;
    padding: 22px 22px 20px;

    border: 1px solid
        color-mix(in srgb, var(--border) 78%, transparent);

    border-radius: 16px;

    background: var(--card);

    /* box-shadow:
        0 7px 24px
            color-mix(in srgb, var(--ink) 4%, transparent); */
}

.columns-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;
}

.columns-heading {
    display: flex;
    align-items: center;
    gap: 12px;
}

.columns-icon {
    display: grid;
    place-items: center;

    width: 38px;
    height: 38px;

    flex: 0 0 38px;

    border-radius: 11px;

    background: var(--primary-soft);

    color: var(--primary);
}

.columns-icon svg {
    width: 18px;
    height: 18px;
}

.columns-heading h2 {
    margin: 4px 0 0;

    font-size: 15px;
    line-height: 1.25;
    font-weight: 700;

    letter-spacing: -0.01em;
}

.columns-count {
    display: flex;
    align-items: baseline;
    gap: 5px;

    padding: 7px 11px;

    border: 1px solid
        color-mix(in srgb, var(--border) 80%, transparent);

    border-radius: 9px;

    background: var(--cream-state);
}

.columns-count strong {
    font-size: 14px;
    font-weight: 750;
}

.columns-count span {
    font-size: 9.5px;
    font-weight: 600;

    color: color-mix(
        in srgb,
        var(--ink) 50%,
        transparent
    );
}

.columns-lede {
    margin: 12px 0 0;

    max-width: 76ch;

    font-size: 11px;
    line-height: 1.55;

    color: color-mix(
        in srgb,
        var(--ink) 50%,
        transparent
    );
}

.columns-list {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;

    margin-top: 16px;
}

.column-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    min-height: 29px;

    padding: 4px 10px 4px 5px;

    border: 1px solid
        color-mix(in srgb, var(--border) 75%, transparent);

    border-radius: 8px;

    background: color-mix(
        in srgb,
        var(--cream-state) 45%,
        var(--card)
    );

    font-family: var(
        --font-mono,
        ui-monospace,
        monospace
    );

    font-size: 9.5px;

    color: color-mix(
        in srgb,
        var(--ink) 76%,
        transparent
    );

    transition:
        background 0.15s ease,
        border-color 0.15s ease,
        transform 0.15s ease;
}

.column-chip:hover {
    border-color: color-mix(
        in srgb,
        var(--primary) 25%,
        var(--border)
    );

    background: var(--primary-soft);

    transform: translateY(-1px);
}

.column-index {
    display: grid;
    place-items: center;

    width: 20px;
    height: 20px;

    border-radius: 5px;

    background: var(--card);

    font-size: 8px;
    font-weight: 700;

    color: color-mix(
        in srgb,
        var(--ink) 42%,
        transparent
    );
}

@media (max-width: 680px) {
    .columns-section {
        padding: 18px;
    }

    .columns-header {
        align-items: flex-start;
    }
}

@media (max-width: 460px) {
    .columns-header {
        flex-direction: column;
        gap: 12px;
    }

    .columns-count {
        align-self: flex-start;
    }
}
/* =========================================================
   PAGE
========================================================= */

.exports-page {
    width: 100%;
    padding: 26px 0 48px;
}

/* =========================================================
   INTRO
========================================================= */

.exports-intro {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;

    padding: 22px 24px;

    border: 1px solid color-mix(
        in srgb,
        var(--border) 75%,
        transparent
    );

    border-radius: 18px;

    background:
        linear-gradient(
            135deg,
            color-mix(in srgb, var(--primary-soft) 42%, var(--card)),
            var(--card) 72%
        );

    /* box-shadow:
        0 10px 30px
            color-mix(in srgb, var(--ink) 4%, transparent); */
}

.intro-main {
    display: flex;
    align-items: center;
    gap: 15px;

    min-width: 0;
}

.intro-icon {
    display: grid;
    place-items: center;

    width: 46px;
    height: 46px;

    flex: 0 0 46px;

    border: 1px solid
        color-mix(in srgb, var(--primary) 16%, var(--border));

    border-radius: 13px;

    background: var(--card);

    color: var(--primary);
}

.intro-icon svg {
    width: 21px;
    height: 21px;
}

.intro-copy {
    min-width: 0;
}

.intro-eyebrow,
.section-kicker {
    display: block;

    font-family: var(
        --font-mono,
        ui-monospace,
        monospace
    );

    font-size: 9px;
    font-weight: 700;

    letter-spacing: 0.13em;

    color: color-mix(
        in srgb,
        var(--primary) 78%,
        var(--ink)
    );
}

.intro-copy h1 {
    margin: 5px 0 0;

    font-size: 21px;
    line-height: 1.25;
    font-weight: 700;

    letter-spacing: -0.02em;
}

.intro-copy p {
    margin: 5px 0 0;

    font-size: 12px;
    line-height: 1.55;

    color: color-mix(
        in srgb,
        var(--ink) 55%,
        transparent
    );
}

.intro-copy strong {
    color: var(--ink);
    font-weight: 650;
}

.privacy-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    flex: 0 0 auto;

    padding: 8px 12px;

    border: 1px solid
        color-mix(in srgb, var(--border) 80%, transparent);

    border-radius: 999px;

    background: color-mix(
        in srgb,
        var(--card) 85%,
        transparent
    );

    font-size: 10.5px;
    font-weight: 650;

    color: color-mix(
        in srgb,
        var(--ink) 62%,
        transparent
    );
}

.privacy-icon {
    display: grid;
    place-items: center;

    width: 17px;
    height: 17px;

    border-radius: 50%;

    background: var(--primary-soft);

    color: var(--primary);
}

.privacy-icon svg {
    width: 11px;
    height: 11px;
}

/* =========================================================
   SECTION HEADINGS
========================================================= */

.formats-section {
    margin-top: 30px;
}

.section-heading h2,
.columns-heading h2 {
    margin: 5px 0 0;

    font-size: 16px;
    line-height: 1.25;
    font-weight: 700;

    letter-spacing: -0.01em;
}

.section-heading p {
    margin: 5px 0 0;

    font-size: 11.5px;
    line-height: 1.5;

    color: color-mix(
        in srgb,
        var(--ink) 52%,
        transparent
    );
}

/* =========================================================
   FORMAT CARDS
========================================================= */

.formats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));

    gap: 14px;

    margin-top: 15px;
}

.format-card {
    position: relative;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 18px;

    min-height: 126px;

    padding: 18px;

    border: 1px solid
        color-mix(in srgb, var(--border) 82%, transparent);

    border-radius: 16px;

    background: var(--card);

    /* box-shadow:
        0 7px 22px
            color-mix(in srgb, var(--ink) 4%, transparent); */

    transition:
        transform 0.18s ease,
        border-color 0.18s ease,
        box-shadow 0.18s ease;
}

.format-card:hover {
    transform: translateY(-2px);

    border-color: color-mix(
        in srgb,
        var(--primary) 24%,
        var(--border)
    );
/* 
    box-shadow:
        0 12px 28px
            color-mix(in srgb, var(--ink) 6%, transparent); */
}

.format-card-primary {
    border-color: color-mix(
        in srgb,
        var(--primary) 20%,
        var(--border)
    );

    background:
        linear-gradient(
            135deg,
            color-mix(
                in srgb,
                var(--primary-soft) 48%,
                var(--card)
            ),
            var(--card) 68%
        );
}

.format-left {
    display: flex;
    align-items: center;
    gap: 14px;

    min-width: 0;
}

.format-badge {
    display: grid;
    place-items: center;

    width: 48px;
    height: 48px;

    flex: 0 0 48px;

    border: 1px solid
        color-mix(in srgb, var(--border) 75%, transparent);

    border-radius: 13px;

    background: var(--cream-state);

    font-family: var(
        --font-mono,
        ui-monospace,
        monospace
    );

    font-size: 10px;
    font-weight: 800;

    color: color-mix(
        in srgb,
        var(--ink) 78%,
        transparent
    );
}

.format-badge-primary {
    border-color: color-mix(
        in srgb,
        var(--primary) 18%,
        transparent
    );

    background: var(--primary-soft);

    color: var(--primary-dark);
}

.format-copy {
    min-width: 0;
}

.format-title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
}

.format-copy h3 {
    margin: 0;

    font-size: 14px;
    line-height: 1.3;
    font-weight: 700;
}

.format-copy p {
    margin: 5px 0 0;

    max-width: 52ch;

    font-size: 11.5px;
    line-height: 1.55;

    color: color-mix(
        in srgb,
        var(--ink) 53%,
        transparent
    );
}

.recommended-badge {
    padding: 3px 7px;

    border-radius: 999px;

    background: var(--primary-soft);

    font-size: 8.5px;
    font-weight: 700;

    color: var(--primary-dark);
}

.format-action {
    display: inline-flex;
    align-items: center;
    gap: 9px;

    flex: 0 0 auto;

    padding: 9px 11px 9px 13px;

    border: 1px solid
        color-mix(in srgb, var(--border) 90%, transparent);

    border-radius: 10px;

    background: var(--card);

    font-size: 10.5px;
    font-weight: 700;

    color: var(--ink);

    text-decoration: none;

    transition:
        background 0.18s ease,
        border-color 0.18s ease,
        transform 0.18s ease;
}

.format-action:hover {
    border-color: color-mix(
        in srgb,
        var(--primary) 35%,
        var(--border)
    );

    color: var(--primary);

    transform: translateX(1px);
}

.format-action-primary {
    border-color: transparent;

    background: var(--primary);

    color: #fff;

    /* box-shadow:
        0 5px 14px
            color-mix(in srgb, var(--primary) 18%, transparent); */
}

.format-action-primary:hover {
    border-color: transparent;

    background: var(--primary-dark);

    color: #fff;

    transform: translateX(1px);
}

.format-arrow {
    display: grid;
    place-items: center;

    width: 22px;
    height: 22px;

    border-radius: 7px;

    background: color-mix(
        in srgb,
        currentColor 9%,
        transparent
    );
}

.format-arrow svg {
    width: 13px;
    height: 13px;
}

/* =========================================================
   COLUMNS
========================================================= */

.columns-section {
    margin-top: 30px;
    /* padding: 22px 0 0; */

    border-top: 1px solid
        color-mix(in srgb, var(--border) 80%, transparent);
}

.columns-top {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;
}

.columns-heading {
    display: flex;
    align-items: center;
    gap: 12px;
}

.columns-icon {
    display: grid;
    place-items: center;

    width: 38px;
    height: 38px;

    border-radius: 11px;

    background: var(--cream-state);

    color: color-mix(
        in srgb,
        var(--primary) 80%,
        var(--ink)
    );
}

.columns-icon svg {
    width: 18px;
    height: 18px;
}

.columns-count {
    display: flex;
    align-items: baseline;
    gap: 5px;

    padding: 7px 11px;

    border: 1px solid
        color-mix(in srgb, var(--border) 85%, transparent);

    border-radius: 9px;

    background: var(--card);
}

.columns-count strong {
    font-size: 15px;
    font-weight: 750;
}

.columns-count span {
    font-size: 9.5px;
    font-weight: 600;

    color: color-mix(
        in srgb,
        var(--ink) 52%,
        transparent
    );
}

.columns-lede {
    margin: 12px 0 0;

    max-width: 76ch;

    font-size: 11.5px;
    line-height: 1.6;

    color: color-mix(
        in srgb,
        var(--ink) 53%,
        transparent
    );
}

.columns-list {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;

    margin-top: 16px;
}

.column-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    min-height: 29px;

    padding: 4px 9px 4px 5px;

    border: 1px solid
        color-mix(in srgb, var(--border) 78%, transparent);

    border-radius: 8px;

    background: var(--card);

    font-family: var(
        --font-mono,
        ui-monospace,
        monospace
    );

    font-size: 9.5px;

    color: color-mix(
        in srgb,
        var(--ink) 78%,
        transparent
    );

    transition:
        border-color 0.15s ease,
        background 0.15s ease;
}

.column-chip:hover {
    border-color: color-mix(
        in srgb,
        var(--primary) 25%,
        var(--border)
    );

    background: color-mix(
        in srgb,
        var(--primary-soft) 38%,
        var(--card)
    );
}

.column-index {
    display: grid;
    place-items: center;

    min-width: 21px;
    height: 20px;

    border-radius: 5px;

    background: var(--cream-state);

    font-size: 8px;
    font-weight: 700;

    color: color-mix(
        in srgb,
        var(--ink) 45%,
        transparent
    );
}

/* =========================================================
   PRIVACY NOTE
========================================================= */

.privacy-note {
    display: flex;
    align-items: flex-start;
    gap: 11px;

    margin-top: 22px;

    padding: 13px 15px;

    border: 1px solid
        color-mix(in srgb, var(--border) 72%, transparent);

    border-radius: 12px;

    background: color-mix(
        in srgb,
        var(--cream-state) 48%,
        var(--card)
    );
}

.privacy-note-icon {
    display: grid;
    place-items: center;

    width: 26px;
    height: 26px;

    flex: 0 0 26px;

    border-radius: 8px;

    background: var(--card);

    color: var(--primary);
}

.privacy-note-icon svg {
    width: 15px;
    height: 15px;
}

.privacy-note strong {
    display: block;

    font-size: 10.5px;
    font-weight: 700;
}

.privacy-note p {
    margin: 3px 0 0;

    font-size: 10.5px;
    line-height: 1.5;

    color: color-mix(
        in srgb,
        var(--ink) 52%,
        transparent
    );
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {
    .formats {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .exports-page {
        padding-top: 18px;
    }

    .exports-intro {
        align-items: flex-start;
        flex-direction: column;
        padding: 18px;
    }

    .privacy-badge {
        margin-left: 0;
    }

    .format-card {
        align-items: flex-start;
        flex-direction: column;
    }

    .format-left {
        width: 100%;
    }

    .format-action {
        width: 100%;
        justify-content: center;
    }

    .columns-top {
        align-items: flex-start;
        flex-direction: column;
    }

    .columns-count {
        align-self: flex-start;
    }
}

@media (max-width: 460px) {
    .intro-main {
        align-items: flex-start;
    }

    .intro-icon {
        width: 40px;
        height: 40px;
        flex-basis: 40px;
    }

    .intro-copy h1 {
        font-size: 18px;
    }

    .format-left {
        align-items: flex-start;
    }

    .format-badge {
        width: 42px;
        height: 42px;
        flex-basis: 42px;
    }

    .privacy-note {
        padding: 12px;
    }
}
</style>
