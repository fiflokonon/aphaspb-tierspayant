<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import Pagination from '@/components/aphaspb/Pagination.vue';
import StatusChip from '@/components/aphaspb/StatusChip.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import type { DeclarationStatus } from '@/types/aphaspb';

type Row = {
    id: number;
    insurerName: string;
    year: number;
    month: number;
    monthLabel: string;
    status: DeclarationStatus;
    statusLabel: string;
    invoiced: string;
    received: string;
    outstanding: string | null;
    delayDays: number | null;
    instalments: number;
    corrections: number;
    privateNote: string | null;
    editUrl: string;
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
    declarations: Paginated<Row>;
    insurers: { id: number; name: string }[];
    years: number[];
    filters: { insurer: number | null; year: number | null; perPage: number };
    pageSizes: number[];
}>();

const TEMPLATE = '1.6fr .8fr .9fr 1fr 1fr 1fr .7fr 1.4fr .8fr';

const COLUMNS = [
    'ASSUREUR',
    'MOIS',
    'STATUT',
    'FACTURÉ',
    'REÇU',
    'RESTE DÛ',
    'DÉLAI',
    'NOTE PRIVÉE',
    'ACTION',
];

const insurerOptions = computed(() => [
    { value: null, label: 'Tous les assureurs' },
    ...props.insurers.map((one) => ({ value: one.id, label: one.name })),
]);

const yearOptions = computed(() => [
    { value: null, label: 'Toutes les années' },
    ...props.years.map((year) => ({ value: year, label: String(year) })),
]);

const insurer = ref<number | null>(props.filters.insurer);
const year = ref<number | null>(props.filters.year);
const perPage = ref(props.filters.perPage);

