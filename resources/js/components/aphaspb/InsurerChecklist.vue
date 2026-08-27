<script setup lang="ts">
import { computed, ref } from 'vue';

type Insurer = { id: number; name: string };

const props = withDefaults(
    defineProps<{
        insurers: Insurer[];
        /** Insurers whose monthly slot would be lost, but not their history. */
        withDeclarations?: number[];
    }>(),
    { withDeclarations: () => [] },
);

const selected = defineModel<number[]>('selected', { default: () => [] });
const other = defineModel<string>('other', { default: '' });

const search = ref('');
const otherOpen = ref(false);

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
                v-model="search"
                type="search"
                placeholder="Rechercher un assureur…"
                class="mt-[13px] h-[42px] w-full rounded-[10px] bg-[#f3f1eb] px-3 text-[12.5px] text-ink outline-none placeholder:text-ink/40"
            />
        </div>

        <div class="flex flex-col border-t border-ink/[0.08]">
            <button
                v-for="insurer in visible"
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
                        v-if="
                            props.withDeclarations.includes(insurer.id) &&
                            !isTicked(insurer.id)
                        "
                        class="mt-[3px] block text-[11px]/[1.35] text-ink/50"
                    >
                        Vos déclarations passées sont conservées. Cet assureur
                        ne vous sera simplement plus proposé chaque mois.
                    </span>
                </span>
            </button>

            <div class="border-t border-dashed border-ink/[0.14]">
                <button
                    type="button"
                    class="flex min-h-[44px] w-full items-center gap-3 px-5 py-[13px] text-left"
                    @click="otherOpen = !otherOpen"
                >
                    <span
                        class="grid size-[22px] shrink-0 place-items-center rounded-md text-[12px] font-bold"
                        :class="
                            other.trim()
                                ? 'bg-officine text-white'
                                : 'border-[1.5px] border-ink/20'
                        "
                    >
                        <template v-if="other.trim()">✓</template>
                    </span>
                    <span class="text-[13.5px] font-medium text-ink/75">
                        Autre…
                    </span>
                </button>
                <div v-if="otherOpen" class="px-5 pb-[14px]">
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
