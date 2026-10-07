<script setup lang="ts">
/**
 * Qui a déclaré quel mois, pour relancer hors plateforme.
 *
 * Exception délimitée au CDC : un nom d'officine et son numéro de contact à
 * côté de l'état de son mois, et rien d'autre — ni assureur, ni nombre
 * d'assureurs, ni montant. La relance part sur WhatsApp ou par téléphone ; la
 * plateforme ne l'envoie pas.
 */
import { Head, router } from '@inertiajs/vue3';
import { MessageCircle, Phone } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import Pagination from '@/components/aphaspb/Pagination.vue';
import StatusChip from '@/components/aphaspb/StatusChip.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type CompletenessState = 'complete' | 'partial' | 'none';

type Row = {
    id: number;
    name: string;
    city: string | null;
    state: CompletenessState;
    stateLabel: string;
    phone: { href: string; label: string } | null;
    whatsappUrl: string | null;
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
    month: string;
    months: { value: string; label: string }[];
    city: string | null;
    cities: string[];
    state: string;
    summary: { complete: number; partial: number; none: number; total: number };
    pharmacies: Paginated<Row>;
}>();

const TEMPLATE = '2fr 1fr 1fr 1.2fr 1.3fr';
const COLUMNS = ['Officine', 'Ville', 'État du mois', 'Contact', 'Relance'];

/**
 * Les teintes des statuts de déclaration, réutilisées pour ne pas inventer une
 * troisième palette : complète se lit comme « payée », partielle comme
 * « partielle », sans déclaration comme « non payée ».
 */
const CHIP: Record<CompletenessState, 'paid' | 'partial' | 'unpaid'> = {
    complete: 'paid',
    partial: 'partial',
    none: 'unpaid',
};

const STATES = [
    { value: 'to-chase', label: 'À relancer' },
    { value: 'all', label: 'Toutes' },
    { value: 'complete', label: 'Complètes' },
    { value: 'partial', label: 'Partielles' },
    { value: 'none', label: 'Sans déclaration' },
];

const month = ref(props.month);
const city = ref<string | null>(props.city);
const state = ref(props.state);

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

