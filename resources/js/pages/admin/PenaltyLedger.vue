<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ChartSkeleton from '@/components/aphaspb/charts/ChartSkeleton.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import PenaltyLedgerTable from '@/components/aphaspb/PenaltyLedgerTable.vue';
import PenaltyTrendCard from '@/components/aphaspb/PenaltyTrendCard.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import type { PenaltyLedger } from '@/types/aphaspb';

const props = defineProps<{
    penaltyTrend?: PenaltyLedger;
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    city: string | null;
    cities: string[];
    insurer: number | null;
    insurers: { id: number; name: string }[];
    downloadUrl: string;
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

/** Le journal est différé : il doit être nommé dans `only` pour revenir. */
watch([period, city, insurer], () =>
    router.get(
        '/admin/penalties',
        { period: period.value, city: city.value, insurer: insurer.value },
        {
            only: ['penaltyTrend', 'period', 'periodLabel', 'city', 'insurer'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    ),
);

/** Chaque lien porte les filtres : le fichier couvre l'écran, pas autre chose. */
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

const FORMATS = [
    { key: 'pdf' as const, label: 'PDF' },
    { key: 'xlsx' as const, label: 'XLSX' },
    { key: 'csv' as const, label: 'CSV' },
];
</script>

<template>
    <Head title="Journal des pénalités" />

    <div class="penalty-ledger-page">
        

        <!-- INTRODUCTION + FILTRES -->
        <section class="ledger-intro">
            <div class="intro-decoration"></div>

            <div class="intro-main">
                <div class="intro-content">
                    <div class="intro-icon">
                        <span>!</span>
                    </div>

                    <div class="intro-text">
                       

                        <h1>Journal des pénalités</h1>

                        <span class="intro-period">
                            {{ periodLabel }}
                            <template v-if="city !== null">
                                · {{ city }}
                            </template>
                        </span>
                    </div>
                </div>

                <div class="intro-filters">
                    <div class="filter-item">
                        <span class="filter-label">PÉRIODE</span>

                        <FilterSelect
                            v-model="period"
                            :options="periods"
                            aria-label="Filtrer par période"
                        />
                    </div>

                    <div class="filter-item">
                        <span class="filter-label">VILLE</span>

                        <FilterSelect
                            v-model="city"
                            :options="cityOptions"
                            aria-label="Filtrer par ville"
                        />
                    </div>

                    <div class="filter-item insurer-filter">
                        <span class="filter-label">ASSUREUR</span>

                        <FilterSelect
                            v-model="insurer"
                            :options="insurerOptions"
                            aria-label="Filtrer par assureur"
                        />
                    </div>
                </div>
            </div>
        </section>

        <div class="ledger-body">
            <!-- TENDANCE DES PÉNALITÉS -->
            <section class="penalty-analysis">
                <div class="section-top-line"></div>

                <div class="analysis-heading">
                    <div class="analysis-title-row">
                        <div class="analysis-icon">
                            <span>↗</span>
                        </div>

                        <div>
                            <span class="section-eyebrow">
                                ANALYSE DU RÉSEAU
                            </span>

                            <h2>Évolution des pénalités</h2>
                        </div>
                    </div>

                    <p>
                        Suivez l’évolution des pénalités enregistrées sur le
                        réseau et identifiez les périodes concernées.
                    </p>
                </div>

                <div class="analysis-card">
                    <PenaltyTrendCard
                        :ledger="penaltyTrend"
                        :subtitle="`${periodLabel}${city === null ? '' : ` · ${city}`}`"
                        filename="aphaspb-journal-penalites-reseau"
                        :show-insurer-filter="false"
                    />
                </div>
            </section>

            <!-- TABLEAU -->
            <section class="ledger-table-section">
                <div class="section-top-line"></div>

                <Deferred data="penaltyTrend">
                    <template #fallback>
                        <div class="table-loading">
                            <ChartSkeleton :height="320" />
                        </div>
                    </template>

                    <div
                        v-if="
                            penaltyTrend &&
                            (penaltyTrend.insurers.length > 0 ||
                                penaltyTrend.maskedInsurers > 0)
                        "
                        class="ledger-table-wrapper"
                    >
                        <div class="table-heading">
                            <div>
                                <span class="section-eyebrow">
                                    DÉTAIL DU JOURNAL
                                </span>

                                <h2>Pénalités par assureur</h2>

                                <p>
                                    Retrouvez les montants et les éléments
                                    associés aux pénalités du réseau.
                                </p>
                            </div>

                            <div class="table-status">
                                <span class="status-dot"></span>
                                Données consolidées
                            </div>
                        </div>

                        <PenaltyLedgerTable
                            :ledger="penaltyTrend"
                        />
                    </div>
                </Deferred>

                <div
                    v-if="penaltyTrend && penaltyTrend.maskedInsurers > 0"
                    class="masked-note"
                >
                    <div class="note-icon">i</div>

                    <p>
                        Le total couvre également
                        <strong>{{ penaltyTrend.maskedInsurers }}</strong>
                        assureur(s) masqué(s) sous le seuil d’anonymat.
                    </p>
                </div>
            </section>

            <!-- EXPORTS -->
            <section class="exports-card">
                <div class="exports-icon">
                    ↓
                </div>

                <div class="exports-content">
                    <span class="exports-eyebrow">DOCUMENTS</span>

                    <strong>Exporter le journal</strong>

                    <span>
                        Téléchargez les données du journal dans le format
                        souhaité.
                    </span>
                </div>

                <div class="exports-actions">
                    <a
                        v-for="format in FORMATS"
                        :key="format.key"
                        :href="hrefFor(format.key)"
                        class="export-link"
                    >
                        <span>{{ format.label }}</span>
                        <span class="export-arrow">↗</span>
                    </a>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.penalty-ledger-page {
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

    --danger: #b96558;
    --danger-soft: #fbf1ef;

    --border: #e5ebe8;

    position: relative;
    width: 100%;
    min-height: 100vh;
    /* padding: 0 10px 60px; */

    color: var(--ink);

    /* background:
        radial-gradient(
            circle at 92% 4%,
            rgb(0 102 76 / 0.035),
            transparent 25%
        ),
        linear-gradient(
            180deg,
            #ffffff 0%,
            var(--page-bg) 48%,
            #f8faf9 100%
        ); */

    font-family: 'Manrope', sans-serif;
}

/* =========================================================
   INTRO
========================================================= */

.ledger-intro {
    position: relative;

    display: flex;
    align-items: center;

    width: 100%;

    margin: 10px 0 25px;
    padding: 22px 24px;

    overflow: hidden;

    border: 1px solid var(--border);
    border-radius: 18px;

    background:
        linear-gradient(
            110deg,
            #ffffff 0%,
            #ffffff 58%,
            #f7fbf9 100%
        );

    /* box-shadow:
        0 7px 28px rgb(35 70 68 / 0.045); */

    animation: introAppear 0.55s ease both;
}

.ledger-intro::before {
    position: absolute;

    left: 24px;
    right: 24px;
    top: 0;

    height: 2px;

    border-radius: 0 0 4px 4px;

    background:
        linear-gradient(
            90deg,
            var(--primary),
            #2b896d 70%,
            var(--gold)
        );

    content: '';
}

.intro-decoration {
    position: absolute;

    right: -65px;
    top: -95px;

    width: 210px;
    height: 210px;

    border: 1px solid rgb(0 102 76 / 0.07);
    border-radius: 50%;

    pointer-events: none;
}

.intro-decoration::after {
    position: absolute;

    right: 28px;
    bottom: 28px;

    width: 70px;
    height: 70px;

    border-radius: 50%;

    background: var(--primary-soft);

    content: '';
}

.intro-main {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;

    width: 100%;
    min-width: 0;

    gap: 34px;
}

.intro-content {
    display: flex;
    align-items: center;

    min-width: 250px;

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

    background:
        linear-gradient(
            145deg,
            var(--primary),
            var(--primary-dark)
        );

    color: #ffffff;

    font-size: 17px;
    font-weight: 850;

    /* box-shadow:
        0 7px 18px rgb(0 102 76 / 0.16); */
}

.intro-text {
    min-width: 0;
}

.intro-eyebrow {
    display: block;

    margin-bottom: 4px;

    color: var(--primary);

    font-size: 8px;
    font-weight: 850;

    letter-spacing: 0.15em;
}

.intro-text h1 {
    margin: 0;

    color: #203a31;

    font-size: 21px;
    font-weight: 800;

    line-height: 1.25;
    letter-spacing: -0.025em;
}

.intro-period {
    display: block;

    margin-top: 4px;

    color: var(--muted);

    font-size: 13px;
    font-weight: 600;
}

.intro-filters {
    display: flex;
    align-items: flex-end;

    flex: 1;

    min-width: 0;

    gap: 12px;

    padding-left: 28px;

    border-left: 1px solid #e8eeeb;
}

.filter-item {
    display: flex;
    flex-direction: column;

    flex: 1;
    min-width: 0;

    gap: 5px;
}

.filter-label {
    color: var(--muted-light);

    font-size: 8px;
    font-weight: 850;

    letter-spacing: 0.12em;
}

.insurer-filter {
    flex: 1.05;
}

/* =========================================================
   BODY
========================================================= */

.ledger-body {
    display: flex;
    flex-direction: column;

    gap: 22px;

    padding: 0 0 40px;
}

/* =========================================================
   ANALYSE
========================================================= */

.penalty-analysis {
    position: relative;

    width: 100%;

    overflow: hidden;

    border: 1px solid var(--border);
    border-radius: 20px;

    background: var(--surface);

    /* box-shadow:
        0 8px 30px rgb(30 68 57 / 0.055); */

    animation: fadeUp 0.55s ease 0.04s both;
}

.section-top-line {
    position: absolute;

    left: 0;
    top: 0;

    width: 100%;
    height: 3px;

    background:
        linear-gradient(
            90deg,
            var(--primary),
            #27856a 65%,
            var(--gold)
        );
}

.analysis-heading {
    padding: 24px 24px 15px;

    border-bottom: 1px solid var(--border);
}

.analysis-title-row {
    display: flex;
    align-items: center;

    gap: 12px;
}

.analysis-icon {
    width: 39px;
    height: 39px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 1px solid #d9ebe4;
    border-radius: 12px;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 16px;
    font-weight: 850;
}

.section-eyebrow {
    display: block;

    margin-bottom: 4px;

    color: var(--primary);

    font-size: 8.5px;
    font-weight: 850;

    letter-spacing: 0.16em;
}

.analysis-heading h2,
.table-heading h2 {
    margin: 0;

    color: var(--ink);

    font-size: 17px;
    font-weight: 800;

    line-height: 1.3;
    letter-spacing: -0.02em;
}

.analysis-heading p,
.table-heading p {
    max-width: 720px;

    margin: 9px 0 0;

    color: var(--muted);

    /* font-size: 13px; */
    line-height: 1.55;
}

.analysis-card {
    padding: 4px;
}

/* =========================================================
   TABLEAU
========================================================= */

.ledger-table-section {
    position: relative;

    width: 100%;

    overflow: hidden;

    border: 1px solid var(--border);
    border-radius: 20px;

    background: var(--surface);

    /* box-shadow:
        0 8px 30px rgb(30 68 57 / 0.055); */

    animation: fadeUp 0.6s ease 0.08s both;
}

.ledger-table-wrapper {
    width: 100%;
}

.table-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;

    gap: 24px;

    padding: 24px 24px 17px;

    border-bottom: 1px solid var(--border);

    background:
        linear-gradient(
            135deg,
            #ffffff,
            #fbfdfc
        );
}

.table-status {
    display: inline-flex;
    align-items: center;

    flex-shrink: 0;

    gap: 7px;

    min-height: 31px;

    padding: 0 11px;

    border: 1px solid #dcebe5;
    border-radius: 9px;

    background: var(--primary-soft);

    color: var(--primary-dark);

    font-size: 10px;
    font-weight: 750;

    white-space: nowrap;
}

.status-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: var(--primary);

    /* box-shadow:
        0 0 0 3px rgb(0 102 76 / 0.08); */
}

.table-loading {
    padding: 20px;
}

/* =========================================================
   NOTE
========================================================= */

.masked-note {
    display: flex;
    align-items: flex-start;

    gap: 9px;

    margin: 13px 20px 18px;

    padding: 11px 13px;

    border: 1px solid #e6eee9;
    border-radius: 11px;

    background: #f8fcfa;
}

.note-icon {
    width: 18px;
    height: 18px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 9px;
    font-weight: 850;
}

.masked-note p {
    margin: 0;

    color: var(--muted);

    font-size: 10.5px;
    line-height: 1.5;
}

.masked-note strong {
    color: var(--ink);
    font-weight: 800;
}

/* =========================================================
   EXPORT
========================================================= */

.exports-card {
    display: flex;
    align-items: center;

    gap: 13px;

    padding: 15px 17px;

    border: 1px solid var(--border);
    border-radius: 16px;

    background:
        linear-gradient(
            105deg,
            #ffffff,
            #fbfdfc
        );

    /* box-shadow:
        0 6px 22px rgb(30 68 57 / 0.04); */

    animation: fadeUp 0.65s ease 0.12s both;
}

.exports-icon {
    width: 38px;
    height: 38px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;

    background: var(--gold-soft);

    color: var(--gold);

    font-size: 15px;
    font-weight: 850;
}

.exports-content {
    display: flex;
    flex-direction: column;

    min-width: 0;

    gap: 2px;
}

.exports-eyebrow {
    color: var(--gold);

    font-size: 13px;
    font-weight: 850;

    letter-spacing: 0.15em;
}

.exports-content strong {
    color: var(--ink);

    font-size: 13px;
    font-weight: 800;
}

.exports-content > span:last-child {
    color: var(--muted);

    font-size: 13px;
}

.exports-actions {
    display: flex;
    align-items: center;

    gap: 7px;

    margin-left: auto;
}

.export-link {
    display: inline-flex;
    align-items: center;

    gap: 8px;

    min-height: 32px;

    padding: 0 11px;

    border: 1px solid var(--border);
    border-radius: 9px;

    background: #ffffff;

    color: var(--ink);

    font-size: 9.5px;
    font-weight: 750;

    text-decoration: none;

    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        background 0.2s ease,
        color 0.2s ease;
}

.export-link:hover {
    border-color: #cfe1d9;

    background: var(--primary-soft);

    color: var(--primary-dark);

    transform: translateY(-1px);
}

.export-arrow {
    color: var(--primary);
    font-size: 11px;
}

/* =========================================================
   ANIMATIONS
========================================================= */

@keyframes introAppear {
    from {
        opacity: 0;
        transform: translateY(7px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
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

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1000px) {
    .penalty-ledger-page {
        padding-left: 6px;
        padding-right: 6px;
    }

    .intro-main {
        gap: 24px;
    }

    .intro-content {
        min-width: 220px;
    }

    .intro-filters {
        padding-left: 20px;
    }
}

@media (max-width: 850px) {
    .intro-main {
        flex-direction: column;
        align-items: stretch;
        gap: 18px;
    }

    .intro-content {
        min-width: 0;
    }

    .intro-filters {
        width: 100%;
        padding-top: 16px;
        padding-left: 0;

        border-top: 1px solid #e8eeeb;
        border-left: 0;
    }

    .table-heading {
        flex-direction: column;
        gap: 12px;
    }

    .table-status {
        align-self: flex-start;
    }
}

@media (max-width: 700px) {
    .penalty-ledger-page {
        padding: 0 4px 50px;
    }

    .ledger-intro {
        padding: 20px 17px;
        border-radius: 16px;
    }

    .ledger-intro::before {
        left: 17px;
        right: 17px;
    }

    .intro-icon {
        width: 43px;
        height: 43px;
        border-radius: 12px;
    }

    .intro-text h1 {
        font-size: 19px;
    }

    .intro-filters {
        flex-wrap: wrap;
    }

    .filter-item {
        flex: 1 1 calc(50% - 6px);
    }

    .insurer-filter {
        flex-basis: 100%;
    }

    .analysis-heading,
    .table-heading {
        padding: 20px 16px 15px;
    }

    .analysis-heading h2,
    .table-heading h2 {
        font-size: 16px;
    }

    .exports-card {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .exports-actions {
        width: 100%;
        margin-left: 51px;
    }
}

@media (max-width: 480px) {
    .ledger-intro {
        padding: 19px 15px;
    }

    .intro-filters {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-item,
    .insurer-filter {
        width: 100%;
        flex: 1 1 auto;
    }

    .analysis-icon {
        width: 35px;
        height: 35px;
        border-radius: 10px;
    }

    .section-eyebrow {
        font-size: 7.5px;
    }

    .analysis-heading p,
    .table-heading p {
        /* font-size: 13px; */
    }

    .exports-actions {
        flex-wrap: wrap;
        margin-left: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .penalty-ledger-page *,
    .penalty-ledger-page *::before,
    .penalty-ledger-page *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>

