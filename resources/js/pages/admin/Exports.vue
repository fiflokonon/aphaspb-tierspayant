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
}>();

const period = ref(props.period);
const city = ref(props.city);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

/** Each link carries the filters, so the file matches the screen above it. */
const hrefFor = (format: 'csv' | 'xlsx') => {
    const query = new URLSearchParams({ period: period.value, format });

    if (city.value) {
        query.set('city', city.value);
    }

    return `${props.downloadUrl}?${query.toString()}`;
};

const csvHref = computed(() => hrefFor('csv'));
const xlsxHref = computed(() => hrefFor('xlsx'));

function reload() {
    router.get(
        '/admin/csv-exports',
        { period: period.value, city: city.value },
        {
            only: ['period', 'periodLabel', 'city'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch([period, city], reload);
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
                aria-label="Filtrer par période"
            />
            <FilterSelect
                v-model="city"
                :options="cityOptions"
                aria-label="Filtrer par ville"
            />
        </template>
    </ConsoleHeader>

    <div class="exports-page">
        <section class="exports-intro">
            <div class="intro-main">
                <div class="intro-icon">
                    <span class="download-symbol">↓</span>
                </div>

                <div class="intro-copy">
                    <span class="intro-eyebrow"> EXPORT DES DONNÉES </span>

                    <h1>Statistiques agrégées</h1>

                    <p>
                        Exportez les indicateurs du réseau par assureur dans un
                        format optimisé pour Excel et les notes de plaidoyer.
                    </p>
                </div>
            </div>

            <div class="privacy-badge">
                <span class="privacy-dot"></span>

                <span> Données anonymisées </span>
            </div>
        </section>

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
                        }}{{ city ? ` · ${city}` : ' · toutes les villes' }}
                    </span>
                </div>
            </div>

            <div class="export-description">
                <div class="description-icon">i</div>

                <div class="description-content">
                    <span class="description-title"> Format d'export </span>

                    <p>
                        <strong>{{ periodLabel }}</strong
                        >{{ city ? ` · ${city}` : ' · toutes les villes' }}, une
                        ligne par assureur. Le classeur Excel porte des cellules
                        numériques, donc une colonne s'additionne sans
                        conversion. Le CSV reste là pour un réimport :
                        séparateur point-virgule, décimales à la virgule, UTF-8
                        avec BOM pour les accents.
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

        <div class="export-footnote">
            <span class="footnote-icon"> i </span>

            <span>
                Les données exportées restent agrégées afin de préserver
                l'anonymat des officines participantes.
            </span>
        </div>
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

    border: 1px solid var(--apha-border);

    background: #ffffff;

    color: var(--apha-ink);

    box-shadow: none;
}

.exports-page {
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

.exports-intro {
    position: relative;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 30px;

    width: 100%;

    margin-bottom: 20px;

    padding: 24px 28px;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: linear-gradient(110deg, #ffffff 0%, #f8fcfb 100%);

    /* box-shadow:
        0 8px 30px
        rgba(35, 70, 68, .035); */

    overflow: hidden;

    animation: fadeUp 0.5s ease both;
}

.exports-intro::after {
    content: '';

    position: absolute;

    width: 230px;

    height: 230px;

    right: -90px;

    top: -120px;

    border-radius: 50%;

    background: radial-gradient(
        circle,
        rgba(0, 143, 131, 0.1),
        transparent 68%
    );

    pointer-events: none;
}

.intro-main {
    position: relative;

    z-index: 1;

    display: flex;

    align-items: center;

    gap: 16px;
}

.intro-icon {
    width: 50px;

    height: 50px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 14px;

    color: #ffffff;

    background: linear-gradient(
        135deg,
        var(--apha-primary),
        var(--apha-primary-dark)
    );

    /* box-shadow:
        0 8px 18px
        rgba(0, 143, 131, .18); */

    transition:
        transform 0.3s ease,
        box-shadow 0.3s ease;
}

.exports-intro:hover .intro-icon {
    transform: translateY(-2px) rotate(2deg);

    box-shadow: 0 11px 22px rgba(0, 143, 131, 0.22);
}

.download-symbol {
    font-size: 22px;

    font-weight: 800;

    line-height: 1;
}

.intro-copy {
    display: flex;

    flex-direction: column;
}

.intro-eyebrow {
    display: block;

    margin-bottom: 3px;

    color: var(--apha-primary);

    font-size: 9px;

    font-weight: 800;

    letter-spacing: 0.13em;
}

.intro-copy h1 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 21px;

    font-weight: 800;

    letter-spacing: -0.03em;
}

.intro-copy p {
    max-width: 700px;

    margin-top: 5px;

    color: var(--apha-muted);

    font-size: 11px;

    line-height: 1.55;
}

.privacy-badge {
    position: relative;

    z-index: 2;

    display: inline-flex;

    align-items: center;

    gap: 8px;

    flex-shrink: 0;

    padding: 8px 12px;

    border: 1px solid rgba(0, 143, 131, 0.08);

    border-radius: 30px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);

    font-size: 10px;

    font-weight: 700;

    white-space: nowrap;
}

.privacy-dot {
    width: 7px;

    height: 7px;

    border-radius: 50%;

    background: var(--apha-primary);

    box-shadow: 0 0 0 4px rgba(0, 143, 131, 0.08);

    animation: statusPulse 2.2s infinite;
}

.export-card {
    position: relative;

    overflow: hidden;

    width: 100%;

    padding: 28px;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: #ffffff;

    box-shadow: 0 10px 35px rgba(35, 70, 68, 0.045);

    animation: fadeUp 0.6s ease 0.05s both;
}

.card-accent {
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

.export-card-header {
    display: flex;

    align-items: flex-start;

    justify-content: space-between;

    gap: 30px;

    padding-bottom: 20px;

    border-bottom: 1px solid var(--apha-border);
}

.card-heading {
    min-width: 0;
}

.section-label {
    display: block;

    margin-bottom: 5px;

    color: var(--apha-primary);

    font-size: 8.5px;

    font-weight: 850;

    letter-spacing: 0.14em;
}

.export-card-header h2 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 17px;

    font-weight: 800;

    letter-spacing: -0.02em;
}

.heading-description {
    max-width: 700px;

    margin-top: 5px;

    color: var(--apha-light);

    font-size: 10px;

    line-height: 1.5;
}

.period-badge {
    display: inline-flex;

    align-items: center;

    gap: 8px;

    flex-shrink: 0;

    min-height: 34px;

    padding: 0 12px;

    border: 1px solid rgba(0, 143, 131, 0.12);

    border-radius: 9px;

    background: #f8fcfb;

    color: var(--apha-primary-dark);

    font-size: 9px;

    font-weight: 750;

    white-space: nowrap;
}

.period-dot {
    width: 6px;

    height: 6px;

    border-radius: 50%;

    background: var(--apha-primary);
}

.export-description {
    display: flex;

    align-items: flex-start;

    gap: 12px;

    margin-top: 20px;

    padding: 14px 15px;

    border: 1px solid rgba(0, 143, 131, 0.08);

    border-radius: 12px;

    background: rgba(0, 143, 131, 0.035);
}

.description-icon {
    width: 21px;

    height: 21px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: var(--apha-primary);

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

    color: var(--apha-ink);

    font-size: 10px;

    font-weight: 750;
}

.export-description p {
    margin: 0;

    color: var(--apha-muted);

    font-size: 10.5px;

    line-height: 1.55;
}

.export-description strong {
    color: var(--apha-ink);

    font-weight: 800;
}

.download-area {
    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 25px;

    margin-top: 20px;

    padding: 15px;

    border: 1px solid var(--apha-border);

    border-radius: 13px;

    background: linear-gradient(110deg, #fcfdfd, #f9fbfb);

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.download-area:hover {
    border-color: rgba(0, 143, 131, 0.16);

    box-shadow: 0 5px 18px rgba(35, 70, 68, 0.035);
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

    border: 1px solid rgba(0, 143, 131, 0.09);

    border-radius: 11px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);
}

.file-icon span {
    font-size: 9px;

    font-weight: 900;

    letter-spacing: 0.05em;
}

.file-details {
    min-width: 0;
}

.file-title {
    display: block;

    color: var(--apha-ink);

    font-size: 11px;

    font-weight: 800;
}

.file-subtitle {
    display: block;

    margin-top: 3px;

    color: var(--apha-light);

    font-size: 9px;
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

    border: 1px solid var(--apha-primary);

    border-radius: 10px;

    background: var(--apha-primary);

    color: #ffffff;

    font-size: 10.5px;

    font-weight: 800;

    text-decoration: none;

    box-shadow: 0 6px 15px rgba(0, 143, 131, 0.15);

    transition:
        transform 0.2s ease,
        background 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.download-button:hover {
    transform: translateY(-2px);

    background: var(--apha-primary-dark);

    border-color: var(--apha-primary-dark);

    color: #ffffff;

    box-shadow: 0 10px 22px rgba(0, 143, 131, 0.2);
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

    border-top: 1px solid var(--apha-border);
}

.columns-header {
    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;
}

.columns-title-group h3 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 12.5px;

    font-weight: 800;
}

.columns-count {
    display: inline-flex;

    align-items: center;

    gap: 4px;

    padding: 5px 9px;

    border: 1px solid #e8eeee;

    border-radius: 7px;

    background: #f7f9f9;

    color: var(--apha-muted);

    font-size: 9px;

    font-weight: 750;
}

.columns-count span {
    font-weight: 600;

    color: var(--apha-light);
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

    border: 1px solid #e8eeee;

    border-radius: 7px;

    background: #fafcfc;

    color: #687575;

    font-family:
        ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

    font-size: 9px;

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

    background: var(--apha-primary);

    opacity: 0.55;
}

.column-tag:hover {
    transform: translateY(-1px);

    border-color: rgba(0, 143, 131, 0.2);

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);
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

    border: 1px solid rgba(215, 163, 61, 0.25);

    border-radius: 15px;

    background: linear-gradient(110deg, #fffdf8, var(--apha-gold-soft));

    box-shadow: 0 6px 22px rgba(130, 100, 40, 0.035);

    animation: fadeUp 0.65s ease 0.1s both;
}

.privacy-icon {
    width: 39px;

    height: 39px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid rgba(215, 163, 61, 0.15);

    border-radius: 11px;

    background: rgba(215, 163, 61, 0.14);

    color: #aa7b22;

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

    color: #a07829;

    font-size: 8px;

    font-weight: 850;

    letter-spacing: 0.12em;
}

.privacy-content h3 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 12.5px;

    font-weight: 800;
}

.privacy-content p {
    margin-top: 5px;

    color: #756f62;

    font-size: 10px;

    line-height: 1.55;
}

.privacy-content strong {
    color: #80601f;

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

    border: 1px solid rgba(215, 163, 61, 0.22);

    border-radius: 50%;

    background: rgba(255, 255, 255, 0.62);

    color: #9a7027;
}

.privacy-shield span {
    font-size: 13px;

    font-weight: 900;
}

.privacy-shield small {
    margin-top: 1px;

    font-size: 7px;

    font-weight: 750;
}

.export-footnote {
    display: flex;

    align-items: center;

    gap: 8px;

    margin-top: 11px;

    padding: 9px 12px;

    color: var(--apha-light);

    font-size: 9px;

    line-height: 1.45;
}

.footnote-icon {
    width: 16px;

    height: 16px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: #e9eeee;

    color: var(--apha-muted);

    font-size: 9px;

    font-weight: 800;
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

@keyframes statusPulse {
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

@media (max-width: 900px) {
    .exports-page {
        padding-left: 6px;

        padding-right: 6px;
    }

    .exports-intro {
        padding: 21px;
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

    .exports-intro {
        align-items: flex-start;

        flex-direction: column;

        padding: 18px;

        border-radius: 15px;
    }

    .privacy-badge {
        align-self: flex-start;
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
    .intro-main {
        align-items: flex-start;

        gap: 11px;
    }

    .intro-icon {
        width: 40px;

        height: 40px;

        border-radius: 11px;
    }

    .download-symbol {
        font-size: 19px;
    }

    .intro-copy h1 {
        font-size: 17px;
    }

    .intro-copy p {
        font-size: 10px;
    }

    .privacy-badge {
        width: 100%;

        justify-content: center;
    }

    .export-card {
        padding: 16px;
    }

    .export-card-header h2 {
        font-size: 14px;
    }

    .heading-description {
        font-size: 9.5px;
    }

    .period-badge {
        width: 100%;

        justify-content: center;
    }

    .export-description {
        padding: 11px;
    }

    .export-description p {
        font-size: 9.5px;
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

    .privacy-content p {
        font-size: 9.5px;
    }

    .export-footnote {
        align-items: flex-start;

        font-size: 8.5px;
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