function reload(page: number) {
    router.get(
        '/admin/declarations-followup',
        { month: month.value, city: city.value, state: state.value, page },
        {
            only: ['pharmacies', 'summary', 'month', 'city', 'state'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch([month, city, state], () => reload(1));

const summaryLine = computed(
    () =>
        `${props.summary.complete} complètes · ${props.summary.partial} partielles · ${props.summary.none} sans déclaration, sur ${props.summary.total} officines`,
);
</script>

<template>
    <Head title="Suivi des déclarations" />
    <br>

    <div class="followup-page">
        <!-- =========================================================
             INTRODUCTION
        ========================================================== -->
        <section class="followup-intro">
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
                                d="M9 5h6"
                                stroke-linecap="round"
                            />
                            <path
                                d="M9 3h6a1 1 0 0 1 1 1v1H8V4a1 1 0 0 1 1-1Z"
                            />
                            <rect
                                x="5"
                                y="5"
                                width="14"
                                height="16"
                                rx="2"
                            />
                            <path
                                d="m8.5 11 1.8 1.8L14 9"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                            <path
                                d="M8.5 16h7"
                                stroke-linecap="round"
                            />
                        </svg>
                    </div>

                    <div class="intro-text">
                        

                        <h1>Suivi des déclarations</h1>

                        <!-- <p class="intro-description">
                            Consultez l’état des déclarations des officines
                            et identifiez rapidement les dossiers à relancer.
                        </p> -->

                        <div class="intro-period">
                           
                            {{ summaryLine }}
                        </div>
                    </div>
                </div>

                <!-- =====================================================
                     FILTRES
                ====================================================== -->
                <div class="intro-filters">
                    <!-- <div class="filter-heading">
                        <span class="filter-heading-line"></span>
                        <span>Filtres</span>
                    </div> -->

                    <div class="filters-grid">
                        <div class="filter-item">
                              <label for="month" style="font-weight: 600;">Mois</label>
                            <FilterSelect
                                v-model="month"
                                :options="months"
                                aria-label="Choisir le mois"
                            />
                        </div>

                        <div class="filter-item">
                              <label for="city" style="font-weight: 600;">Ville</label>

                            <FilterSelect
                                v-model="city"
                                :options="cityOptions"
                             
                                aria-label="Filtrer par ville"
                            />
                        </div>

                        <div class="filter-item">
                              <label for="state" style="font-weight: 600;">État</label>

                            <FilterSelect
                                v-model="state"
                                :options="STATES"
                                
                                aria-label="Filtrer par état"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- =========================================================
             CONTENU
        ========================================================== -->
        <div class="followup-body">
            <!-- =====================================================
                 TABLEAU
            ====================================================== -->
            <section class="followup-table-section">
                <div class="section-top-line"></div>

                <div class="table-heading">
                    <div class="table-heading-content">
                        <!-- <span class="section-eyebrow">
                            DÉCLARATIONS DES OFFICINES
                        </span> -->

                        <h2>Officines</h2>

                        <!-- <p>
                            Visualisez l’état de chaque officine et relancez
                            directement les établissements concernés.
                        </p> -->
                    </div>

                    <!-- <div class="table-status">
                        <span class="status-dot"></span>
                        Suivi actif
                    </div> -->
                </div>

                <DataTable
                    title=""
                    :columns="COLUMNS"
                    :template="TEMPLATE"
                    class="followup-table"
                >
                    <DataTableRow
                        v-for="row in pharmacies.data"
                        :key="row.id"
                        :template="TEMPLATE"
                        class="followup-row"
                    >
                        <!-- NOM -->
                        <div class="name-cell">
                            <div class="pharmacy-avatar">
                                {{ row.name?.charAt(0)?.toUpperCase() }}
                            </div>

                            <div class="name-content">
                                <div
                                    class="name"
                                    :title="row.name"
                                >
                                    {{ row.name }}
                                </div>

                                <span class="name-caption">
                                    Officine
                                </span>
                            </div>
                        </div>

                        <!-- VILLE -->
                        <div class="city-cell">
                            <span class="city-icon" aria-hidden="true">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                >
                                    <path
                                        d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"
                                    />
                                    <circle
                                        cx="12"
                                        cy="10"
                                        r="2.5"
                                    />
                                </svg>
                            </span>

                            <span>
                                {{ row.city ?? '—' }}
                            </span>
                        </div>

                        <!-- ÉTAT -->
                        <div class="state-cell">
                            <StatusChip
                                :status="CHIP[row.state]"
                                :label="row.stateLabel"
                            />
                        </div>

                        <!-- TÉLÉPHONE -->
                        <div class="phone-cell">
                            <a
                                v-if="row.phone"
                                :href="row.phone.href"
                                class="phone-link"
                            >
                                <span class="action-icon phone-icon">
                                    <Phone
                                        class="size-[14px]"
                                        :stroke-width="2"
                                        aria-hidden="true"
                                    />
                                </span>

                                <span>{{ row.phone.label }}</span>
                            </a>

                            <span
                                v-else
                                class="muted-cell"
                                aria-label="Pas de numéro"
                            >
                                —
                            </span>
                        </div>

                        <!-- WHATSAPP -->
                        <div class="whatsapp-cell">
                            <a
                                v-if="row.whatsappUrl"
                                :href="row.whatsappUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="whatsapp-link"
                            >
                                <span class="action-icon whatsapp-icon">
                                    <MessageCircle
                                        class="size-[14px]"
                                        :stroke-width="2"
                                        aria-hidden="true"
                                    />
                                </span>

                                <span>Relancer sur WhatsApp</span>
                            </a>

                            <span
                                v-else-if="row.state !== 'complete'"
                                class="muted-cell"
                            >
                                Pas de numéro
                            </span>

                            <span
                                v-else
                                class="muted-cell"
                                aria-label="Rien à relancer"
                            >
                                —
                            </span>
                        </div>
                    </DataTableRow>

                    <!-- ÉTAT VIDE -->
                    <div
                        v-if="!pharmacies.data.length"
                        class="empty-state"
                    >
                        <div class="empty-icon">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.6"
                            >
                                <circle cx="11" cy="11" r="6.5" />
                                <path
                                    d="m16 16 4 4"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M8.5 11h5"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </div>

                        <div class="empty-title">
                            Aucune officine trouvée
                        </div>

                        <p>
                            Aucune officine ne correspond aux critères
                            sélectionnés.
                        </p>
                    </div>
                </DataTable>
            </section>

            <!-- =====================================================
                 PAGINATION
            ====================================================== -->
            <div class="pagination-wrapper">
                <Pagination
                    class="pagination"
                    :page="pharmacies.current_page"
                    :last-page="pharmacies.last_page"
                    :from="pharmacies.from"
                    :to="pharmacies.to"
                    :total="pharmacies.total"
                    noun="officine"
                    @update:page="reload"
                />
            </div>

            <!-- =====================================================
                 NOTE
            ====================================================== -->
            <div class="followup-footnote">
                <div class="footnote-icon" aria-hidden="true">
                    i
                </div>

                <div>
                    <span class="footnote-title">
                        À propos de ce suivi
                    </span>

                    <p>
                        Ni assureur ni montant ne figurent ici :
                        l'état d'un mois est tout ce que le réseau
                        voit d'une officine.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ================================================================
   PALETTE — MÊME UNIVERS QUE LE JOURNAL DES PÉNALITÉS
================================================================ */

.followup-page {
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

    /* padding: 0 10px 60px; */
    /* background: var(--page-bg); */
    color: var(--ink);
    font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont,
        "Segoe UI", sans-serif;
}

/* ================================================================
   INTRO
================================================================ */

.followup-intro {
    position: relative;
    overflow: hidden;
    margin-bottom: 22px;
    padding: 26px 28px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background:
        linear-gradient(
            135deg,
            rgba(255, 255, 255, 0.98),
            rgba(247, 252, 249, 0.98)
        );
    /* box-shadow:
        0 10px 28px rgba(30, 60, 48, 0.045),
        0 2px 6px rgba(30, 60, 48, 0.025); */
    isolation: isolate;
}

.followup-intro::before {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    left: 0;
    height: 3px;
    background: linear-gradient(
        90deg,
        var(--primary),
        #2b8c70 55%,
        var(--gold)
    );
}

.intro-main {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
}

.intro-content {
    display: flex;
    align-items: center;
    min-width: 0;
    gap: 17px;
}

.intro-icon {
    display: flex;
    flex: 0 0 50px;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(
        145deg,
        var(--primary),
        var(--primary-dark)
    );
    /* box-shadow: 0 8px 18px rgba(0, 102, 76, 0.16); */
}

.intro-icon svg {
    width: 25px;
    height: 25px;
}

.intro-text {
    min-width: 0;
}

.intro-eyebrow,
.section-eyebrow {
    display: block;
    margin-bottom: 5px;
    color: var(--primary);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.14em;
    line-height: 1.3;
}

.intro-text h1 {
    margin: 0;
    color: var(--ink);
    font-size: 21px;
    font-weight: 750;
    letter-spacing: -0.025em;
    line-height: 1.25;
}

.intro-description {
    max-width: 590px;
    margin: 5px 0 8px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
}

.intro-period {
    display: flex;
    align-items: center;
    gap: 7px;
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
}

.period-dot,
.status-dot {
    display: inline-block;
    flex: 0 0 6px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--primary);
    /* box-shadow: 0 0 0 3px rgba(0, 102, 76, 0.08); */
}

/* ================================================================
   DÉCORATIONS
================================================================ */

.intro-decoration {
    position: absolute;
    z-index: 0;
    pointer-events: none;
    border: 1px solid rgba(0, 102, 76, 0.05);
    border-radius: 50%;
}

.intro-decoration-one {
    top: -90px;
    right: 17%;
    width: 190px;
    height: 190px;
}

.intro-decoration-two {
    right: -65px;
    bottom: -95px;
    width: 190px;
    height: 190px;
    border-color: rgba(176, 138, 69, 0.08);
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
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.filter-heading-line {
    width: 15px;
    height: 1px;
    background: var(--gold);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(110px, 1fr));
    gap: 9px;
}

.filter-item {
    min-width: 0;
}

/* ================================================================
   BODY
================================================================ */

.followup-body {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

/* ================================================================
   TABLE SECTION
================================================================ */

.followup-table-section {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: var(--surface);
    /* box-shadow:
        0 10px 28px rgba(30, 60, 48, 0.04),
        0 2px 5px rgba(30, 60, 48, 0.025); */
}

.section-top-line {
    position: absolute;
    top: 0;
    right: 0;
    left: 0;
    height: 3px;
    background: linear-gradient(
        90deg,
        var(--primary),
        #2b8c70 60%,
        var(--gold)
    );
}

.table-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    padding: 25px 25px 18px;
}

.table-heading-content h2 {
    margin: 0;
    color: var(--ink);
    font-size: 18px;
    font-weight: 750;
    letter-spacing: -0.02em;
}

.table-heading-content p {
    max-width: 650px;
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.6;
}

.table-status {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 8px;
    padding: 7px 11px;
    border: 1px solid rgba(0, 102, 76, 0.1);
    border-radius: 999px;
    color: var(--primary);
    background: var(--primary-soft);
    font-size: 10px;
    font-weight: 750;
    white-space: nowrap;
}

/* ================================================================
   TABLE
================================================================ */

.followup-table {
    border-top: 1px solid rgba(229, 235, 232, 0.8);
}

/* ================================================================
   NOM PHARMACIE
================================================================ */

.name-cell {
    display: flex;
    align-items: center;
    min-width: 0;
    gap: 10px;
}

.pharmacy-avatar {
    display: flex;
    flex: 0 0 34px;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border: 1px solid rgba(0, 102, 76, 0.08);
    border-radius: 10px;
    color: var(--primary);
    background: var(--primary-soft);
    font-size: 12px;
    font-weight: 800;
}

.name-content {
    min-width: 0;
}

.name {
    overflow: hidden;
    color: var(--ink);
    font-size: 13px;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.name-caption {
    display: block;
    margin-top: 2px;
    color: var(--muted-light);
    font-size: 13px;
    font-weight: 600;
}

/* ================================================================
   VILLE
================================================================ */

.city-cell {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--ink);
    font-size: 13px;
}

.city-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
}

.city-icon svg {
    width: 14px;
    height: 14px;
}

/* ================================================================
   ÉTAT
================================================================ */

.state-cell {
    display: flex;
    align-items: center;
}

/* ================================================================
   ACTIONS
================================================================ */

.phone-link,
.whatsapp-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 8px;
    text-decoration: none;
    transition:
        color 0.18s ease,
        background 0.18s ease,
        transform 0.18s ease;
}

.phone-link {
    color: var(--primary);
    font-weight: 650;
}

.whatsapp-link {
    padding: 5px 8px;
    color: var(--primary);
    background: var(--primary-soft);
    font-size: 13px;
    font-weight: 700;
}

.phone-link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
    text-underline-offset: 3px;
}

