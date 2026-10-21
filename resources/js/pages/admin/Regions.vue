<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { MapPin } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import type { RegionCity } from '@/lib/regionDepartments';
import {
    departmentSelection,
    groupByDepartment,
    regionCoverage,
    toggleDepartment,
} from '@/lib/regionDepartments';

type Region = {
    id: number;
    name: string;
    cityIds: number[];
    cities: string[];
};

const props = defineProps<{
    regions: Region[];
    cities: RegionCity[];
}>();

/** La région en cours d'édition, ou null pour en créer une. */
const editing = ref<number | null>(null);
const confirmingDelete = ref<number | null>(null);
const citySearch = ref('');

const form = useForm({
    name: '',
    city_ids: [] as number[],
});

/**
 * Les villes que la région éditée peut prendre — les siennes et les libres —,
 * regroupées par département.
 *
 * Une ville d'une autre région n'est jamais proposée — le serveur la
 * refuserait de toute façon, une ville n'appartenant qu'à une région. La
 * recherche porte sur la ville ou sur son département.
 */
const selectableDepartments = computed(() => {
    const needle = citySearch.value.trim().toLocaleLowerCase('fr');
    const matches = (text: string) =>
        text.toLocaleLowerCase('fr').includes(needle);

    return groupByDepartment(
        props.cities.filter(
            (city) =>
                (city.regionId === null || city.regionId === editing.value) &&
                (needle === '' ||
                    matches(city.name) ||
                    matches(city.department)),
        ),
    );
});

/** Les départements de chaque région, entiers ou partiels. */
const coverageByRegion = computed(
    () =>
        new Map(
            props.regions.map((region) => [
                region.id,
                regionCoverage(props.cities, region.id),
            ]),
        ),
);

function selectDepartment(cities: RegionCity[]) {
    form.city_ids = toggleDepartment(cities, form.city_ids);
}

const freeCityCount = computed(
    () => props.cities.filter((city) => city.regionId === null).length,
);

function startEditing(region: Region) {
    editing.value = region.id;
    citySearch.value = '';
    form.clearErrors();
    form.name = region.name;
    form.city_ids = [...region.cityIds];
}

function resetEditor() {
    editing.value = null;
    citySearch.value = '';
    form.reset();
    form.clearErrors();
}

function submit() {
    const options = { preserveScroll: true, onSuccess: resetEditor };

    if (editing.value === null) {
        form.post('/admin/regions', options);
    } else {
        form.patch(`/admin/regions/${editing.value}`, options);
    }
}

function destroy(region: Region) {
    router.delete(`/admin/regions/${region.id}`, {
        preserveScroll: true,
        onFinish: () => {
            confirmingDelete.value = null;

            if (editing.value === region.id) {
                resetEditor();
            }
        },
    });
}

/** La première erreur d'une ville, que Laravel indexe par position. */
const cityError = computed(
    () =>
        form.errors.city_ids ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('city_ids.'),
        )?.[1],
);
</script>

