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
    filters: { insurer: number | null; year: number | null };
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

/** Filters and paging both reload only the list, never the whole page. */
function reload(page: number) {
    router.get(
        '/pharmacy/history',
        { insurer: insurer.value, year: year.value, page },
        {
            only: ['declarations', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

// Changing a filter returns to page one: staying on page seven of a list that
// now holds four rows would show an empty table.
watch([insurer, year], () => reload(1));

const footer = computed(
    () =>
        `${props.declarations.total} déclaration${props.declarations.total > 1 ? 's' : ''} · l'export CSV est réservé à l'APhaSPB`,
);
</script>



<template>
    <Head title="Historique" />

    <div class="history-page">
 
        
        <ConsoleHeader
            eyebrow="MES DÉCLARATIONS"
            title="Historique"
            class="history-header"
        >
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

        
        <section class="history-intro">
            <div class="intro-main">
                <div class="intro-icon">
                    <span class="icon-history">↺</span>
                </div>

                <div class="intro-text">
                    <span class="intro-eyebrow">
                        SUIVI ADMINISTRATIF
                    </span>

                    <h1>
                        Vos déclarations
                    </h1>

                    <p>
                        Retrouvez l'ensemble de vos déclarations mensuelles,
                        leurs statuts et les montants enregistrés.
                    </p>
                </div>
            </div>

            <div class="intro-badge">
                <span class="badge-dot"></span>
                <span>
                    Données personnelles
                </span>
            </div>
        </section>

        <section class="history-table-section">
            <div class="table-top-line"></div>

            <div class="table-heading">
                <div>
                    <span class="table-kicker">
                        REGISTRE DES DÉCLARATIONS
                    </span>

                    <h2>
                        Toutes mes déclarations
                    </h2>

                    <p>
                        Consultez et modifiez vos déclarations enregistrées.
                    </p>
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
                    <span>
                        Historique actualisé
                    </span>
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
                                {{
                                    row.insurerName
                                        ?.charAt(0)
                                        ?.toUpperCase()
                                }}
                            </div>

                            <div class="insurer-info">
                                <span class="insurer-name">
                                    {{ row.insurerName }}
                                </span>

                                <span class="insurer-label">
                                    Assureur
                                </span>
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

                            <span
                                v-else
                                class="delay-empty"
                            >
                                —
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

                            <span
                                v-else
                                class="note-empty"
                            >
                                —
                            </span>
                        </div>

                        <div class="action-cell">
                            <Link
                                :href="row.editUrl"
                                class="edit-link"
                            >
                                <span>
                                    Modifier
                                </span>

                                <span class="edit-arrow">
                                    →
                                </span>
                            </Link>
                        </div>
                    </DataTableRow>

                    <div
                        v-if="!declarations.data.length"
                        class="empty-state"
                    >
                        <div class="empty-icon">
                            ◌
                        </div>

                        <div class="empty-content">
                            <h3>
                                Aucune déclaration trouvée
                            </h3>

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
                @update:page="reload"
            />
        </div>

        <div class="history-footnote">
            <div class="footnote-icon">
                <span>i</span>
            </div>

            <div class="footnote-content">
                <span class="footnote-title">
                    Confidentialité
                </span>

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
    --apha-primary: #008f83;
    --apha-primary-dark: #006f68;
    --apha-primary-soft: #e8f6f3;

    --apha-gold: #d7a33d;
    --apha-gold-dark: #ae7d20;
    --apha-gold-soft: #fff8e9;

    --apha-ink: #243333;
    --apha-muted: #788585;
    --apha-light: #a2adad;

    --apha-border: #e5ecea;
    --apha-background: #f7f9f9;
    --apha-card: #ffffff;

    position: relative;
    min-height: 100vh;

    padding-bottom: 60px;

    /* background:
        radial-gradient(
            circle at 95% 0%,
            rgba(0, 143, 131, 0.055),
            transparent 28%
        ),
        radial-gradient(
            circle at 0% 35%,
            rgba(215, 163, 61, 0.025),
            transparent 24%
        ),
        var(--apha-background); */
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

    box-shadow:
        0 7px 18px rgba(35, 70, 68, 0.06);
}



.history-intro {
    position: relative;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 24px;

    margin-top: 12px;
    margin-bottom: 24px;

    padding: 21px 23px;

    overflow: hidden;

    border:
        1px solid
        var(--apha-border);

    border-radius: 18px;

    background:
        linear-gradient(
            110deg,
            #ffffff 0%,
            #f9fcfb 100%
        );

    /* box-shadow:
        0 9px 30px rgba(35, 70, 68, 0.035); */

    animation:
        historyIntroAppear 0.55s ease both;
}

.history-intro::before {
    content: "";

    position: absolute;

    right: -65px;
    top: -90px;

    width: 220px;
    height: 220px;

    border-radius: 50%;

    background:
        radial-gradient(
            circle,
            rgba(0, 143, 131, 0.09),
            transparent 68%
        );

    pointer-events: none;
}

.history-intro::after {
    content: "";

    position: absolute;

    left: 0;
    bottom: 0;

    width: 100%;
    height: 2px;

    background:
        linear-gradient(
            90deg,
            transparent,
            rgba(0, 143, 131, 0.25),
            transparent
        );

    opacity: 0.5;
}




.intro-main {
    position: relative;
    z-index: 1;

    display: flex;
    align-items: center;

    gap: 15px;
}

.intro-icon {
    width: 49px;
    height: 49px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 14px;

    color: #ffffff;

    background:
        linear-gradient(
            135deg,
            var(--apha-primary),
            var(--apha-primary-dark)
        );

    /* box-shadow:
        0 8px 20px rgba(0, 143, 131, 0.17); */

    animation:
        iconFloat 3s ease-in-out infinite;
}

.icon-history {
    display: block;

    font-size: 23px;
    line-height: 1;

    transform: rotate(-20deg);
}

.intro-text {
    display: flex;
    flex-direction: column;
}

.intro-eyebrow {
    margin-bottom: 3px;

    color: var(--apha-primary);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: 0.13em;
}

.intro-text h1 {
    color: var(--apha-ink);

    font-size: 20px;
    font-weight: 750;

    letter-spacing: -0.025em;
}

.intro-text p {
    margin-top: 4px;

    color: var(--apha-muted);

    font-size: 11px;
    line-height: 1.45;
}




.intro-badge {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;

    gap: 8px;

    padding: 8px 12px;

    border-radius: 30px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);

    font-size: 10px;
    font-weight: 700;

    white-space: nowrap;
}

.badge-dot {
    width: 7px;
    height: 7px;

    border-radius: 50%;

    background: var(--apha-primary);

    box-shadow:
        0 0 0 4px rgba(0, 143, 131, 0.08);

    animation:
        badgePulse 2.2s infinite;
}




.history-table-section {
    position: relative;

    overflow: hidden;

    border:
        1px solid
        var(--apha-border);

    border-radius: 18px;

    background: var(--apha-card);

    box-shadow:
        0 9px 32px rgba(35, 70, 68, 0.04);

    animation:
        tableAppear 0.65s ease both;
}

.table-top-line {
    position: absolute;

    left: 0;
    top: 0;

    width: 100%;
    height: 3px;

    background:
        linear-gradient(
            90deg,
            var(--apha-primary),
            #35a799,
            var(--apha-gold)
        );

    opacity: 0.9;
}


.table-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;

    padding: 22px 22px 17px;

    border-bottom:
        1px solid
        rgba(35, 70, 68, 0.07);
}

.table-kicker {
    display: block;

    margin-bottom: 4px;

    color: var(--apha-primary);

    font-size: 8.5px;
    font-weight: 800;

    letter-spacing: 0.13em;
}

.table-heading h2 {
    color: var(--apha-ink);

    font-size: 17px;
    font-weight: 750;

    letter-spacing: -0.02em;
}

.table-heading p {
    margin-top: 4px;

    color: var(--apha-muted);

    font-size: 10.5px;
}



.declaration-count {
    display: flex;
    align-items: baseline;

    gap: 6px;

    padding: 8px 12px;

    border:
        1px solid
        rgba(0, 143, 131, 0.1);

    border-radius: 11px;

    background:
        linear-gradient(
            135deg,
            #f5fbf9,
            #ffffff
        );

    white-space: nowrap;
}

.count-number {
    color: var(--apha-primary-dark);

    font-size: 16px;
    font-weight: 800;
}

.count-label {
    color: var(--apha-muted);

    font-size: 9px;
    font-weight: 600;
}



.table-filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    min-height: 39px;

    padding: 0 22px;

    border-bottom:
        1px solid
        rgba(35, 70, 68, 0.055);

    background:
        rgba(248, 251, 250, 0.65);
}