.whatsapp-link:hover {
    background: #e4f2ec;
    transform: translateY(-1px);
}

.action-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 7px;
}

.phone-icon {
    color: var(--primary);
    background: var(--primary-soft);
}

.whatsapp-icon {
    color: var(--primary);
}

.muted-cell {
    color: var(--muted-light);
    font-size: 10px;
}

/* ================================================================
   EMPTY STATE
================================================================ */

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 230px;
    padding: 35px 20px;
    text-align: center;
}

.empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    margin-bottom: 12px;
    border: 1px solid var(--border);
    border-radius: 14px;
    color: var(--muted);
    background: var(--surface-soft);
}

.empty-icon svg {
    width: 22px;
    height: 22px;
}

.empty-title {
    margin-bottom: 4px;
    color: var(--ink);
    font-size: 13px;
    font-weight: 750;
}

.empty-state p {
    max-width: 390px;
    margin: 0;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.6;
}

/* ================================================================
   PAGINATION
================================================================ */

.pagination-wrapper {
    padding: 2px 4px 0;
}

.pagination {
    margin-top: 0;
}

/* ================================================================
   FOOTNOTE
================================================================ */

.followup-footnote {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px 15px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.72);
}

.footnote-icon {
    display: flex;
    flex: 0 0 22px;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border: 1px solid rgba(176, 138, 69, 0.2);
    border-radius: 7px;
    color: var(--gold);
    background: var(--gold-soft);
    font-size: 11px;
    font-weight: 800;
}

