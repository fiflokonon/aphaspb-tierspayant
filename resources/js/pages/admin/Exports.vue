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

    <ConsoleHeader
        eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
        title="Exports CSV"
        class="exports-header"
    >
        <template #filters>
            <FilterSelect
                v-model="period"
                :options="periods"
                label="Période"
                aria-label="Filtrer par période"
            />
            <FilterSelect
                v-model="city"
                :options="cityOptions"
                label="Ville"
                aria-label="Filtrer par ville"
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
        <section class="export-card">
            <div class="card-accent"></div>

            <div class="export-card-header">
                <div class="card-heading">
                    <span class="section-label"> FICHIER CSV </span>

                    <h2>Statistiques agrégées par assureur</h2>

                    <p class="heading-description">
                        Un export synthétique des indicateurs du réseau, préparé
                        pour l'analyse et le suivi des performances.
                    </p>
                </div>

                <div class="period-badge">
                    <span class="period-dot"></span>

                    <span>
                        {{ periodLabel
                        }}{{ city ? ` · ${city}` : ' · toutes les villes'
                        }}{{
                            insurerName
                                ? ` · ${insurerName}`
                                : ' · tous les assureurs'
                        }}
                    </span>
                </div>
            </div>

            <div class="export-description">
                <div class="description-icon">i</div>

                <div class="description-content">
                    <span class="description-title"> Format d'export </span>

                    <p>
                        <strong>{{ periodLabel }}</strong
                        >{{ city ? ` · ${city}` : ' · toutes les villes'
                        }}{{
                            insurerName
                                ? ` · ${insurerName}`
                                : ' · tous les assureurs'
                        }},
                        {{
                            insurerName
                                ? 'une seule ligne.'
                                : 'une ligne par assureur.'
                        }}
                        Le classeur Excel porte des cellules numériques, donc
                        une colonne s'additionne sans conversion. Le CSV reste
                        là pour un réimport : séparateur point-virgule,
                        décimales à la virgule, UTF-8 avec BOM pour les accents.
                        Le PDF, lui, ne se recalcule pas : c'est le rapport mis
                        en page, à joindre tel quel à une note ou à un courrier.
                    </p>
                </div>
            </div>

            <div class="download-area">
                <div class="download-info">
                    <div class="file-icon">
                        <span> CSV </span>
                    </div>

                    <div class="file-details">
                        <span class="file-title"> Export des indicateurs </span>

                        <span class="file-subtitle">
                            Format compatible Excel
                            <span class="separator">·</span>
                            UTF-8 avec BOM
                        </span>
                    </div>
                </div>

                <div class="download-actions">
                    <a :href="xlsxHref" class="download-button">
                        <span class="download-button-icon"> ↓ </span>

                        <span class="download-button-text">
                            Télécharger le classeur Excel
                        </span>

                        <span class="download-button-arrow"> → </span>
                    </a>

                    <a
                        :href="pdfHref"
                        class="download-button download-button-secondary"
                    >
                        <span class="download-button-icon"> ↓ </span>

                        <span class="download-button-text">
                            Télécharger le rapport PDF
                        </span>
                    </a>

                    <a
                        :href="csvHref"
                        class="download-button download-button-secondary"
                    >
                        <span class="download-button-icon"> ↓ </span>

                        <span class="download-button-text">
                            Télécharger le CSV
                        </span>
                    </a>
                </div>
            </div>

            <div class="columns-section">
                <div class="columns-header">
                    <div class="columns-title-group">
                        <span class="section-label">
                            STRUCTURE DU FICHIER
                        </span>

                        <h3>Colonnes du fichier</h3>
                    </div>

                    <span class="columns-count">
                        {{ columns.length }}

                        <span> colonnes </span>
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

        <section class="privacy-card">
            <div class="privacy-icon">✓</div>

            <div class="privacy-content">
                <span class="privacy-label"> PROTECTION DES DONNÉES </span>

                <h3>Ce que le fichier ne contient jamais</h3>

                <p>
                    Aucun nom d'officine, aucun montant individuel, aucune note
                    privée. Un assureur déclaré par moins de
                    <strong>5 officines</strong> apparaît avec la mention «
                    données insuffisantes » et aucun chiffre — la ligne est
                    conservée exprès, car une ligne absente se lirait comme une
                    absence de données et non comme une rétention volontaire.
                </p>
            </div>

            <div class="privacy-shield">
                <span> 5+ </span>

                <small> officines </small>
            </div>
        </section>

        <p class="page-source">
            Les données exportées restent agrégées afin de préserver l'anonymat
            des officines participantes.
        </p>
    </div>
</template>

<style scoped>
.download-actions {
    display: flex;

    flex-wrap: wrap;

    gap: 9px;

    flex-shrink: 0;
}

.download-button-secondary {
    min-width: 0;

    border: 1px solid var(--border);

    background: #ffffff;

    color: var(--ink);

    box-shadow: none;
}

.exports-page {
    /* La palette vient de :root — voir resources/css/app.css. */

    position: relative;

    width: 100%;

    max-width: none;

    margin-top: 22px;

    padding: 0 10px 60px;
}