.filter-status {
    display: flex;
    align-items: center;

    gap: 7px;

    color: var(--apha-muted);

    font-size: 9.5px;
    font-weight: 600;
}

.status-indicator {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: var(--apha-primary);

    box-shadow:
        0 0 0 3px rgba(0, 143, 131, 0.08);
}

.filter-summary {
    color: var(--apha-light);

    font-family: monospace;

    font-size: 9px;
}



.history-table-wrapper {
    width: 100%;

    overflow-x: auto;

    scrollbar-width: thin;
    scrollbar-color:
        rgba(0, 143, 131, 0.2)
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

    background:
        rgba(0, 143, 131, 0.2);
}



.history-table {
    min-width: 1080px;
}


.history-row {
    animation:
        rowAppear 0.45s ease both;

    animation-delay:
        var(--row-delay);

    transition:
        background 0.25s ease,
        transform 0.25s ease;
}

.history-row:hover {
    background:
        linear-gradient(
            90deg,
            rgba(0, 143, 131, 0.025),
            rgba(0, 143, 131, 0.008)
        );
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

    border:
        1px solid
        rgba(0, 143, 131, 0.1);

    border-radius: 10px;

    background:
        linear-gradient(
            135deg,
            #e5f6f2,
            #f4faf9
        );

    color: var(--apha-primary-dark);

    font-size: 10px;
    font-weight: 800;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.history-row:hover .insurer-avatar {
    transform:
        scale(1.08)
        rotate(2deg);

    box-shadow:
        0 5px 13px rgba(0, 143, 131, 0.1);
}

.insurer-info {
    min-width: 0;

    display: flex;
    flex-direction: column;

    gap: 2px;
}

.insurer-name {
    overflow: hidden;

    color: var(--apha-ink);

    font-size: 11.5px;
    font-weight: 700;

    text-overflow: ellipsis;
    white-space: nowrap;
}

.insurer-label {
    color: var(--apha-light);

    font-size: 8.5px;
}



.month-cell {
    color: var(--apha-ink);

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
    color: var(--apha-ink);

    font-family: monospace;

    font-size: 11px;
    font-weight: 650;

    white-space: nowrap;
}

.amount-cell.received {
    color: var(--apha-primary-dark);
}

.amount-cell.outstanding {
    transition:
        transform 0.2s ease;
}

.amount-cell.outstanding.has-outstanding {
    color: #c55245;

    font-weight: 750;
}

.amount-cell.outstanding.empty-outstanding {
    color: var(--apha-light);
}

.history-row:hover .amount-cell.outstanding.has-outstanding {
    transform:
        translateX(2px);
}



.delay-cell {
    display: flex;
    align-items: baseline;

    gap: 3px;

    white-space: nowrap;
}

.delay-value {
    color: var(--apha-ink);

    font-weight: 750;
}

.delay-unit {
    color: var(--apha-light);

    font-size: 9px;
}

.delay-empty {
    color: var(--apha-light);
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

    color: var(--apha-muted);

    font-size: 10px;

    text-overflow: ellipsis;
    white-space: nowrap;
}

.note-icon {
    flex-shrink: 0;

    color: var(--apha-gold);

    font-size: 9px;
}

.note-empty {
    color: var(--apha-light);

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

    color: var(--apha-primary-dark);

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

    color: var(--apha-primary-dark);

    background:
        var(--apha-primary-soft);

    transform:
        translateX(1px);
}

.edit-arrow {
    color: var(--apha-primary);

    font-size: 13px;

    transition:
        transform 0.2s ease;
}

.edit-link:hover .edit-arrow {
    transform:
        translateX(2px);
}



.empty-state {
    display: flex;
    align-items: center;
    justify-content: center;

    gap: 14px;

    padding: 45px 20px;

    border-top:
        1px solid
        rgba(35, 70, 68, 0.055);

    background:
        linear-gradient(
            135deg,
            #ffffff,
            #f9fcfb
        );

    animation:
        emptyAppear 0.5s ease both;
}

.empty-icon {
    width: 43px;
    height: 43px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 13px;

    background:
        var(--apha-primary-soft);

    color: var(--apha-primary);

    font-size: 22px;
}

.empty-content h3 {
    color: var(--apha-ink);

    font-size: 12.5px;
    font-weight: 750;
}

.empty-content p {
    margin-top: 3px;

    color: var(--apha-muted);

    font-size: 10.5px;
}




.history-pagination {
    margin-top: 16px;

    animation:
        paginationAppear 0.55s ease both;
}



.history-footnote {
    display: flex;
    align-items: flex-start;

    gap: 9px;

    margin-top: 14px;

    padding: 12px 15px;

    border:
        1px solid
        rgba(0, 143, 131, 0.07);

    border-radius: 12px;

    background:
        rgba(0, 143, 131, 0.035);
}

.footnote-icon {
    width: 19px;
    height: 19px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: var(--apha-primary);

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
    color: var(--apha-primary-dark);

    font-size: 9.5px;
    font-weight: 750;
}

.footnote-content p {
    color: var(--apha-muted);

    font-size: 10px;
    line-height: 1.45;
}



@keyframes historyIntroAppear {
    from {
        opacity: 0;
        transform:
            translateY(10px);
    }

    to {
        opacity: 1;
        transform:
            translateY(0);
    }
}

@keyframes tableAppear {
    from {
        opacity: 0;
        transform:
            translateY(15px);
    }

    to {
        opacity: 1;
        transform:
            translateY(0);
    }
}

@keyframes rowAppear {
    from {
        opacity: 0;
        transform:
            translateY(6px);
    }

    to {
        opacity: 1;
        transform:
            translateY(0);
    }
}

@keyframes paginationAppear {
    from {
        opacity: 0;
        transform:
            translateY(7px);
    }

    to {
        opacity: 1;
        transform:
            translateY(0);
    }
}

@keyframes emptyAppear {
    from {
        opacity: 0;
        transform:
            scale(0.98);
    }

    to {
        opacity: 1;
        transform:
            scale(1);
    }
}

@keyframes iconFloat {
    0%,
    100% {
        transform:
            translateY(0);
    }

    50% {
        transform:
            translateY(-3px);
    }
}

@keyframes badgePulse {
    0% {
        box-shadow:
            0 0 0 0
            rgba(0, 143, 131, 0.24);
    }

    70% {
        box-shadow:
            0 0 0 5px
            rgba(0, 143, 131, 0);
    }

    100% {
        box-shadow:
            0 0 0 0
            rgba(0, 143, 131, 0);
    }
}



@media (max-width: 900px) {
    .history-intro {
        align-items: flex-start;
        flex-direction: column;
    }

    .intro-badge {
        align-self: flex-start;
    }

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

    .history-intro {
        padding: 18px;
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

    .history-intro {
        margin-top: 6px;
        margin-bottom: 17px;

        border-radius: 14px;

        padding: 16px;
    }

    .intro-main {
        align-items: flex-start;
    }

    .intro-icon {
        width: 41px;
        height: 41px;

        border-radius: 11px;
    }

    .icon-history {
        font-size: 19px;
    }

    .intro-text h1 {
        font-size: 17px;
    }

    .intro-text p {
        font-size: 10px;
    }

    .intro-badge {
        width: 100%;

        justify-content: center;
    }

    .history-table-section {
        border-radius: 14px;
    }

    .table-heading h2 {
        font-size: 16px;
    }

    .table-heading p {
        font-size: 10px;
    }

    .table-filter-bar {
        min-height: 36px;
    }

    .filter-status {
        font-size: 9px;
    }

    .filter-summary {
        font-size: 8px;
    }

    .empty-state {
        flex-direction: column;

        text-align: center;

        padding: 38px 18px;
    }

    .history-footnote {
        border-radius: 11px;
    }
}


@media (max-width: 400px) {
    .intro-main {
        gap: 11px;
    }

    .intro-text h1 {
        font-size: 16px;
    }

    .intro-text p {
        max-width: 240px;
    }

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