<template>
    <Head title="Régions pharmaceutiques" />

    <div class="flex flex-col gap-6">
        <ConsoleHeader title="Régions pharmaceutiques" />

        <p class="max-w-[720px] text-[14px]/[1.55] text-ink/60">
            Une région regroupe des départements : cocher un département prend
            toutes ses villes libres, et l'on peut encore en décocher une. Une
            ville n'appartient qu'à une seule région : celles déjà rattachées ne
            sont pas proposées ailleurs. L'officine hérite de la région de sa
            ville.
        </p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,380px)_1fr]">
            <form
                class="flex flex-col gap-4 self-start rounded-[var(--radius-card)] border border-ink/[0.06] bg-card p-5 shadow-[var(--surface-shadow)]"
                @submit.prevent="submit"
            >
                <h2 class="text-[15px] font-bold text-ink">
                    {{
                        editing === null
                            ? 'Nouvelle région'
                            : 'Modifier la région'
                    }}
                </h2>

                <FormField label="NOM DE LA RÉGION" :error="form.errors.name">
                    <TextInput
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        placeholder="Région Sud"
                    />
                </FormField>

                <FormField
                    label="DÉPARTEMENTS ET VILLES"
                    :error="cityError"
                    :hint="`${form.city_ids.length} sélectionnée(s) · ${freeCityCount} ville(s) sans région`"
                >
                    <input
                        v-model="citySearch"
                        type="search"
                        placeholder="Rechercher un département ou une ville…"
                        aria-label="Rechercher un département ou une ville"
                        class="mb-2 h-[38px] w-full rounded-[10px] border-[1.5px] border-ink/[0.13] bg-card px-3 text-[13px] text-ink outline-none focus:border-gold-mid/[0.55]"
                    />

                    <div
                        class="max-h-[280px] overflow-y-auto rounded-[10px] border border-ink/[0.08]"
                    >
                        <div
                            v-for="group in selectableDepartments"
                            :key="group.department"
                            class="border-b border-ink/[0.05] last:border-b-0"
                        >
                            <label
                                class="flex cursor-pointer items-center gap-2 bg-cream-state/60 px-3 py-2 text-[13px] font-bold text-ink hover:bg-cream-state"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        departmentSelection(
                                            group.cities,
                                            form.city_ids,
                                        ) === 'all'
                                    "
                                    :indeterminate="
                                        departmentSelection(
                                            group.cities,
                                            form.city_ids,
                                        ) === 'some'
                                    "
                                    class="accent-[var(--primary)]"
                                    @change="selectDepartment(group.cities)"
                                />
                                <span class="flex-1">{{
                                    group.department
                                }}</span>
                                <span
                                    class="text-[12.5px] font-medium text-ink/45"
                                >
                                    {{ group.cities.length }} ville{{
                                        group.cities.length > 1 ? 's' : ''
                                    }}
                                </span>
                            </label>

                            <label
                                v-for="city in group.cities"
                                :key="city.id"
                                class="flex cursor-pointer items-center gap-2 py-2 pr-3 pl-8 text-[13px] text-ink hover:bg-cream-state"
                            >
                                <input
                                    v-model="form.city_ids"
                                    type="checkbox"
                                    :value="city.id"
                                    class="accent-[var(--primary)]"
                                />
                                {{ city.name }}
                            </label>
                        </div>

                        <p
                            v-if="selectableDepartments.length === 0"
                            class="px-3 py-3 text-[13px] text-ink/45"
                        >
                            Aucune ville disponible.
                        </p>
                    </div>
                </FormField>

                <div class="flex gap-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="flex h-[42px] flex-1 items-center justify-center rounded-[12px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground transition-opacity disabled:opacity-60"
                    >
                        {{
                            editing === null ? 'Créer la région' : 'Enregistrer'
                        }}
                    </button>

                    <button
                        v-if="editing !== null"
                        type="button"
                        class="h-[42px] rounded-[12px] border border-ink/[0.13] px-4 text-[12.5px] font-semibold text-ink/70"
                        @click="resetEditor"
                    >
                        Annuler
                    </button>
                </div>
            </form>

            <section class="flex flex-col gap-3">
                <p
                    v-if="regions.length === 0"
                    class="rounded-[var(--radius-card)] border border-dashed border-ink/[0.15] p-6 text-[14px] text-ink/50"
                >
                    Aucune région pour l'instant.
                </p>

                <article
                    v-for="region in regions"
                    :key="region.id"
                    class="rounded-[var(--radius-card)] border bg-card p-5 shadow-[var(--surface-shadow)]"
                    :class="
                        editing === region.id
                            ? 'border-primary/40'
                            : 'border-ink/[0.06]'
                    "
                >
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="flex-1">
                            <h3 class="text-[15px] font-bold text-ink">
                                {{ region.name }}
                            </h3>
                            <p class="text-[13px] text-ink/50">
                                {{ region.cities.length }} ville{{
                                    region.cities.length > 1 ? 's' : ''
                                }}
                            </p>
                        </div>

                        <button
                            type="button"
                            class="h-[34px] rounded-[10px] border border-ink/[0.13] px-3 text-[12.5px] font-semibold text-ink/70"
                            @click="startEditing(region)"
                        >
                            Modifier
                        </button>

                        <button
                            v-if="confirmingDelete !== region.id"
                            type="button"
                            class="h-[34px] rounded-[10px] px-3 text-[12.5px] font-semibold text-terracotta-dark"
                            @click="confirmingDelete = region.id"
                        >
                            Supprimer
                        </button>

                        <button
                            v-else
                            type="button"
                            class="h-[34px] rounded-[10px] bg-terracotta px-3 text-[12.5px] font-bold text-white"
                            @click="destroy(region)"
                        >
                            Confirmer la suppression
                        </button>
                    </div>

                    <ul
                        v-if="region.cities.length > 0"
                        class="mt-3 flex flex-wrap gap-[6px]"
                    >
                        <li
                            v-for="coverage in coverageByRegion.get(region.id)"
                            :key="coverage.department"
                            class="flex items-center gap-1 rounded-full bg-cream-state px-[10px] py-[4px] text-[12.5px] font-medium text-ink/75"
                        >
                            <MapPin :size="12" aria-hidden="true" />
                            {{ coverage.department }}
                            <span
                                v-if="coverage.held < coverage.total"
                                class="text-ink/45"
                            >
                                · {{ coverage.held }}/{{ coverage.total }}
                                villes
                            </span>
                        </li>
                    </ul>
                </article>
            </section>
        </div>
    </div>
</template>