/** Filters and paging both reload only the list, never the whole page. */
function reload(page: number) {
    router.get(
        '/pharmacy/history',
        {
            insurer: insurer.value,
            year: year.value,
            per_page: perPage.value,
            page,
        },
        {
            only: ['declarations', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

// Changing a filter returns to page one: staying on page seven of a list that
// now holds four rows would show an empty table. Widening the page size has the
// same effect, so it goes through the same reset.
watch([insurer, year, perPage], () => reload(1));

const footer = computed(
    () =>
        `${props.declarations.total} déclaration${props.declarations.total > 1 ? 's' : ''} · l'export CSV est réservé à l'APhaSPB`,
);
</script>

<template>
    <Head title="Historique" />

    <div class="history-page">
        <ConsoleHeader title="Historique" class="history-header">
            <template #filters>
                <div class="history-filters">
                    <div class="filter-wrapper">
                        <FilterSelect
                            v-model="insurer"
                            :options="insurerOptions"
                            aria-label="Filtrer par assureur"
                        />
                    </div>

                    <div class="filter-wrapper">
                        <FilterSelect
                            v-model="year"
                            :options="yearOptions"
                            aria-label="Filtrer par année"
                        />
                    </div>
                </div>
            </template>
        </ConsoleHeader>

        <section class="history-table-section">
            <div class="table-top-line"></div>

            <div class="table-heading">
                <div>
                    <span class="table-kicker">
                        REGISTRE DES DÉCLARATIONS
                    </span>

                    <h2>Toutes mes déclarations</h2>

                    <p>Consultez et modifiez vos déclarations enregistrées.</p>
                </div>

                <div class="declaration-count">
                    <span class="count-number">
                        {{ declarations.total }}
                    </span>

                    <span class="count-label">
                        déclaration{{ declarations.total > 1 ? 's' : '' }}
                    </span>
                </div>
            </div>

            <div class="table-filter-bar">
                <div class="filter-status">
                    <span class="status-indicator"></span>
                    <span> Historique actualisé </span>
                </div>

                <div class="filter-summary">
                    {{ declarations.from ?? 0 }}
                    –
                    {{ declarations.to ?? 0 }}
                    sur
                    {{ declarations.total }}
                </div>
            </div>

            <div class="history-table-wrapper">
                <DataTable
                    title=""
                    :columns="COLUMNS"
                    :template="TEMPLATE"
                    :footer="footer"
                    class="history-table"
                >
                    <DataTableRow
                        v-for="(row, index) in declarations.data"
                        :key="row.id"
                        :template="TEMPLATE"
                        class="history-row"
                        :style="{
                            '--row-delay': `${Math.min(index * 45, 360)}ms`,
                        }"
                    >
                        <div class="insurer-cell">
                            <div class="insurer-avatar">
                                {{ row.insurerName?.charAt(0)?.toUpperCase() }}
                            </div>

                            <div class="insurer-info">
                                <span class="insurer-name">
                                    {{ row.insurerName }}
                                </span>

                                <span class="insurer-label"> Assureur </span>
                            </div>
                        </div>

                        <div class="month-cell">
                            <span class="month-main">
                                {{ row.monthLabel }}
                            </span>
                        </div>

                        <div class="status-cell">
                            <StatusChip
                                :status="row.status"
                                :label="row.statusLabel"
                            />
                        </div>

                        <div class="amount-cell">
                            <span>
                                {{ row.invoiced }}
                            </span>
                        </div>

                        <div class="amount-cell received">
                            <span>
                                {{ row.received }}
                            </span>
                        </div>

                        <div
                            class="amount-cell outstanding"
                            :class="
                                row.outstanding
                                    ? 'has-outstanding'
                                    : 'empty-outstanding'
                            "
                        >
                            <span>
                                {{ row.outstanding ?? '—' }}
                            </span>
                        </div>

                        <div class="delay-cell">
                            <span
                                v-if="row.delayDays !== null"
                                class="delay-value"
                            >
                                {{ row.delayDays }}
                            </span>

                            <span
                                v-if="row.delayDays !== null"
                                class="delay-unit"
                            >
                                j
                            </span>

                            <span v-else class="delay-empty"> — </span>

                            <!--
                                Le délai se compte jusqu'au dernier versement :
                                sans ce repère, un mois réglé en trois fois
                                affiche un chiffre qu'aucune date du relevé ne
                                justifie.
                            -->
                            <span
                                v-if="row.instalments > 1"
                                class="delay-instalments"
                                :title="`Réglée en ${row.instalments} versements`"
                            >
                                ×{{ row.instalments }}
                            </span>
                        </div>

                        <div class="note-cell">
                            <span
                                v-if="row.privateNote"
                                class="note-content"
                                :title="row.privateNote"
                            >
                                <span class="note-icon">✦</span>
                                {{ row.privateNote }}
                            </span>

                            <span v-else class="note-empty"> — </span>
                        </div>

                        <div class="action-cell">
                            <span
                                v-if="row.corrections > 0"
                                class="corrections-badge"
                                :title="`Modifiée ${row.corrections} fois depuis sa déclaration initiale`"
                            >
                                {{ row.corrections }} corr.
                            </span>

                            <Link :href="row.editUrl" class="edit-link">
                                <span> Modifier </span>

                                <span class="edit-arrow"> → </span>
                            </Link>
                        </div>
                    </DataTableRow>

                    <div v-if="!declarations.data.length" class="empty-state">
                        <div class="empty-icon">◌</div>

                        <div class="empty-content">
                            <h3>Aucune déclaration trouvée</h3>

                            <p>
                                Aucune déclaration ne correspond aux filtres
                                sélectionnés.
                            </p>
                        </div>
                    </div>
                </DataTable>
            </div>
        </section>

        <div class="history-pagination">
            <Pagination
                :page="declarations.current_page"
                :last-page="declarations.last_page"
                :from="declarations.from"
                :to="declarations.to"
                :total="declarations.total"
                noun="déclaration"
                :per-page="filters.perPage"
                :page-sizes="pageSizes"
                @update:page="reload"
                @update:per-page="perPage = $event"
            />
        </div>

        <div class="history-footnote">
            <div class="footnote-icon">
                <span>i</span>
            </div>

            <div class="footnote-content">
                <span class="footnote-title"> Confidentialité </span>

                <p>
                    Vos notes privées n'apparaissent que sur cet écran.
                    L'APhaSPB ne les reçoit jamais.
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.history-page {
    /* La palette vient de :root — voir resources/css/app.css. */

    position: relative;
    min-height: 100vh;

    padding-bottom: 60px;
}

.history-header {
    position: relative;
    z-index: 5;
}

.history-filters {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-wrapper {
    position: relative;

    border-radius: 11px;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.filter-wrapper:hover {
    transform: translateY(-1px);

    box-shadow: var(--surface-shadow-raised);
}

.history-table-section {
    /*
      Les 22 px que portait le panneau d'introduction supprimé. Sans eux, le
      liseré d'accent de 3 px vient se coller sous le titre serif et se lit
      comme un soulignement mal posé — 1 px d'écart sur l'historique, la jambe
      du « q » touchait le trait. Même valeur que .exports-page, la référence.
    */
    margin-top: 22px;

    position: relative;

    overflow: hidden;

    border-radius: var(--radius-card);

    background: var(--card);

    box-shadow: var(--surface-shadow);

    animation: tableAppear 0.65s ease both;
}

.table-top-line {
    position: absolute;

    left: 0;
    top: 0;

    width: 100%;
    height: 3px;

    background: var(--primary);

    opacity: 0.9;
}

.table-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;

    padding: 22px 22px 17px;

    border-bottom: 1px solid color-mix(in srgb, var(--ink) 7%, transparent);
}

.table-kicker {
    display: block;

    margin-bottom: 4px;

    color: var(--primary);

    font-size: 9.5px;
    font-weight: 800;

    letter-spacing: 0.14em;
}

.table-heading h2 {
    color: var(--ink);

    font-size: 17px;
    font-weight: 750;

    letter-spacing: -0.02em;
}

.table-heading p {
    margin-top: 4px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;
}

.declaration-count {
    display: flex;
    align-items: baseline;

    gap: 6px;

    padding: 8px 12px;

    border: 1px solid color-mix(in srgb, var(--officine) 10%, transparent);

    border-radius: 11px;

    background: var(--cream-state);

    white-space: nowrap;
}

.count-number {
    color: var(--primary-dark);

    font-size: 16px;
    font-weight: 800;
}

.count-label {
    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;
    font-weight: 600;
}

.table-filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    min-height: 39px;

    padding: 0 22px;

    border-bottom: 1px solid color-mix(in srgb, var(--ink) 5.5%, transparent);

    background: color-mix(in srgb, var(--cream-state) 65%, transparent);
}

.filter-status {
    display: flex;
    align-items: center;

    gap: 7px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;
    font-weight: 600;
}

.status-indicator {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: var(--primary);

    box-shadow: 0 0 0 3px color-mix(in srgb, var(--officine) 8%, transparent);
}

.filter-summary {
    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-family: monospace;

    font-size: 12.5px;
}

.history-table-wrapper {
    width: 100%;

    overflow-x: auto;

    scrollbar-width: thin;
    scrollbar-color: color-mix(in srgb, var(--officine) 20%, transparent)
        transparent;
}

.history-table-wrapper::-webkit-scrollbar {
    height: 5px;
}

.history-table-wrapper::-webkit-scrollbar-track {
    background: transparent;
}

.history-table-wrapper::-webkit-scrollbar-thumb {
    border-radius: 20px;

    background: color-mix(in srgb, var(--officine) 20%, transparent);
}

.history-table {
    min-width: 1080px;
}

.history-row {
    animation: rowAppear 0.45s ease both;

    animation-delay: var(--row-delay);

    transition:
        background 0.25s ease,
        transform 0.25s ease;
}

.history-row:hover {
    background: var(--primary-soft);
}

.insurer-cell {
    display: flex;
    align-items: center;

    gap: 9px;

    min-width: 150px;
}

.insurer-avatar {
    width: 32px;
    height: 32px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 1px solid color-mix(in srgb, var(--officine) 10%, transparent);

    border-radius: 10px;

    background: var(--primary-soft);

    color: var(--primary-dark);

    font-size: 10px;
    font-weight: 800;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.history-row:hover .insurer-avatar {
    transform: scale(1.08) rotate(2deg);

    box-shadow: 0 5px 13px color-mix(in srgb, var(--officine) 10%, transparent);
}

.insurer-info {
    min-width: 0;

    display: flex;
    flex-direction: column;

    gap: 2px;
}

.insurer-name {
    overflow: hidden;

    color: var(--ink);

    font-size: 11.5px;
    font-weight: 700;

    text-overflow: ellipsis;
    white-space: nowrap;
}

.insurer-label {
    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-size: 12.5px;
}

.month-cell {
    color: var(--ink);

    font-size: 11px;
    font-weight: 650;
}

.month-main {
    white-space: nowrap;
}

.status-cell {
    white-space: nowrap;
}

.amount-cell {
    color: var(--ink);

    font-family: monospace;

    font-size: 11px;
    font-weight: 650;

    white-space: nowrap;
}

.amount-cell.received {
    color: var(--primary-dark);
}

.amount-cell.outstanding {
    transition: transform 0.2s ease;
}

.amount-cell.outstanding.has-outstanding {
    color: var(--terracotta);

    font-weight: 750;
}

.amount-cell.outstanding.empty-outstanding {
    color: color-mix(in srgb, var(--ink) 38%, transparent);
}

.history-row:hover .amount-cell.outstanding.has-outstanding {
    transform: translateX(2px);
}

.delay-cell {
    display: flex;
    align-items: baseline;

    gap: 3px;

    white-space: nowrap;
}

.delay-value {
    color: var(--ink);

    font-weight: 750;
}

.delay-unit {
    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-size: 12.5px;
}

.delay-empty {
    color: color-mix(in srgb, var(--ink) 38%, transparent);
}

.delay-instalments {
    margin-left: 2px;

    padding: 1px 4px;

    border-radius: 4px;

    background: var(--primary-soft);

    font-size: 12.5px;

    font-weight: 700;

    color: var(--primary-dark);
}

.corrections-badge {
    margin-right: 8px;

    padding: 1px 6px;

    border-radius: 999px;

    background: var(--gold-soft);

    font-size: 12.5px;

    font-weight: 700;

    color: var(--gold-ink);

    white-space: nowrap;
}

.note-cell {
    max-width: 190px;

    overflow: hidden;
}

.note-content {
    display: flex;
    align-items: center;

    gap: 5px;

    overflow: hidden;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 10px;

    text-overflow: ellipsis;
    white-space: nowrap;
}

.note-icon {
    flex-shrink: 0;

    color: var(--gold-mid);

    font-size: 12.5px;
}

.note-empty {
    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-size: 10px;
}

.action-cell {
    white-space: nowrap;
}

.edit-link {
    display: inline-flex;
    align-items: center;

    gap: 5px;

    padding: 6px 8px;

    border-radius: 8px;

    color: var(--primary-dark);

    font-size: 10.5px;
    font-weight: 750;

    text-decoration: none;

    transition:
        background 0.2s ease,
        color 0.2s ease,
        gap 0.2s ease,
        transform 0.2s ease;
}

.edit-link:hover {
    gap: 8px;

    color: var(--primary-dark);

    background: var(--primary-soft);

    transform: translateX(1px);
}

.edit-arrow {
    color: var(--primary);

    font-size: 13px;

    transition: transform 0.2s ease;
}

.edit-link:hover .edit-arrow {
    transform: translateX(2px);
}

.empty-state {
    display: flex;
    align-items: center;
    justify-content: center;

    gap: 14px;

    padding: 45px 20px;

    border-top: 1px solid color-mix(in srgb, var(--ink) 5.5%, transparent);

    background: #fff;

    animation: emptyAppear 0.5s ease both;
}

.empty-icon {
    width: 43px;
    height: 43px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 13px;

    background: var(--primary-soft);

    color: var(--primary);

    font-size: 22px;
}

.empty-content h3 {
    color: var(--ink);

    font-size: 12.5px;
    font-weight: 750;
}

.empty-content p {
    margin-top: 3px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;
}

.history-pagination {
    margin-top: 16px;

    animation: paginationAppear 0.55s ease both;
}

.history-footnote {
    display: flex;
    align-items: flex-start;

    gap: 9px;

    margin-top: 14px;

    padding: 12px 15px;

    border-radius: var(--radius-card);

    box-shadow: var(--surface-shadow);

    background: color-mix(in srgb, var(--officine) 3.5%, transparent);
}

.footnote-icon {
    width: 19px;
    height: 19px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: var(--primary);

    color: #ffffff;

    font-size: 10px;
    font-weight: 800;
}

.footnote-content {
    display: flex;
    flex-direction: column;

    gap: 2px;
}

.footnote-title {
    color: var(--primary-dark);

    font-size: 12.5px;
    font-weight: 750;
}

.footnote-content p {
    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;
    line-height: 1.45;
}

@keyframes tableAppear {
    from {
        opacity: 0;
        transform: translateY(15px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes rowAppear {
    from {
        opacity: 0;
        transform: translateY(6px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes paginationAppear {
    from {
        opacity: 0;
        transform: translateY(7px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes emptyAppear {
    from {
        opacity: 0;
        transform: scale(0.98);
    }

    to {
        opacity: 1;
        transform: scale(1);
    }
}

@media (max-width: 900px) {
    .table-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .declaration-count {
        align-self: flex-start;
    }
}

@media (max-width: 760px) {
    .history-filters {
        width: 100%;

        overflow-x: auto;

        padding-bottom: 3px;

        scrollbar-width: none;
    }

    .history-filters::-webkit-scrollbar {
        display: none;
    }

    .table-heading {
        padding: 18px 17px 15px;
    }

    .table-filter-bar {
        padding: 0 17px;
    }

    .history-footnote {
        margin-bottom: 20px;
    }
}

@media (max-width: 640px) {
    .history-page {
        padding-bottom: 80px;
    }

    .table-filter-bar {
        min-height: 36px;
    }

    .empty-state {
        flex-direction: column;

        text-align: center;

        padding: 38px 18px;
    }
}

@media (max-width: 400px) {
    .table-heading {
        padding-left: 14px;
        padding-right: 14px;
    }

    .table-filter-bar {
        padding-left: 14px;
        padding-right: 14px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .history-page *,
    .history-page *::before,
    .history-page *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