.exports-header {
    position: relative;

    z-index: 5;
}

:deep(.exports-header) {
    position: relative;
}

.export-card {
    position: relative;

    overflow: hidden;

    width: 100%;

    padding: 28px;

    border-radius: var(--radius-card);

    background: #ffffff;

    box-shadow: var(--surface-shadow);

    animation: fadeUp 0.6s ease 0.05s both;
}

.card-accent {
    position: absolute;

    left: 0;

    top: 0;

    width: 100%;

    height: 3px;

    background: var(--primary);
}

.export-card-header {
    display: flex;

    align-items: flex-start;

    justify-content: space-between;

    gap: 30px;

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

    font-size: 9.5px;

    font-weight: 850;

    letter-spacing: 0.14em;
}

.export-card-header h2 {
    margin: 0;

    color: var(--ink);

    font-size: 17px;

    font-weight: 800;

    letter-spacing: -0.02em;
}

.heading-description {
    max-width: 700px;

    margin-top: 5px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-size: 12.5px;

    line-height: 1.5;
}

.period-badge {
    display: inline-flex;

    align-items: center;

    gap: 8px;

    flex-shrink: 0;

    min-height: 34px;

    padding: 0 12px;

    border: 1px solid color-mix(in srgb, var(--officine) 12%, transparent);

    border-radius: 9px;

    background: var(--cream-state);

    color: var(--primary-dark);

    font-size: 12.5px;

    font-weight: 750;

    white-space: nowrap;
}

.period-dot {
    width: 6px;

    height: 6px;

    border-radius: 50%;

    background: var(--primary);
}

.export-description {
    display: flex;

    align-items: flex-start;

    gap: 12px;

    margin-top: 20px;

    padding: 14px 15px;

    border-radius: var(--radius-card);

    background: color-mix(in srgb, var(--officine) 3.5%, transparent);

    box-shadow: var(--surface-shadow);
}

.description-icon {
    width: 21px;

    height: 21px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

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

    font-size: 12.5px;

    font-weight: 750;
}

.export-description p {
    margin: 0;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;

    line-height: 1.55;
}

.export-description strong {
    color: var(--ink);

    font-weight: 800;
}

.download-area {
    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 25px;

    margin-top: 20px;

    padding: 15px;

    border-radius: var(--radius-card);

    background: var(--cream-state);

    box-shadow: var(--surface-shadow);

    transition: box-shadow 0.2s ease;
}

.download-area:hover {
    box-shadow: var(--surface-shadow-raised);
}

.download-info {
    display: flex;

    align-items: center;

    gap: 12px;

    min-width: 0;
}

.file-icon {
    width: 44px;

    height: 44px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid color-mix(in srgb, var(--officine) 9%, transparent);

    border-radius: 11px;

    background: var(--primary-soft);

    color: var(--primary-dark);
}

.file-icon span {
    font-size: 12.5px;

    font-weight: 900;

    letter-spacing: 0.05em;
}

.file-details {
    min-width: 0;
}

.file-title {
    display: block;

    color: var(--ink);

    font-size: 11px;

    font-weight: 800;
}

.file-subtitle {
    display: block;

    margin-top: 3px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-size: 12.5px;
}

.separator {
    margin: 0 3px;
}

