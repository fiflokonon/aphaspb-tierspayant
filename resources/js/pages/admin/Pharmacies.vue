<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import Pagination from '@/components/aphaspb/Pagination.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Row = {
    id: number;
    name: string;
    city: string | null;
    onpbLicense: string | null;
    registeredAt: string | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    pharmacies: Paginated<Row>;
    total: number;
    cities: string[];
    filters: { city: string | null; search: string | null; perPage: number };
    pageSizes: number[];
}>();

const TEMPLATE = '2fr 1fr 1fr 1.1fr';
const COLUMNS = ['Officine', 'Ville', 'N° Onpb', 'Inscrite le'];

const city = ref<string | null>(props.filters.city);
const search = ref(props.filters.search ?? '');
const perPage = ref(props.filters.perPage);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

function reload(page: number) {
    router.get(
        '/admin/pharmacies',
        {
            city: city.value,
            search: search.value || null,
            per_page: perPage.value,
            page,
        },
        {
            only: ['pharmacies', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

let timer: ReturnType<typeof setTimeout> | undefined;

// Debounced, and back to page one: the search fires on every keystroke, and a
// narrowed list rarely still has the page the reader was on.
watch([city, search, perPage], () => {
    clearTimeout(timer);
    timer = setTimeout(() => reload(1), 250);
});

const footer = computed(
    () =>
        `${props.pharmacies.total} officine${props.pharmacies.total > 1 ? 's' : ''} sur ${props.total} inscrite${props.total > 1 ? 's' : ''} · cette liste ne donne accès à aucune déclaration ni à aucun montant`,
);
</script>

<template>
    <Head title="Pharmacies inscrites" />

    <div class="pharmacies-page">

        <!-- =====================================================
             INTRODUCTION + FILTRES
        ====================================================== -->
        <section class="pharmacies-intro">

            <div class="intro-decoration"></div>

            <div class="intro-main">

                <!-- TITRE -->
                <div class="intro-content">

                    <div class="intro-icon">
                        <span>+</span>
                    </div>

                    <div class="intro-text">
                   

                        <h1>
                            Pharmacies inscrites
                        </h1>

                        <span class="intro-period">
                            Officines actuellement enregistrées dans le réseau
                            <template v-if="city !== null">
                                · {{ city }}
                            </template>
                        </span>
                    </div>

                </div>

                <!-- FILTRES -->
                <div class="intro-filters">

                    <!-- RECHERCHE -->
                    <div class="filter-item search-filter">

                        <span class="filter-label">
                            RECHERCHE
                        </span>

                        <div class="search-box">

                            <span
                                class="search-icon"
                                aria-hidden="true"
                            >
                                ⌕
                            </span>

                            <input
                                v-model="search"
                                type="search"
                                placeholder="Rechercher une officine…"
                                aria-label="Rechercher une officine"
                                class="search-input"
                            />

                            <button
                                v-if="search"
                                type="button"
                                class="clear-search"
                                aria-label="Effacer la recherche"
                                @click="search = ''"
                            >
                                ×
                            </button>

                        </div>

                    </div>

                    <!-- VILLE -->
                    <div class="filter-item city-filter-item">

                        <span class="filter-label">
                            VILLE
                        </span>

                        <div class="city-filter">
<!-- 
                            <span
                                class="city-filter-icon"
                                aria-hidden="true"
                            >
                                ●
                            </span> -->

                            <FilterSelect
                                v-model="city"
                                :options="cityOptions"
                                aria-label="Filtrer par ville"
                            />

                        </div>

                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
             CONTENU
        ====================================================== -->
        <div class="pharmacies-body">

            <!-- =================================================
                 TABLEAU
            ================================================== -->
            <section class="pharmacies-table-section">

                <div class="section-top-line"></div>

                <div class="table-heading">

                    <div class="table-heading-main">

                        <div class="table-heading-icon">
                            <span>⌘</span>
                        </div>

                        <div>

                            <!-- <span class="section-eyebrow">
                                DÉTAIL DU RÉSEAU
                            </span> -->

                            <h2>
                                Officines du réseau
                            </h2>

                           

                        </div>

                    </div>

                    <!-- STATUT -->
                    <!-- <div class="table-status">

                        <span class="status-dot"></span>

                        Réseau actif

                    </div> -->

                </div>


                <!-- =================================================
                     DATA TABLE
                ================================================== -->
                <DataTable
                    title=""
                    :columns="COLUMNS"
                    :template="TEMPLATE"
                    :footer="footer"
                    class="pharmacies-table"
                >

                    <DataTableRow
                        v-for="row in pharmacies.data"
                        :key="row.id"
                        :template="TEMPLATE"
                        class="pharmacy-row"
                    >

                        <!-- NOM -->
                        <div class="pharmacy-name-cell">

                            <div class="pharmacy-avatar">
                                {{ row.name?.charAt(0)?.toUpperCase() }}
                            </div>

                            <div class="pharmacy-name-content">

                                <div
                                    class="pharmacy-name"
                                    :title="row.name"
                                >
                                    {{ row.name }}
                                </div>

                                <span class="pharmacy-status">

                                    <span class="mini-status-dot"></span>

                                    Officine inscrite

                                </span>

                            </div>

                        </div>


                        <!-- VILLE -->
                        <div class="city-cell">

                            <span
                                class="city-marker"
                                aria-hidden="true"
                            >
                                ●
                            </span>

                            <span>
                                {{ row.city ?? '—' }}
                            </span>

                        </div>


                        <!-- ONPB -->
                        <div class="license-cell">

                            <span
                                class="license-icon"
                                aria-hidden="true"
                            >
                                #
                            </span>

                            <span>
                                {{ row.onpbLicense ?? '—' }}
                            </span>

                        </div>


                        <!-- DATE -->
                        <div class="date-cell">

                            <span
                                class="date-icon"
                                aria-hidden="true"
                            >
                                ◷
                            </span>

                            <span>
                                {{ row.registeredAt ?? '—' }}
                            </span>

                        </div>

                    </DataTableRow>


                    <!-- =================================================
                         AUCUN RÉSULTAT
                    ================================================== -->
                    <div
                        v-if="!pharmacies.data.length"
                        class="empty-state"
                    >

                        <div class="empty-icon">
                            ⌕
                        </div>

                        <div class="empty-title">
                            Aucune officine trouvée
                        </div>

                        <p>
                            Aucune officine ne correspond aux critères
                            de recherche sélectionnés.
                        </p>

                        <button
                            v-if="search || city"
                            type="button"
                            class="empty-reset"
                            @click="
                                search = '';
                                city = null;
                            "
                        >
                            Réinitialiser les filtres
                        </button>

                    </div>

                </DataTable>

            </section>


            <!-- =================================================
                 PAGINATION
            ================================================== -->
            <div class="pagination-wrapper">

                <Pagination
                    :page="pharmacies.current_page"
                    :last-page="pharmacies.last_page"
                    :from="pharmacies.from"
                    :to="pharmacies.to"
                    :total="pharmacies.total"
                    noun="officine"
                    :per-page="filters.perPage"
                    :page-sizes="pageSizes"
                    @update:page="reload"
                    @update:per-page="perPage = $event"
                />

            </div>


            <!-- =================================================
                 NOTE
            ================================================== -->
            <div class="pharmacies-footnote">

                <div class="footnote-icon">
                    i
                </div>

                <p>
                    Les informations affichées correspondent aux officines
                    actuellement enregistrées dans le réseau.
                </p>

            </div>

        </div>

    </div>
</template>


<style scoped>

/* =========================================================
   VARIABLES
   ========================================================= */

.pharmacies-page {

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

    position: relative;

    width: 100%;
    min-height: 100vh;

    color: var(--ink);

    font-family: 'Manrope', sans-serif;
}


/* =========================================================
   INTRODUCTION
   ========================================================= */

.pharmacies-intro {

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

    animation:
        introAppear
        0.55s ease
        both;
}


/* =========================================================
   LIGNE SUPÉRIEURE
   ========================================================= */

.pharmacies-intro::before {

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


/* =========================================================
   DÉCORATION
   ========================================================= */

.intro-decoration {

    position: absolute;

    right: -65px;
    top: -95px;

    width: 210px;
    height: 210px;

    border: 1px solid
        rgb(0 102 76 / 0.07);

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


/* =========================================================
   INTRO MAIN
   ========================================================= */

.intro-main {

    position: relative;

    z-index: 2;

    display: flex;
    align-items: center;

    width: 100%;

    min-width: 0;

    gap: 34px;
}


/* =========================================================
   TITRE
   ========================================================= */

.intro-content {

    display: flex;
    align-items: center;

    min-width: 285px;

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

    font-size: 18px;

    font-weight: 850;
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

    font-size: 10px;

    font-weight: 600;
}


/* =========================================================
   FILTRES
   ========================================================= */

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


/* =========================================================
   RECHERCHE
   ========================================================= */

.search-filter {

    flex: 1.4;
}

.search-box {

    position: relative;

    display: flex;
    align-items: center;

    width: 100%;

    height: 39px;

    border: 1px solid var(--border);

    border-radius: 9px;

    background: #ffffff;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.search-box:hover {

    border-color:
        color-mix(
            in srgb,
            var(--primary) 25%,
            var(--border)
        );
}

.search-box:focus-within {

    border-color:
        color-mix(
            in srgb,
            var(--primary) 42%,
            var(--border)
        );

    box-shadow:
        0 0 0 3px
        rgb(0 102 76 / 0.06);
}

.search-icon {

    display: flex;
    align-items: center;
    justify-content: center;

    width: 35px;

    flex-shrink: 0;

    color: var(--muted);

    font-size: 16px;
}

.search-input {

    width: 100%;
    height: 100%;

    padding: 0 32px 0 0;

    border: none;
    outline: none;

    background: transparent;

    color: var(--ink);

    font-size: 10.5px;

    font-weight: 600;
}

.search-input::placeholder {

    color:
        color-mix(
            in srgb,
            var(--ink) 38%,
            transparent
        );

    font-weight: 500;
}

.search-input::-webkit-search-cancel-button {
    display: none;
}


/* =========================================================
   CLEAR SEARCH
   ========================================================= */

.clear-search {

    position: absolute;

    right: 8px;
    top: 50%;

    width: 20px;
    height: 20px;

    display: flex;
    align-items: center;
    justify-content: center;

    transform: translateY(-50%);

    border: none;

    border-radius: 50%;

    background: var(--primary-soft);

    color: var(--primary-dark);

    font-size: 13px;

    cursor: pointer;

    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.clear-search:hover {

    background: #dcefe8;

    color: var(--primary);
}


/* =========================================================
   VILLE
   ========================================================= */

.city-filter-item {

    flex: 0.85;
}

.city-filter {

    position: relative;

    /* display: flex; */
    align-items: center;

    width: 100%;

    height: 39px;

    border: 1px solid var(--border);

    border-radius: 9px;

    background: #ffffff;

    overflow: hidden;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.city-filter:hover {

    border-color:
        color-mix(
            in srgb,
            var(--primary) 25%,
            var(--border)
        );
}

.city-filter-icon {

    position: absolute;

    left: 11px;

    z-index: 2;

    color: var(--primary);

    font-size: 7px;

    pointer-events: none;
}

.city-filter :deep(select) {

    width: 100%;

    height: 100%;

    min-width: 135px;

    padding-left: 27px;
    padding-right: 30px;

    border: none;
    outline: none;

    background: transparent;

    color: var(--ink);

    font-size: 10px;

    font-weight: 650;

    cursor: pointer;
}


/* =========================================================
   BODY
   ========================================================= */

.pharmacies-body {

    display: flex;
    flex-direction: column;

    gap: 14px;

    padding-bottom: 40px;
}


/* =========================================================
   TABLE SECTION
   ========================================================= */

.pharmacies-table-section {

    position: relative;

    width: 100%;

    overflow: hidden;

    border: 1px solid var(--border);

    border-radius: 20px;

    background: var(--surface);

    animation:
        fadeUp
        0.55s ease
        0.05s both;
}


/* =========================================================
   LIGNE ACCENT
   ========================================================= */

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


/* =========================================================
   TABLE HEADING
   ========================================================= */

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

.table-heading-main {

    display: flex;
    align-items: center;

    gap: 12px;
}

.table-heading-icon {

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

.table-heading h2 {

    margin: 0;

    color: var(--ink);

    font-size: 17px;

    font-weight: 800;

    line-height: 1.3;

    letter-spacing: -0.02em;
}

.table-heading p {

    max-width: 720px;

    margin: 7px 0 0;

    color: var(--muted);

    font-size: 11px;

    line-height: 1.55;
}


/* =========================================================
   STATUT
   ========================================================= */

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
}


/* =========================================================
   TABLE
   ========================================================= */

.pharmacies-table {

    border-radius: 0 0 18px 18px;

    overflow: hidden;
}


/* =========================================================
   PHARMACY
   ========================================================= */

.pharmacy-name-cell {

    display: flex;
    align-items: center;

    gap: 11px;

    min-width: 220px;
}

.pharmacy-avatar {

    width: 36px;
    height: 36px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 1px solid
        rgb(0 102 76 / 0.12);

    border-radius: 10px;

    background:
        linear-gradient(
            145deg,
            var(--primary-soft),
            #f8fcfa
        );

    color: var(--primary-dark);

    font-size: 11px;

    font-weight: 850;

    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.pharmacy-row:hover .pharmacy-avatar {

    transform: translateY(-1px);

    box-shadow:
        0 5px 14px
        rgb(0 102 76 / 0.10);
}

.pharmacy-name-content {

    min-width: 0;

    display: flex;
    flex-direction: column;

    gap: 3px;
}

.pharmacy-name {

    max-width: 100%;

    overflow: hidden;

    color: var(--ink);

    font-size: 11.5px;

    font-weight: 750;

    text-overflow: ellipsis;

    white-space: nowrap;
}

.pharmacy-status {

    display: inline-flex;
    align-items: center;

    gap: 5px;

    color: var(--muted);

    font-size: 9.5px;

    font-weight: 550;
}

.mini-status-dot {

    width: 5px;
    height: 5px;

    flex-shrink: 0;

    border-radius: 50%;

    background: var(--primary);

    box-shadow:
        0 0 0 3px
        rgb(0 102 76 / 0.07);
}


/* =========================================================
   VILLE
   ========================================================= */

.city-cell {

    display: flex;
    align-items: center;

    gap: 8px;

    color:
        color-mix(
            in srgb,
            var(--ink) 63%,
            transparent
        );

    font-size: 10.5px;

    font-weight: 600;
}

.city-marker {

    width: 22px;
    height: 22px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 7px;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 7px;
}


/* =========================================================
   ONPB
   ========================================================= */

.license-cell {

    display: flex;
    align-items: center;

    gap: 8px;

    color:
        color-mix(
            in srgb,
            var(--ink) 64%,
            transparent
        );

    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Monaco,
        Consolas,
        monospace;

    font-size: 9.5px;

    font-weight: 600;
}

.license-icon {

    width: 22px;
    height: 22px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 7px;

    background: var(--gold-soft);

    color: var(--gold);

    font-size: 9px;

    font-weight: 800;
}


/* =========================================================
   DATE
   ========================================================= */

.date-cell {

    display: flex;
    align-items: center;

    gap: 8px;

    color:
        color-mix(
            in srgb,
            var(--ink) 60%,
            transparent
        );

    font-size: 10px;

    font-weight: 600;
}

.date-icon {

    width: 22px;
    height: 22px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 7px;

    background:
        rgb(0 102 76 / 0.06);

    color: var(--primary);

    font-size: 12px;
}


/* =========================================================
   EMPTY STATE
   ========================================================= */

.empty-state {

    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;

    min-height: 250px;

    padding: 40px 20px;

    border-top: 1px solid
        color-mix(
            in srgb,
            var(--ink) 6%,
            transparent
        );

    text-align: center;
}

.empty-icon {

    width: 50px;
    height: 50px;

    display: flex;
    align-items: center;
    justify-content: center;

    margin-bottom: 13px;

    border: 1px solid
        rgb(0 102 76 / 0.12);

    border-radius: 15px;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 20px;
}

.empty-title {

    color: var(--ink);

    font-size: 12.5px;

    font-weight: 750;
}

.empty-state p {

    max-width: 390px;

    margin: 6px 0 15px;

    color: var(--muted);

    font-size: 11px;

    line-height: 1.55;
}

.empty-reset {

    height: 34px;

    padding: 0 14px;

    border: 1px solid
        rgb(0 102 76 / 0.16);

    border-radius: 8px;

    background: #ffffff;

    color: var(--primary-dark);

    font-size: 10.5px;

    font-weight: 700;

    cursor: pointer;

    transition:
        background 0.2s ease,
        border-color 0.2s ease,
        transform 0.2s ease;
}

.empty-reset:hover {

    border-color:
        rgb(0 102 76 / 0.30);

    background: var(--primary-soft);

    transform: translateY(-1px);
}


/* =========================================================
   PAGINATION
   ========================================================= */

.pagination-wrapper {

    margin-top: 0;

    padding: 0 2px;
}


/* =========================================================
   NOTE
   ========================================================= */

.pharmacies-footnote {

    display: flex;
    align-items: center;

    gap: 10px;

    padding: 11px 14px;

    border: 1px solid var(--border);

    border-radius: 13px;

    background:
        linear-gradient(
            105deg,
            #ffffff,
            #fbfdfc
        );
}

.footnote-icon {

    width: 19px;
    height: 19px;

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

.pharmacies-footnote p {

    margin: 0;

    color: var(--muted);

    font-size: 10.5px;

    line-height: 1.5;
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
   TABLET
   ========================================================= */

@media (max-width: 1000px) {

    .pharmacies-page {
        padding-left: 6px;
        padding-right: 6px;
    }

    .intro-main {
        gap: 24px;
    }

    .intro-content {
        min-width: 230px;
    }

    .intro-filters {
        padding-left: 20px;
    }
}


/* =========================================================
   TABLET PETIT
   ========================================================= */

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


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 700px) {

    .pharmacies-page {

        padding: 0 4px 50px;
    }

    .pharmacies-intro {

        padding: 20px 17px;

        border-radius: 16px;
    }

    .pharmacies-intro::before {

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

    .city-filter-item {

        flex-basis: 100%;
    }

    .table-heading {

        padding: 20px 16px 15px;
    }

    .table-heading h2 {

        font-size: 16px;
    }

    .table-heading p {

        font-size: 10.5px;
    }
}


/* =========================================================
   PETIT MOBILE
   ========================================================= */

@media (max-width: 480px) {

    .pharmacies-intro {

        padding: 19px 15px;
    }

    .intro-filters {

        flex-direction: column;

        align-items: stretch;
    }

    .filter-item,
    .city-filter-item {

        width: 100%;

        flex: 1 1 auto;
    }

    .table-heading-main {

        align-items: flex-start;
    }

    .table-heading-icon {

        width: 35px;
        height: 35px;

        border-radius: 10px;
    }

    .section-eyebrow {

        font-size: 7.5px;
    }

    .pharmacy-name-cell {

        min-width: 175px;
    }

    .pharmacy-avatar {

        width: 32px;
        height: 32px;

        border-radius: 9px;
    }

    .pharmacy-name {

        font-size: 10.5px;
    }

    .city-cell,
    .date-cell {

        font-size: 10px;
    }

    .license-cell {

        font-size: 9px;
    }

    .pharmacies-footnote {

        align-items: flex-start;
    }
}


/* =========================================================
   ACCESSIBILITÉ
   ========================================================= */

@media (prefers-reduced-motion: reduce) {

    .pharmacies-page *,
    .pharmacies-page *::before,
    .pharmacies-page *::after {

        animation-duration: 0.01ms !important;

        animation-iteration-count: 1 !important;

        transition-duration: 0.01ms !important;
    }
}

</style>

