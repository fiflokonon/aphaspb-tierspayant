<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Row = {
    id: number;
    name: string;
    isActive: boolean;
    standardDelayDays: number;
    pharmacies: number;
};

defineProps<{
    insurers: Row[];
    anonymityMinimum: number;
}>();

const TEMPLATE = '2fr .8fr 1.1fr .9fr 1.2fr';
const COLUMNS = [
    'ASSUREUR',
    'OFFICINES (n)',
    'DÉLAI STANDARD',
    'ÉTAT',
    'ACTION',
];

const editing = ref<number | null>(null);
const draft = ref('');

function startEditing(row: Row) {
    editing.value = row.id;
    draft.value = row.name;
}
</script>

<template>
    <Head title="Gestion des assureurs" />

    <ConsoleHeader
        eyebrow="RÉSEAU DES OFFICINES · BÉNIN"
        title="Gestion des assureurs"
    />

    <DataTable
        title="Assureurs et courtiers"
        :columns="COLUMNS"
        :template="TEMPLATE"
        :footer="`${insurers.length} entrées · désactiver un assureur le retire des formulaires sans toucher à ses déclarations`"
    >
        <DataTableRow
            v-for="row in insurers"
            :key="row.id"
            :template="TEMPLATE"
            :tone="row.isActive ? 'default' : 'muted'"
        >
            <div>
                <Form
                    v-if="editing === row.id"
                    :action="`/admin/insurers/${row.id}`"
                    method="patch"
                    class="flex items-center gap-2"
                    @success="editing = null"
                >
                    <input
                        v-model="draft"
                        name="name"
                        type="text"
                        aria-label="Nouveau nom de l'assureur"
                        class="h-8 min-w-0 flex-1 rounded-md border-[1.5px] border-ink/[0.13] px-2 text-xs"
                    />
                    <input
                        type="hidden"
                        name="is_active"
                        :value="row.isActive ? 1 : 0"
                    />
                    <button
                        type="submit"
                        class="text-[11px] font-semibold text-officine"
                    >
                        OK
                    </button>
                    <button
                        type="button"
                        class="text-[11px] font-semibold text-ink/50"
                        @click="editing = null"
                    >
                        Annuler
                    </button>
                </Form>
                <span v-else :class="row.isActive ? '' : 'text-ink/50'">
                    {{ row.name }}
                </span>
            </div>

            <div class="font-medium text-ink/60">{{ row.pharmacies }}</div>

            <!--
                Typed, not stepped: an admin transcribing a convention knows the
                figure and should be able to write it. Saved on change, so the
                row carries no button of its own.
            -->
            <div>
                <Form
                    :action="`/admin/insurers/${row.id}`"
                    method="patch"
                    #default="{ submit, processing, errors }"
                >
                    <div class="flex items-center gap-[6px]">
                        <input
                            :value="row.standardDelayDays"
                            name="standard_delay_days"
                            type="number"
                            min="1"
                            max="365"
                            :disabled="processing"
                            :aria-label="`Délai standard de ${row.name} en jours`"
                            class="h-8 w-16 rounded-md border-[1.5px] border-ink/[0.13] bg-card px-2 text-xs outline-none focus:border-gold-mid/[0.55] disabled:opacity-60"
                            @change="submit"
                        />
                        <span class="text-[11px] text-ink/50">j</span>
                    </div>
                    <p
                        v-if="errors.standard_delay_days"
                        class="mt-1 text-[10.5px]/[1.3] text-terracotta-dark"
                    >
                        {{ errors.standard_delay_days }}
                    </p>
                </Form>
            </div>

            <div>
                <span
                    v-if="row.isActive"
                    class="rounded-[5px] bg-officine/[0.12] px-[7px] py-[5px] font-mono text-[10px] font-semibold text-officine"
                >
                    ACTIF
                </span>
                <span
                    v-else
                    class="rounded-[5px] bg-ink/[0.07] px-[7px] py-[5px] font-mono text-[10px] font-semibold text-ink/[0.55]"
                >
                    INACTIF
                </span>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="text-[11.5px] font-semibold text-officine"
                    @click="startEditing(row)"
                >
                    Renommer
                </button>
                <Form
                    :action="`/admin/insurers/${row.id}`"
                    method="patch"
                    class="contents"
                >
                    <input type="hidden" name="name" :value="row.name" />
                    <input
                        type="hidden"
                        name="is_active"
                        :value="row.isActive ? 0 : 1"
                    />
                    <button
                        type="submit"
                        class="text-[11.5px] font-semibold text-ink/60"
                    >
                        {{ row.isActive ? 'Désactiver' : 'Réactiver' }}
                    </button>
                </Form>
            </div>
        </DataTableRow>
    </DataTable>

    <div class="mt-3 grid gap-3 lg:grid-cols-2">
        <div class="rounded-[11px] border border-border bg-card p-4">
            <div class="text-[12.5px] font-bold text-ink">
                Ajouter un assureur ou un courtier
            </div>
            <Form
                action="/admin/insurers"
                method="post"
                reset-on-success
                class="mt-3 flex flex-col gap-2 sm:flex-row"
                #default="{ errors, processing }"
            >
                <input
                    name="name"
                    type="text"
                    placeholder="Nom de l'assureur"
                    aria-label="Nom du nouvel assureur"
                    class="h-[42px] min-w-0 flex-1 rounded-[10px] border-[1.5px] border-ink/[0.13] bg-card px-3 text-[13px] outline-none focus:border-gold-mid/[0.55]"
                />
                <input
                    name="standard_delay_days"
                    type="number"
                    min="1"
                    max="365"
                    value="30"
                    aria-label="Délai standard en jours"
                    class="h-[42px] w-full shrink-0 rounded-[10px] border-[1.5px] border-ink/[0.13] bg-card px-3 text-[13px] outline-none focus:border-gold-mid/[0.55] sm:w-20"
                />
                <button
                    type="submit"
                    :disabled="processing"
                    class="h-[42px] shrink-0 rounded-[10px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground disabled:opacity-60"
                >
                    Ajouter
                </button>
                <p
                    v-if="errors.name"
                    class="text-[11px]/[1.4] text-terracotta-dark sm:hidden"
                >
                    {{ errors.name }}
                </p>
            </Form>
        </div>

        <div class="rounded-[11px] border border-border bg-card p-4">
            <div class="text-[12.5px] font-bold text-ink">
                Le délai est propre à chaque assureur
            </div>
            <p class="mt-1 text-[11px]/[1.45] text-ink/50">
                La part réglée « dans les délais » compare chaque déclaration au
                délai inscrit sur la ligne de son assureur, et non plus à un
                seuil unique pour tout le réseau. Un assureur créé depuis une
                officine démarre à 30 jours, à corriger ici dès que la
                convention est connue.
            </p>

            <p
                class="mt-4 border-t border-ink/[0.08] pt-3 text-[11px]/[1.45] text-ink/50"
            >
                Le seuil d'anonymat de
                <strong class="font-semibold text-ink/70">
                    {{ anonymityMinimum }} officines
                </strong>
                n'est pas réglable ici, et c'est délibéré : l'abaisser
                permettrait de lire les indicateurs d'un assureur déclaré par
                une seule officine, donc de l'identifier.
            </p>
        </div>
    </div>
</template>
