<script setup lang="ts">
/**
 * Qui a déclaré quel mois, pour relancer hors plateforme.
 *
 * Exception délimitée au CDC : un nom d'officine à côté de l'état de son mois,
 * et rien d'autre — ni assureur, ni nombre d'assureurs, ni montant. La
 * relance part sur WhatsApp ; la plateforme ne l'envoie pas.
 */
import { Head, router } from '@inertiajs/vue3';
import { MessageCircle } from '@lucide/vue';
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

const TEMPLATE = '2fr 1fr 1fr 1.3fr';
const COLUMNS = ['OFFICINE', 'VILLE', 'ÉTAT DU MOIS', 'RELANCE'];

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
        { preserveState: true, preserveScroll: true, replace: true },
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

    <div class="followup-page">
        <ConsoleHeader title="Suivi des déclarations">
            <template #filters>
                <FilterSelect
                    v-model="month"
                    :options="months"
                    label="Mois"
                    aria-label="Choisir le mois"
                />
                <FilterSelect
                    v-model="city"
                    :options="cityOptions"
                    label="Ville"
                    aria-label="Filtrer par ville"
                />
                <FilterSelect
                    v-model="state"
                    :options="STATES"
                    label="État"
                    aria-label="Filtrer par état"
                />
            </template>
        </ConsoleHeader>

        <p class="summary">{{ summaryLine }}</p>

        <DataTable title="Officines" :columns="COLUMNS" :template="TEMPLATE">
            <DataTableRow
                v-for="row in pharmacies.data"
                :key="row.id"
                :template="TEMPLATE"
            >
                <div class="name" :title="row.name">{{ row.name }}</div>

                <div>{{ row.city ?? '—' }}</div>

                <div>
                    <StatusChip
                        :status="CHIP[row.state]"
                        :label="row.stateLabel"
                    />
                </div>

                <div>
                    <a
                        v-if="row.whatsappUrl"
                        :href="row.whatsappUrl"
                        target="_blank"
                        rel="noopener"
                        class="whatsapp-link"
                    >
                        <MessageCircle
                            class="size-[14px]"
                            :stroke-width="2"
                            aria-hidden="true"
                        />
                        Relancer sur WhatsApp
                    </a>
                    <span v-else-if="row.state !== 'complete'" class="muted">
                        pas de numéro
                    </span>
                </div>
            </DataTableRow>

            <p v-if="!pharmacies.data.length" class="empty">
                Aucune officine ne correspond à ces critères.
            </p>
        </DataTable>

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

        <p class="footnote">
            Ni assureur ni montant ne figurent ici : l'état d'un mois est tout
            ce que le réseau voit d'une officine.
        </p>
    </div>
</template>

<style scoped>
.followup-page {
    padding: 0 10px 60px;
}

.summary {
    margin-top: 18px;
    font-size: var(--text-meta);
    color: var(--ink);
}

.name {
    overflow: hidden;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.whatsapp-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--officine);
    font-weight: 600;
}

.whatsapp-link:hover {
    text-decoration: underline;
    text-underline-offset: 2px;
}

.muted,
.footnote,
.empty {
    color: color-mix(in srgb, var(--ink) 70%, transparent);
    font-size: var(--text-meta);
}

.empty {
    padding: 22px 16px;
}

.pagination {
    margin-top: 16px;
}

.footnote {
    margin-top: 18px;
}
</style>
