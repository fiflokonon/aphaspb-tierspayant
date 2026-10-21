<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, ChevronDown, Lock, MapPin, Minus } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import type {
    DepartmentSelection,
    DepartmentTile,
    RegionCity,
} from '@/lib/regionDepartments';
import {
    departmentSelection,
    departmentTiles,
    regionCoverage,
    selectionCoverage,
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
/** Les départements dépliés pour choisir leurs villes une à une. */
const opened = ref<string[]>([]);
const editor = ref<HTMLElement | null>(null);

const form = useForm({
    name: '',
    city_ids: [] as number[],
});

const regionNames = computed(
    () => new Map(props.regions.map((region) => [region.id, region.name])),
);

const villes = (count: number) => `${count} ville${count > 1 ? 's' : ''}`;

const needle = computed(() => citySearch.value.trim().toLocaleLowerCase('fr'));

const matches = (text: string) =>
    text.toLocaleLowerCase('fr').includes(needle.value);

/**
 * Les départements, filtrés par la recherche sur leur nom ou celui d'une de
 * leurs villes proposées.
 *
 * Une ville d'une autre région n'est jamais proposée — le serveur la
 * refuserait de toute façon, une ville n'appartenant qu'à une région : son
 * département s'affiche, verrouillé s'il ne reste rien à prendre.
 */
const tiles = computed(() =>
    departmentTiles(props.cities, editing.value).filter(
        (tile) =>
            needle.value === '' ||
            matches(tile.department) ||
            tile.selectable.some((city) => matches(city.name)),
    ),
);

type TileState = DepartmentSelection | 'locked';

function tileState(tile: DepartmentTile): TileState {
    return tile.selectable.length === 0
        ? 'locked'
        : departmentSelection(tile.selectable, form.city_ids);
}

/** Déplié à la main, ou parce que la recherche vise une de ses villes. */
function isOpen(tile: DepartmentTile): boolean {
    if (tile.selectable.length === 0) {
        return false;
    }

    return (
        opened.value.includes(tile.department) ||
        (needle.value !== '' && !matches(tile.department))
    );
}

function toggleOpen(tile: DepartmentTile) {
    opened.value = opened.value.includes(tile.department)
        ? opened.value.filter((department) => department !== tile.department)
        : [...opened.value, tile.department];
}

function selectDepartment(tile: DepartmentTile) {
    form.city_ids = toggleDepartment(tile.selectable, form.city_ids);
}

function tileCount(tile: DepartmentTile, state: TileState): string {
    if (state === 'locked') {
        return villes(tile.total);
    }

    const held = tile.selectable.filter((city) =>
        form.city_ids.includes(city.id),
    ).length;

    return state === 'some'
        ? `${held} sur ${villes(tile.selectable.length)}`
        : villes(tile.selectable.length);
}

/** « Région 1 », ou « 2 villes en Région 3 » quand il en reste à prendre. */
function elsewhereLabel(tile: DepartmentTile): string {
    return tile.elsewhere
        .map(({ regionId, count }) => {
            const name = regionNames.value.get(regionId) ?? 'une autre région';

            return tile.selectable.length === 0 && tile.elsewhere.length === 1
                ? name
                : `${villes(count)} en ${name}`;
        })
        .join(' · ');
}

const summary = computed(() => selectionCoverage(props.cities, form.city_ids));

const freeCityCount = computed(
    () => props.cities.filter((city) => city.regionId === null).length,
);

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

function startEditing(region: Region) {
    editing.value = region.id;
    citySearch.value = '';
    opened.value = [];
    form.clearErrors();
    form.name = region.name;
    form.city_ids = [...region.cityIds];
    editor.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function resetEditor() {
    editing.value = null;
    citySearch.value = '';
    opened.value = [];
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

const tileClass: Record<TileState, string> = {
    all: 'border-primary/50 bg-primary/[0.05]',
    some: 'border-primary/30 bg-card',
    none: 'border-ink/[0.13] bg-card hover:border-ink/25',
    locked: 'border-dashed border-ink/[0.13] bg-ink/[0.02]',
};

const boxClass: Record<TileState, string> = {
    all: 'bg-primary text-primary-foreground',
    some: 'bg-primary text-primary-foreground',
    none: 'border-[1.5px] border-ink/20',
    locked: 'text-ink/35',
};
</script>

<template>
    <Head title="Régions pharmaceutiques" />

    <div class="flex flex-col gap-6">
        <ConsoleHeader title="Régions pharmaceutiques" />

        <p class="max-w-[720px] text-[14px]/[1.55] text-ink/60">
            Une région regroupe des départements. Cliquez un département pour le
            prendre avec toutes ses villes, dépliez-le pour en retirer une. Une
            ville n'appartient qu'à une seule région : un département déjà pris
            ailleurs apparaît verrouillé. L'officine hérite de la région de sa
            ville.
        </p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            <form
                ref="editor"
                class="flex scroll-mt-6 flex-col self-start rounded-[var(--radius-card)] border border-ink/[0.06] bg-card shadow-[var(--surface-shadow)]"
                @submit.prevent="submit"
            >
                <div class="flex flex-col gap-5 p-5">
                    <h2 class="text-[15px] font-bold text-ink">
                        {{
                            editing === null
                                ? 'Nouvelle région'
                                : 'Modifier la région'
                        }}
                    </h2>

                    <FormField
                        label="NOM DE LA RÉGION"
                        :error="form.errors.name"
                    >
                        <TextInput
                            v-model="form.name"
                            :invalid="!!form.errors.name"
                            placeholder="Région 3"
                        />
                    </FormField>

                    <FormField
                        label="DÉPARTEMENTS"
                        :error="cityError"
                        :hint="`${villes(freeCityCount)} sans région`"
                    >
                        <input
                            v-model="citySearch"
                            type="search"
                            placeholder="Rechercher un département ou une ville…"
                            aria-label="Rechercher un département ou une ville"
                            class="mb-3 h-[38px] w-full rounded-[10px] border-[1.5px] border-ink/[0.13] bg-card px-3 text-[13px] text-ink outline-none focus:border-gold-mid/[0.55]"
                        />

                        <div
                            class="grid items-start gap-2 sm:grid-cols-2 xl:grid-cols-3"
                        >
                            <div
                                v-for="tile in tiles"
                                :key="tile.department"
                                class="rounded-[12px] border transition-colors"
                                :class="tileClass[tileState(tile)]"
                            >
                                <div class="flex items-stretch">
                                    <button
                                        type="button"
                                        class="flex min-w-0 flex-1 items-start gap-3 p-3 text-left disabled:cursor-not-allowed"
                                        :disabled="tileState(tile) === 'locked'"
                                        :aria-pressed="
                                            tileState(tile) === 'some'
                                                ? 'mixed'
                                                : tileState(tile) === 'all'
                                        "
                                        @click="selectDepartment(tile)"
                                    >
                                        <span
                                            class="mt-px grid size-[20px] shrink-0 place-items-center rounded-md"
                                            :class="boxClass[tileState(tile)]"
                                            aria-hidden="true"
                                        >
                                            <Check
                                                v-if="tileState(tile) === 'all'"
                                                :size="13"
                                                :stroke-width="3"
                                            />
                                            <Minus
                                                v-else-if="
                                                    tileState(tile) === 'some'
                                                "
                                                :size="13"
                                                :stroke-width="3"
                                            />
                                            <Lock
                                                v-else-if="
                                                    tileState(tile) === 'locked'
                                                "
                                                :size="13"
                                            />
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span
                                                class="block truncate text-[13.5px] font-semibold"
                                                :class="
                                                    tileState(tile) === 'locked'
                                                        ? 'text-ink/45'
                                                        : 'text-ink'
                                                "
                                            >
                                                {{ tile.department }}
                                            </span>
                                            <span
                                                class="block text-[12.5px] text-ink/50"
                                            >
                                                {{
                                                    tileCount(
                                                        tile,
                                                        tileState(tile),
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-if="tile.elsewhere.length > 0"
                                                class="mt-[2px] block text-[12px]/[1.35] text-ink/40"
                                            >
                                                {{ elsewhereLabel(tile) }}
                                            </span>
                                        </span>
                                    </button>

                                    <button
                                        v-if="tile.selectable.length > 0"
                                        type="button"
                                        class="grid w-10 shrink-0 place-items-center text-ink/40 hover:text-ink"
                                        :aria-expanded="isOpen(tile)"
                                        :aria-label="`Choisir les villes de ${tile.department} une à une`"
                                        @click="toggleOpen(tile)"
                                    >
                                        <ChevronDown
                                            :size="16"
                                            class="transition-transform"
                                            :class="
                                                isOpen(tile) ? 'rotate-180' : ''
                                            "
                                        />
                                    </button>
                                </div>

                                <div
                                    v-if="isOpen(tile)"
                                    class="flex flex-col border-t border-ink/[0.06] py-1"
                                >
                                    <label
                                        v-for="city in tile.selectable"
                                        :key="city.id"
                                        class="flex cursor-pointer items-center gap-2 px-3 py-[6px] text-[13px] text-ink hover:bg-cream-state"
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
                            </div>
                        </div>

                        <p
                            v-if="tiles.length === 0"
                            class="rounded-[12px] border border-dashed border-ink/[0.15] px-3 py-4 text-[13px] text-ink/45"
                        >
                            Aucun département ni ville ne correspond.
                        </p>
                    </FormField>
                </div>

                <div
                    class="sticky bottom-0 flex flex-wrap items-center gap-3 rounded-b-[var(--radius-card)] border-t border-ink/[0.06] bg-card px-5 py-4"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            v-if="summary.length === 0"
                            class="text-[13px] text-ink/45"
                        >
                            Aucun département sélectionné.
                        </p>
                        <template v-else>
                            <p class="text-[13px] font-semibold text-ink">
                                {{
                                    summary
                                        .map((entry) =>
                                            entry.held < entry.total
                                                ? `${entry.department} (${villes(entry.held)})`
                                                : entry.department,
                                        )
                                        .join(', ')
                                }}
                            </p>
                            <p class="text-[12.5px] text-ink/50">
                                {{ villes(form.city_ids.length) }}
                            </p>
                        </template>
                    </div>

                    <button
                        v-if="editing !== null"
                        type="button"
                        class="h-[42px] rounded-[12px] border border-ink/[0.13] px-4 text-[12.5px] font-semibold text-ink/70"
                        @click="resetEditor"
                    >
                        Annuler
                    </button>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="flex h-[42px] items-center justify-center rounded-[12px] bg-primary px-5 text-[12.5px] font-bold text-primary-foreground transition-opacity disabled:opacity-60"
                    >
                        {{
                            editing === null ? 'Créer la région' : 'Enregistrer'
                        }}
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
                    class="rounded-[var(--radius-card)] border bg-card p-4 shadow-[var(--surface-shadow)]"
                    :class="
                        editing === region.id
                            ? 'border-primary/40'
                            : 'border-ink/[0.06]'
                    "
                >
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <h3 class="text-[15px] font-bold text-ink">
                                {{ region.name }}
                            </h3>
                            <p class="text-[12.5px] text-ink/50">
                                {{ villes(region.cities.length) }}
                            </p>
                        </div>

                        <button
                            type="button"
                            class="h-[32px] rounded-[10px] border border-ink/[0.13] px-3 text-[12.5px] font-semibold text-ink/70"
                            @click="startEditing(region)"
                        >
                            Modifier
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
                            </span>
                        </li>
                    </ul>

                    <div class="mt-3 flex justify-end">
                        <button
                            v-if="confirmingDelete !== region.id"
                            type="button"
                            class="text-[12.5px] font-semibold text-terracotta-dark"
                            @click="confirmingDelete = region.id"
                        >
                            Supprimer
                        </button>

                        <span v-else class="flex items-center gap-2">
                            <button
                                type="button"
                                class="text-[12.5px] font-semibold text-ink/60"
                                @click="confirmingDelete = null"
                            >
                                Garder
                            </button>
                            <button
                                type="button"
                                class="h-[32px] rounded-[10px] bg-terracotta px-3 text-[12.5px] font-bold text-white"
                                @click="destroy(region)"
                            >
                                Confirmer la suppression
                            </button>
                        </span>
                    </div>
                </article>
            </section>
        </div>
    </div>
</template>