.footnote-title {
    display: block;
    margin-bottom: 2px;
    color: var(--ink);
    font-size: 13px;
    font-weight: 750;
}

.followup-footnote p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.55;
}

/* ================================================================
   ANIMATIONS
================================================================ */

.followup-intro {
    animation: introAppear 0.45s ease both;
}

.followup-table-section {
    animation: fadeUp 0.5s ease 0.06s both;
}

.followup-footnote {
    animation: fadeUp 0.5s ease 0.12s both;
}

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
        transform: translateY(8px);
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
        max-width: 620px;
    }
}

/* ================================================================
   RESPONSIVE — 850px
================================================================ */

@media (max-width: 850px) {
    .followup-page {
        padding-right: 7px;
        padding-left: 7px;
    }

    .followup-intro {
        padding: 23px 21px;
        border-radius: 17px;
    }

    .table-heading {
        padding: 22px 20px 16px;
    }

    .table-status {
        display: none;
    }
}

/* ================================================================
   RESPONSIVE — 700px
================================================================ */

@media (max-width: 700px) {
    .followup-intro {
        margin-bottom: 16px;
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
        font-size: 11px;
    }

    .filters-grid {
        grid-template-columns: 1fr;
        max-width: none;
    }

    .filter-item {
        width: 100%;
    }

    .followup-table-section {
        border-radius: 17px;
    }

    .table-heading-content h2 {
        font-size: 16px;
    }
}

/* ================================================================
   RESPONSIVE — 480px
================================================================ */

@media (max-width: 480px) {
    .followup-page {
        padding: 0 4px 40px;
    }

    .followup-intro {
        padding: 20px 16px;
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
        margin-top: 4px;
        font-size: 10px;
    }

    .intro-period {
        font-size: 9px;
    }

    .table-heading {
        padding: 19px 15px 14px;
    }

    .table-heading-content p {
        font-size: 10px;
    }

    .name {
        max-width: 145px;
    }

    .pharmacy-avatar {
        flex-basis: 30px;
        width: 30px;
        height: 30px;
    }

    .name-caption {
        display: none;
    }

    .followup-footnote {
        padding: 11px 12px;
    }
}
</style>