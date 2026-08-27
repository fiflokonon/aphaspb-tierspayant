<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Row = {
    id: number;
    name: string;
    city: string | null;
    onpbLicense: string | null;
    registeredAt: string | null;
};

const props = defineProps<{
    pharmacies: Row[];
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

let timer: ReturnType<typeof setTimeout> | undefined;

watch([city, search], ([nextCity, nextSearch]) => {
    clearTimeout(timer);

    // Debounced: the search fires on every keystroke, and reloading the list
    // per character would be noise on the wire for no gain.
    timer = setTimeout(() => {
        router.get(
            '/admin/pharmacies',
            { city: nextCity, search: nextSearch || null },
            {
                only: ['pharmacies', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 250);
});

const footer = computed(
    () =>
        `${props.pharmacies.length} sur ${props.total} officine${props.total > 1 ? 's' : ''} inscrite${props.total > 1 ? 's' : ''} · cette liste ne donne accès à aucune déclaration ni à aucun montant`,
);
</script>

<template>
    <Head title="Pharmacies inscrites" />

    <ConsoleHeader
        eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
        title="Pharmacies inscrites"
    >
        <template #filters>
            <input
                v-model="search"
                type="search"
                placeholder="Rechercher une officine…"
                aria-label="Rechercher une officine"
                class="h-[42px] w-full rounded-[10px] border border-input bg-card px-[13px] text-xs font-medium text-ink outline-none placeholder:font-normal placeholder:text-ink/40 sm:w-[220px]"
            />
            <FilterSelect
                v-model="city"
                :options="cityOptions"
                aria-label="Filtrer par ville"
            />
        </template>
    </ConsoleHeader>

    <DataTable
        title="Officines du réseau"
        :columns="COLUMNS"
        :template="TEMPLATE"
        :footer="footer"
    >
        <DataTableRow
            v-for="row in pharmacies"
            :key="row.id"
            :template="TEMPLATE"
        >
            <div class="truncate">{{ row.name }}</div>
            <div class="font-medium text-ink/60">{{ row.city ?? '—' }}</div>
            <div class="font-mono text-[11.5px] text-ink/60">
                {{ row.onpbLicense ?? '—' }}
            </div>
            <div class="font-medium text-ink/60">
                {{ row.registeredAt ?? '—' }}
            </div>
        </DataTableRow>

        <div
            v-if="!pharmacies.length"
            class="border-t border-ink/[0.06] px-4 py-8 text-center text-[12.5px] text-ink/50"
        >
            Aucune officine pour ce filtre.
        </div>
    </DataTable>
</template>