.download-button {
    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 8px;

    min-width: 190px;

    height: 42px;

    flex-shrink: 0;

    padding: 0 14px;

    border: 1px solid var(--primary);

    border-radius: 10px;

    background: var(--primary);

    color: #ffffff;

    font-size: 10.5px;

    font-weight: 800;

    text-decoration: none;

    box-shadow: 0 6px 15px color-mix(in srgb, var(--officine) 15%, transparent);

    transition:
        transform 0.2s ease,
        background 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.download-button:hover {
    transform: translateY(-2px);

    background: var(--primary-dark);

    border-color: var(--primary-dark);

    color: #ffffff;

    box-shadow: 0 10px 22px color-mix(in srgb, var(--officine) 20%, transparent);
}

.download-button:active {
    transform: translateY(0);
}

.download-button-icon {
    width: 21px;

    height: 21px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 6px;

    background: rgba(255, 255, 255, 0.14);

    font-size: 13px;

    font-weight: 900;
}

.download-button-text {
    white-space: nowrap;
}

.download-button-arrow {
    margin-left: 2px;

    font-size: 13px;

    opacity: 0.7;

    transition: transform 0.2s ease;
}

.download-button:hover .download-button-arrow {
    transform: translateX(3px);
}

.columns-section {
    margin-top: 24px;

    padding-top: 21px;

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

    font-size: 12.5px;

    font-weight: 800;
}

.columns-count {
    display: inline-flex;

    align-items: center;

    gap: 4px;

    padding: 5px 9px;

    border: 1px solid var(--border);

    border-radius: 7px;

    background: var(--cream-state);

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;

    font-weight: 750;
}

.columns-count span {
    font-weight: 600;

    color: color-mix(in srgb, var(--ink) 38%, transparent);
}

.columns-list {
    display: flex;

    flex-wrap: wrap;

    gap: 7px;

    margin-top: 14px;
}

.column-tag {
    display: inline-flex;

    align-items: center;

    gap: 6px;

    min-height: 28px;

    padding: 0 9px;

    border: 1px solid var(--border);

    border-radius: 7px;

    background: var(--cream-state);

    /*
      65 % et non les 55 % du gris secondaire : ce texte se pose sur --cream-state,
      où 55 % tombe à 3,81:1. Les 55 % restent la valeur du reste de l'application
      et sont eux aussi sous AA — leur relèvement global est une décision de charte
      en attente, qui touche tous les écrans. En attendant, on ne dégrade pas un
      texte qui était conforme.
    */
    color: color-mix(in srgb, var(--ink) 65%, transparent);

    font-family:
        ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

    font-size: 12.5px;

    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;
}

.column-dot {
    width: 4px;

    height: 4px;

    border-radius: 50%;

    background: var(--primary);

    opacity: 0.55;
}

.column-tag:hover {
    transform: translateY(-1px);

    border-color: color-mix(in srgb, var(--officine) 20%, transparent);

    background: var(--primary-soft);

    color: var(--primary-dark);
}

.column-tag:hover .column-dot {
    opacity: 1;
}

.privacy-card {
    position: relative;

    display: flex;

    align-items: center;

    gap: 15px;

    width: 100%;

    margin-top: 16px;

    padding: 18px 20px;

    border-radius: var(--radius-card);

    background: var(--gold-soft);

    box-shadow: var(--surface-shadow);

    animation: fadeUp 0.65s ease 0.1s both;
}

.privacy-icon {
    width: 39px;

    height: 39px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid color-mix(in srgb, var(--gold-mid) 15%, transparent);

    border-radius: 11px;

    background: color-mix(in srgb, var(--gold-mid) 14%, transparent);

    color: var(--gold-ink);

    font-size: 15px;

    font-weight: 900;
}

.privacy-content {
    flex: 1;

    min-width: 0;
}

.privacy-label {
    display: block;

    margin-bottom: 3px;

    color: var(--gold-ink);

    font-size: 9.5px;

    font-weight: 850;

    letter-spacing: 0.14em;
}

.privacy-content h3 {
    margin: 0;

    color: var(--ink);

    font-size: 12.5px;

    font-weight: 800;
}

.privacy-content p {
    margin-top: 5px;

    /*
      65 % et non les 55 % du gris secondaire : ce texte se pose sur
      --gold-soft, le fond de .privacy-card, où 55 % tombe à 3,69:1. À 65 % il
      donne 5,01:1. Les 55 % restent la valeur du reste de l'application et
      sont eux aussi sous AA — leur relèvement global est une décision de
      charte en attente, qui touche tous les écrans. En attendant, on ne
      dégrade pas un texte qui était conforme.
    */
    color: color-mix(in srgb, var(--ink) 65%, transparent);

    font-size: 12.5px;

    line-height: 1.55;
}

.privacy-content strong {
    color: var(--gold-ink);

    font-weight: 850;
}

.privacy-shield {
    width: 58px;

    height: 58px;

    flex-shrink: 0;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    border: 1px solid color-mix(in srgb, var(--gold-mid) 22%, transparent);

    border-radius: 50%;

    background: rgba(255, 255, 255, 0.62);

    color: var(--gold-ink);
}

.privacy-shield span {
    font-size: 13px;

    font-weight: 900;
}

.privacy-shield small {
    margin-top: 1px;

    font-size: 12.5px;

    font-weight: 750;
}

/*
  Une ligne de métadonnée, plus un panneau : la phrase mérite d'être lisible,
  pas d'occuper une bande avec une icône « i ».
*/
.page-source {
    margin-top: 16px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);

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

@media (max-width: 900px) {
    .exports-page {
        padding-left: 6px;

        padding-right: 6px;
    }

    .export-card {
        padding: 22px;
    }

    .download-area {
        gap: 15px;
    }

    .download-button {
        min-width: 175px;
    }
}

@media (max-width: 760px) {
    .exports-page {
        margin-top: 15px;

        padding: 0 4px 50px;
    }

    /* CARD */

    .export-card {
        padding: 19px;

        border-radius: 15px;
    }

    .export-card-header {
        align-items: flex-start;

        flex-direction: column;

        gap: 14px;
    }

    .period-badge {
        width: fit-content;
    }

    /* DOWNLOAD */

    .download-area {
        align-items: stretch;

        flex-direction: column;
    }

    .download-button {
        width: 100%;
    }

    /* PRIVACY */

    .privacy-card {
        align-items: flex-start;
    }
}

@media (max-width: 520px) {
    .export-card {
        padding: 16px;
    }

    .period-badge {
        width: 100%;

        justify-content: center;
    }

    .export-description {
        padding: 11px;
    }

    .download-info {
        align-items: flex-start;
    }

    .file-icon {
        width: 40px;

        height: 40px;
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
