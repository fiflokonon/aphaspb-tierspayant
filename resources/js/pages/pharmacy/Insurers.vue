<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InsurerChecklist from '@/components/aphaspb/InsurerChecklist.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Insurer = {
    id: number;
    name: string;
    isActive: boolean;
    declarations: number;
};

const props = defineProps<{
    insurers: Insurer[];
    selected: number[];
}>();

const selected = ref<number[]>([...props.selected]);
const other = ref('');

const count = computed(
    () => selected.value.length + (other.value.trim() ? 1 : 0),
);

/** Insurers being untied that carry a history — the only untying worth a word. */
const losing = computed(() =>
    props.insurers.filter(
        (insurer) =>
            insurer.declarations > 0 &&
            props.selected.includes(insurer.id) &&
            !selected.value.includes(insurer.id),
    ),
);
</script>

<template>
    <Head title="Mes assureurs" />

    <ConsoleHeader eyebrow="MON OFFICINE" title="Mes assureurs" />

    <div class="mt-5 max-w-[560px]">
        <Form
            action="/pharmacy/insurers"
            method="patch"
            class="overflow-hidden rounded-[11px] border border-border bg-card"
            #default="{ errors, processing }"
        >
            <InsurerChecklist
                v-model:selected="selected"
                v-model:other="other"
                :insurers="insurers"
            >
                <template #heading>
                    <h1 class="text-[18px]/[1.25] font-bold text-ink">
                        Avec quels assureurs travaillez-vous ?
                    </h1>
                    <p class="mt-[7px] text-[12px]/[1.5] text-ink/[0.55]">
                        Ce sont les assureurs qui vous seront proposés chaque
                        mois. Modifiable à tout moment.
                    </p>
                </template>
            </InsurerChecklist>

            <div class="border-t border-ink/[0.08] bg-cream px-5 pt-4 pb-5">
                <p
                    v-if="errors.insurers"
                    class="mb-3 text-[11px]/[1.4] text-terracotta-dark"
                >
                    {{ errors.insurers }}
                </p>
                <p
                    v-else-if="losing.length"
                    class="mb-3 text-[11px]/[1.4] text-ink/60"
                >
                    {{ losing.length }} assureur{{
                        losing.length > 1 ? 's' : ''
                    }}
                    {{ losing.length > 1 ? 'perdront' : 'perdra' }} sa place
                    dans la déclaration mensuelle. Vos déclarations passées
                    restent dans votre historique.
                </p>
                <button
                    type="submit"
                    :disabled="processing || count === 0"
                    class="flex h-[50px] w-full items-center justify-center rounded-[11px] bg-primary text-[14px] font-bold text-primary-foreground transition-opacity disabled:opacity-50"
                >
                    Enregistrer · {{ count }} assureur{{ count > 1 ? 's' : '' }}
                </button>
            </div>
        </Form>
    </div>
</template>
