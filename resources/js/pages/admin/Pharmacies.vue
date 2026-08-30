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
    filters: { city: string | null; search: string | null };
}>();

const TEMPLATE = '2fr 1fr 1fr 1.1fr';
const COLUMNS = ['OFFICINE', 'VILLE', 'N° ONPB', 'INSCRITE LE'];

const city = ref<string | null>(props.filters.city);
const search = ref(props.filters.search ?? '');

const cityOptions = computed(() => [
    { value: null, label: 'Toutes les villes' },
    ...props.cities.map((one) => ({ value: one, label: one })),
]);

function reload(page: number) {
    router.get(
        '/admin/pharmacies',
        { city: city.value, search: search.value || null, page },
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
watch([city, search], () => {
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
        <ConsoleHeader
            eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
            title="Pharmacies inscrites"
            class="pharmacies-header"
        >
            <template #filters>
                <div class="header-filters">
                    <!-- RECHERCHE -->

                    <div class="search-box">
                        <span class="search-icon" aria-hidden="true"> ⌕ </span>

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

                    <div class="city-filter">
                        <span class="city-filter-icon" aria-hidden="true">
                            ◉
                        </span>

                        <FilterSelect
                            v-model="city"
                            :options="cityOptions"
                            aria-label="Filtrer par ville"
                        />
                    </div>
                </div>
            </template>
        </ConsoleHeader>

        <section class="pharmacies-intro">
            <div class="intro-content">
                <div class="intro-icon">
                    <span> + </span>
                </div>

                <div class="intro-text">
                    <span class="intro-eyebrow"> RÉSEAU DES OFFICINES </span>

                    <h1>Pharmacies inscrites</h1>

                    <p>
                        Consultez les officines enregistrées dans le réseau et
                        retrouvez rapidement leurs informations
                        d'identification.
                    </p>
                </div>
            </div>

            <div class="network-status">
                <span class="status-dot"></span>

                <span> Réseau actif </span>
            </div>
        </section>

        <section class="pharmacies-section">
            <div class="section-top-line"></div>

            <DataTable
                title="Officines du réseau"
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
                    <div class="pharmacy-name-cell">
                        <div class="pharmacy-avatar">
                            {{ row.name?.charAt(0)?.toUpperCase() }}
                        </div>

                        <div class="pharmacy-name-content">
                            <div class="pharmacy-name" :title="row.name">
                                {{ row.name }}
                            </div>

                            <span class="pharmacy-status">
                                <span class="mini-status-dot"></span>

                                Officine inscrite
                            </span>
                        </div>
                    </div>

                    <div class="city-cell">
                        <span class="city-marker"> ● </span>

                        <span>
                            {{ row.city ?? '—' }}
                        </span>
                    </div>

                    <div class="license-cell">
                        <span class="license-icon"> # </span>

                        <span>
                            {{ row.onpbLicense ?? '—' }}
                        </span>
                    </div>

                    <div class="date-cell">
                        <span class="date-icon"> ◷ </span>

                        <span>
                            {{ row.registeredAt ?? '—' }}
                        </span>
                    </div>
                </DataTableRow>

                <div v-if="!pharmacies.data.length" class="empty-state">
                    <div class="empty-icon">⌕</div>

                    <div class="empty-title">Aucune officine trouvée</div>

                    <p>
                        Aucune officine ne correspond aux critères sélectionnés.
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

        <div class="pagination-wrapper">
            <Pagination
                :page="pharmacies.current_page"
                :last-page="pharmacies.last_page"
                :from="pharmacies.from"
                :to="pharmacies.to"
                :total="pharmacies.total"
                noun="officine"
                @update:page="reload"
            />
        </div>

        <div class="pharmacies-footnote">
            <div class="footnote-icon">i</div>

            <p>
                Les informations affichées correspondent aux officines
                actuellement enregistrées dans le réseau.
            </p>
        </div>
    </div>
</template>

<style scoped>
.pharmacies-page {
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

    min-height: 100vh;

    padding: 0 10px 60px;

    /* background:
        radial-gradient(
            circle at 95% 0%,
            rgba(0, 143, 131, .045),
            transparent 28%
        ); */
}

.pharmacies-header {
    position: relative;

    z-index: 5;
}

.header-filters {
    display: flex;

    align-items: center;

    gap: 8px;
}

.search-box {
    position: relative;

    display: flex;

    align-items: center;

    width: 250px;

    height: 42px;

    border: 1px solid var(--apha-border);

    border-radius: 10px;

    background: #ffffff;

    /* box-shadow:
        0 3px 10px
        rgba(35, 70, 68, .025); */

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.search-box:focus-within {
    border-color: rgba(0, 143, 131, 0.35);

    /* box-shadow:
        0 0 0 3px
        rgba(0, 143, 131, .07); */
}

.search-icon {
    display: flex;

    align-items: center;

    justify-content: center;

    width: 38px;

    flex-shrink: 0;

    color: var(--apha-muted);

    font-size: 18px;

    line-height: 1;
}

.search-input {
    width: 100%;

    height: 100%;

    padding: 0 34px 0 0;

    border: none;

    outline: none;

    background: transparent;

    color: var(--apha-ink);

    font-size: 11px;

    font-weight: 550;
}

.search-input::placeholder {
    color: rgba(36, 51, 51, 0.38);

    font-weight: 450;
}

.search-input::-webkit-search-cancel-button {
    display: none;
}

.clear-search {
    position: absolute;

    right: 9px;

    top: 50%;

    width: 21px;

    height: 21px;

    display: flex;

    align-items: center;

    justify-content: center;

    transform: translateY(-50%);

    border: none;

    border-radius: 50%;

    background: #f1f4f3;

    color: var(--apha-muted);

    font-size: 14px;

    cursor: pointer;

    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.clear-search:hover {
    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);
}

.city-filter {
    position: relative;

    display: flex;

    align-items: center;

    height: 42px;

    border: 1px solid var(--apha-border);

    border-radius: 10px;

    background: #ffffff;

    overflow: hidden;
}

.city-filter-icon {
    position: absolute;

    left: 11px;

    z-index: 2;

    color: var(--apha-primary);

    font-size: 8px;

    pointer-events: none;
}

.city-filter :deep(select) {
    height: 100%;

    min-width: 155px;

    padding-left: 26px;

    padding-right: 32px;

    border: none;

    outline: none;

    background: transparent;

    color: var(--apha-ink);

    font-size: 10.5px;

    font-weight: 650;
}

.pharmacies-intro {
    position: relative;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 25px;

    margin: 16px 0 22px;

    padding: 22px 25px;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: linear-gradient(110deg, #ffffff 0%, #f8fcfb 100%);

    /* box-shadow:
        0 8px 30px
        rgba(35, 70, 68, .035); */

    overflow: hidden;

    animation: fadeUp 0.5s ease both;
}

.pharmacies-intro::after {
    content: '';

    position: absolute;

    right: -70px;

    top: -90px;

    width: 220px;

    height: 220px;

    border-radius: 50%;

    background: radial-gradient(
        circle,
        rgba(0, 143, 131, 0.09),
        transparent 68%
    );

    pointer-events: none;
}

.intro-content {
    position: relative;

    z-index: 1;

    display: flex;

    align-items: center;

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

    background: linear-gradient(
        135deg,
        var(--apha-primary),
        var(--apha-primary-dark)
    );

    color: #ffffff;

    box-shadow: 0 8px 18px rgba(0, 143, 131, 0.18);
}

.intro-icon span {
    font-size: 24px;

    font-weight: 400;

    line-height: 1;
}

.intro-text {
    display: flex;

    flex-direction: column;
}

.intro-eyebrow {
    margin-bottom: 3px;

    color: var(--apha-primary);

    font-size: 8.5px;

    font-weight: 850;

    letter-spacing: 0.14em;
}

.intro-text h1 {
    margin: 0;

    color: var(--apha-ink);

    font-size: 20px;

    font-weight: 800;

    letter-spacing: -0.025em;
}

.intro-text p {
    max-width: 750px;

    margin-top: 4px;

    color: var(--apha-muted);

    font-size: 10.5px;

    line-height: 1.55;
}

.network-status {
    position: relative;

    z-index: 2;

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 8px 11px;

    border: 1px solid rgba(0, 143, 131, 0.08);

    border-radius: 30px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);

    font-size: 9px;

    font-weight: 700;

    white-space: nowrap;
}

.status-dot {
    width: 7px;

    height: 7px;

    border-radius: 50%;

    background: var(--apha-primary);

    box-shadow: 0 0 0 4px rgba(0, 143, 131, 0.07);
}

.pharmacies-section {
    position: relative;

    width: 100%;

    overflow: hidden;

    padding: 4px;

    border: 1px solid var(--apha-border);

    border-radius: 18px;

    background: #ffffff;

    box-shadow: 0 8px 30px rgba(35, 70, 68, 0.035);

    animation: fadeUp 0.6s ease 0.05s both;
}

.section-top-line {
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

    opacity: 0.9;
}

.pharmacies-table {
    border-radius: 14px;
}

.pharmacy-name-cell {
    display: flex;

    align-items: center;

    gap: 10px;

    min-width: 180px;
}

.pharmacy-avatar {
    width: 34px;

    height: 34px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid rgba(0, 143, 131, 0.08);

    border-radius: 10px;

    background: linear-gradient(135deg, #e6f6f2, #f3faf8);

    color: var(--apha-primary-dark);

    font-size: 11px;

    font-weight: 850;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.pharmacy-row:hover .pharmacy-avatar {
    transform: scale(1.06);

    box-shadow: 0 5px 12px rgba(0, 143, 131, 0.1);
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

    color: var(--apha-ink);

    font-size: 11.5px;

    font-weight: 700;

    text-overflow: ellipsis;

    white-space: nowrap;
}

.pharmacy-status {
    display: inline-flex;

    align-items: center;

    gap: 4px;

    color: var(--apha-light);

    font-size: 8px;

    font-weight: 550;
}

.mini-status-dot {
    width: 5px;

    height: 5px;

    border-radius: 50%;

    background: var(--apha-primary);
}

.city-cell {
    display: flex;

    align-items: center;

    gap: 7px;

    color: rgba(36, 51, 51, 0.62);

    font-size: 11px;

    font-weight: 550;
}

.city-marker {
    color: var(--apha-primary);

    font-size: 7px;
}

.license-cell {
    display: flex;

    align-items: center;

    gap: 7px;

    color: rgba(36, 51, 51, 0.62);

    font-family:
        ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

    font-size: 10px;
}

.license-icon {
    width: 20px;

    height: 20px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 6px;

    background: #f4f7f6;

    color: var(--apha-muted);

    font-size: 9px;

    font-weight: 800;
}

.date-cell {
    display: flex;

    align-items: center;

    gap: 7px;

    color: rgba(36, 51, 51, 0.6);

    font-size: 10.5px;

    font-weight: 550;
}

.date-icon {
    color: var(--apha-primary);

    font-size: 13px;
}

.empty-state {
    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    min-height: 220px;

    padding: 35px 20px;

    border-top: 1px solid rgba(36, 51, 51, 0.06);

    text-align: center;
}

.empty-icon {
    width: 46px;

    height: 46px;

    display: flex;

    align-items: center;

    justify-content: center;

    margin-bottom: 11px;

    border: 1px solid rgba(0, 143, 131, 0.08);

    border-radius: 14px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary);

    font-size: 20px;
}

.empty-title {
    color: var(--apha-ink);

    font-size: 13px;

    font-weight: 750;
}

.empty-state p {
    max-width: 360px;

    margin: 5px 0 13px;

    color: var(--apha-muted);

    font-size: 10px;

    line-height: 1.5;
}

.empty-reset {
    height: 34px;

    padding: 0 13px;

    border: 1px solid rgba(0, 143, 131, 0.15);

    border-radius: 8px;

    background: #ffffff;

    color: var(--apha-primary-dark);

    font-size: 9px;

    font-weight: 700;

    cursor: pointer;

    transition:
        background 0.2s ease,
        border-color 0.2s ease;
}

.empty-reset:hover {
    border-color: rgba(0, 143, 131, 0.3);

    background: var(--apha-primary-soft);
}

.pagination-wrapper {
    margin-top: 14px;

    padding: 0 2px;
}

.pharmacies-footnote {
    display: flex;

    align-items: flex-start;

    gap: 9px;

    margin-top: 13px;

    padding: 11px 14px;

    border: 1px solid rgba(0, 143, 131, 0.07);

    border-radius: 11px;

    background: rgba(0, 143, 131, 0.025);
}

.footnote-icon {
    width: 18px;

    height: 18px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: var(--apha-primary);

    color: #ffffff;

    font-size: 9px;

    font-weight: 850;
}

.pharmacies-footnote p {
    margin: 0;

    color: var(--apha-muted);

    font-size: 9px;

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

@media (max-width: 1000px) {
    .pharmacies-page {
        padding-left: 6px;

        padding-right: 6px;
    }

    .header-filters {
        flex-wrap: wrap;
    }

    .search-box {
        width: 220px;
    }
}

@media (max-width: 760px) {
    .pharmacies-page {
        padding: 0 4px 50px;
    }

    .pharmacies-intro {
        align-items: flex-start;

        flex-direction: column;

        margin-top: 10px;

        padding: 17px;

        border-radius: 15px;
    }

    .intro-content {
        align-items: flex-start;
    }

    .intro-icon {
        width: 41px;

        height: 41px;

        border-radius: 11px;
    }

    .intro-text h1 {
        font-size: 17px;
    }

    .intro-text p {
        font-size: 9.5px;
    }

    .network-status {
        width: 100%;

        justify-content: center;
    }

    /* HEADER FILTERS */

    .header-filters {
        width: 100%;

        flex-direction: column;

        align-items: stretch;
    }

    .search-box {
        width: 100%;
    }

    .city-filter {
        width: 100%;
    }

    .city-filter :deep(select) {
        width: 100%;
    }

    /* TABLE */

    .pharmacies-section {
        border-radius: 15px;

        padding: 2px;
    }

    /*
       Le tableau reste scrollable horizontalement
       afin de ne jamais casser la structure des colonnes.
    */

    .pharmacies-table {
        overflow-x: auto;
    }

    .pharmacies-footnote {
        margin-top: 10px;
    }
}

@media (max-width: 480px) {
    .pharmacy-name-cell {
        min-width: 160px;
    }

    .pharmacy-avatar {
        width: 31px;

        height: 31px;

        border-radius: 9px;
    }

    .pharmacy-name {
        font-size: 10.5px;
    }

    .city-cell,
    .date-cell {
        font-size: 9.5px;
    }

    .license-cell {
        font-size: 9px;
    }

    .empty-state {
        min-height: 190px;
    }
}

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
