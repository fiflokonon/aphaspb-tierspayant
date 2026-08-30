<script setup lang="ts">
import { computed, ref } from 'vue';

type Insurer = {
    id: number;
    name: string;
    /** Absent at onboarding, where only active insurers are ever offered. */
    isActive?: boolean;
    /** How many declarations this officine already filed with this insurer. */
    declarations?: number;
};

/** Past a short list, scanning beats searching; below it the field is noise. */
const SEARCHABLE_FROM = 12;

const props = defineProps<{ insurers: Insurer[] }>();

const selected = defineModel<number[]>('selected', { default: () => [] });
const other = defineModel<string>('other', { default: '' });

const search = ref('');
const otherOpen = ref(false);

const searchable = computed(() => props.insurers.length > SEARCHABLE_FROM);

/**
 * Filtered in the browser: the list of Beninese insurers and brokers fits in
 * memory many times over, so a server round trip per keystroke would be waste.
 */
const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();

    if (!needle) {
        return props.insurers;
    }

    return props.insurers.filter((insurer) =>
        insurer.name.toLowerCase().includes(needle),
    );
});

const isTicked = (id: number) => selected.value.includes(id);

/**
 * Two groups once anything is ticked, one anonymous group otherwise.
 *
 * That single rule is what lets the onboarding step reuse this component
 * untouched: nothing is ticked there, so no heading appears and the screen
 * stays the plain list it has always been.
 */
const groups = computed(() => {
    const ticked = visible.value.filter((insurer) => isTicked(insurer.id));

    if (selected.value.length === 0) {
        return [{ key: 'all', label: null, rows: visible.value }];
    }

    return [
        {
            key: 'ticked',
            label: `VOS ASSUREURS · ${selected.value.length}`,
            rows: ticked,
        },
        {
            key: 'rest',
            label: 'EN AJOUTER',
            rows: visible.value.filter((insurer) => !isTicked(insurer.id)),
        },
    ];
});

const declarationsLabel = (insurer: Insurer): string | null => {
    const count = insurer.declarations ?? 0;

    return count === 0 ? null : `${count} déclaration${count > 1 ? 's' : ''}`;
};

function toggle(id: number) {
    selected.value = isTicked(id)
        ? selected.value.filter((current) => current !== id)
        : [...selected.value, id];
}
</script>

<template>
    <div>
        <div class="px-5 pt-5 pb-[14px]">
            <!--
                The heading is a slot: the same checklist serves the onboarding
                step and the editable « Mes assureurs » screen, which say
                different things about the same choice.
            -->
            <slot name="heading" />
            <input
                v-if="searchable"
                v-model="search"
                type="search"
                placeholder="Rechercher un assureur…"
                class="mt-[13px] h-[42px] w-full rounded-[10px] bg-[#f3f1eb] px-3 text-[12.5px] text-ink outline-none placeholder:text-ink/40"
            />
        </div>

        <div class="flex flex-col border-t border-ink/[0.08]">
            <template v-for="(group, index) in groups" :key="group.key">
                <div
                    v-if="group.label"
                    class="bg-cream-header px-5 py-[7px] font-mono text-[10px] font-semibold tracking-[0.04em] text-ink/50"
                    :class="index > 0 ? 'border-t border-ink/[0.08]' : ''"
                >
                    {{ group.label }}
                </div>

                <button
                    v-for="insurer in group.rows"
                    :key="insurer.id"
                    type="button"
                    class="flex min-h-[44px] items-center gap-3 px-5 py-[13px] text-left transition-colors"
                    :class="
                        isTicked(insurer.id)
                            ? 'bg-officine/[0.06]'
                            : 'hover:bg-ink/[0.02]'
                    "
                    @click="toggle(insurer.id)"
                >
                    <span
                        class="grid size-[22px] shrink-0 place-items-center rounded-md text-[12px] font-bold"
                        :class="
                            isTicked(insurer.id)
                                ? 'bg-officine text-white'
                                : 'border-[1.5px] border-ink/20'
                        "
                    >
                        <template v-if="isTicked(insurer.id)">✓</template>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span
                            class="text-[13.5px]"
                            :class="
                                isTicked(insurer.id)
                                    ? 'font-semibold text-ink'
                                    : 'font-medium text-ink/75'
                            "
                        >
                            {{ insurer.name }}
                        </span>
                        <span
                            v-if="insurer.isActive === false"
                            class="mt-[3px] block text-[11px]/[1.35] text-ink/50"
                        >
                            Plus proposé par l'APhaSPB. Vous pouvez continuer à
                            déclarer, ou le retirer.
                        </span>
                    </span>
                    <span
                        v-if="declarationsLabel(insurer)"
                        class="shrink-0 font-mono text-[10.5px] text-ink/[0.45]"
                    >
                        {{ declarationsLabel(insurer) }}
                    </span>
                </button>
            </template>

            <p
                v-if="visible.length === 0"
                class="px-5 py-6 text-center text-[12px] text-ink/[0.45]"
            >
                Aucun assureur ne correspond à « {{ search.trim() }} ».
            </p>

            <div class="border-t border-dashed border-ink/[0.14]">
                <!--
                    An action, not a checkbox. The old row wore a tick whose
                    state came from whether text had been typed, and clicking it
                    opened a field instead of selecting anything.
                -->
                <button
                    v-if="!otherOpen"
                    type="button"
                    class="flex min-h-[44px] w-full items-center px-5 py-[13px] text-left text-[12.5px] font-semibold text-officine transition-colors hover:bg-ink/[0.02]"
                    @click="otherOpen = true"
                >
                    + Un assureur qui n'est pas dans la liste
                </button>
                <div v-else class="px-5 py-[14px]">
                    <input
                        v-model="other"
                        name="other"
                        type="text"
                        placeholder="Nom de l'assureur ou du courtier"
                        class="h-[46px] w-full rounded-[10px] border-[1.5px] border-ink/[0.13] bg-card px-3 text-[13px] font-medium text-ink outline-none focus:border-gold-mid/[0.55]"
                    />
                    <p class="mt-[6px] text-[11px]/[1.4] text-ink/[0.45]">
                        L'APhaSPB validera ce nom avant qu'il entre dans les
                        statistiques du réseau.
                    </p>
                </div>
            </div>
        </div>

        <input
            v-for="id in selected"
            :key="`field-${id}`"
            type="hidden"
            name="insurers[]"
            :value="id"
        />
    </div>
</template>
