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

    <ConsoleHeader eyebrow="MES DÉCLARATIONS" title="Historique">
        <template #filters>
            <FilterSelect
                v-model="insurer"
                :options="insurerOptions"
                aria-label="Filtrer par assureur"
            />
            <FilterSelect
                v-model="year"
                :options="yearOptions"
                aria-label="Filtrer par année"
            />
        </template>
    </ConsoleHeader>

    <DataTable
        title="Toutes mes déclarations"
        :columns="COLUMNS"
        :template="TEMPLATE"
        :footer="footer"
    >
        <DataTableRow
            v-for="row in declarations.data"
            :key="row.id"
            :template="TEMPLATE"
        >
            <div class="truncate">{{ row.insurerName }}</div>
            <div class="font-medium text-ink/60">{{ row.monthLabel }}</div>
            <div>
                <StatusChip :status="row.status" :label="row.statusLabel" />
            </div>
            <div class="font-mono text-[11.5px]">{{ row.invoiced }}</div>
            <div class="font-mono text-[11.5px]">{{ row.received }}</div>
            <div
                class="font-mono text-[11.5px]"
                :class="
                    row.outstanding ? 'text-terracotta-dark' : 'text-ink/40'
                "
            >
                {{ row.outstanding ?? '—' }}
            </div>
            <div class="font-medium">
                {{ row.delayDays !== null ? `${row.delayDays} j` : '—' }}
            </div>
            <div class="truncate text-[11.5px] font-normal text-ink/60">
                {{ row.privateNote ?? '—' }}
            </div>
            <div>
                <Link
                    :href="row.editUrl"
                    class="text-[11.5px] font-semibold text-officine"
                >
                    Modifier
                </Link>
            </div>
        </DataTableRow>

        <div
            v-if="!declarations.data.length"
            class="border-t border-ink/[0.06] px-4 py-8 text-center text-[12.5px] text-ink/50"
        >
            Aucune déclaration pour ce filtre.
        </div>
    </DataTable>

    <Pagination
        :page="declarations.current_page"
        :last-page="declarations.last_page"
        :from="declarations.from"
        :to="declarations.to"
        :total="declarations.total"
        noun="déclaration"
        @update:page="reload"
    />

    <p class="mt-3 text-[11px]/[1.45] text-ink/[0.45]">
        Vos notes privées n'apparaissent que sur cet écran. L'APhaSPB ne les
        reçoit jamais.
    </p>
</template>
