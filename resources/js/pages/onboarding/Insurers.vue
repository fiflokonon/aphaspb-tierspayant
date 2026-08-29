<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InsurerChecklist from '@/components/aphaspb/InsurerChecklist.vue';

const props = defineProps<{
    insurers: { id: number; name: string; isActive: boolean }[];
    selected: number[];
}>();

const selected = ref<number[]>([...props.selected]);
const other = ref('');

const count = computed(
    () => selected.value.length + (other.value.trim() ? 1 : 0),
);

const label = computed(() =>
    count.value === 0
        ? 'Choisissez au moins un assureur'
        : `Continuer · ${count.value} assureur${count.value > 1 ? 's' : ''}`,
);
</script>

<template>
    <Head title="Vos assureurs" />

    <Form
        action="/onboarding/insurers"
        method="post"
        class="overflow-hidden rounded-[14px] bg-card shadow-sm"
        #default="{ errors, processing }"
    >
        <InsurerChecklist
            v-model:selected="selected"
            v-model:other="other"
            :insurers="insurers"
        >
            <template #heading>
                <div
                    class="font-mono text-[10.5px]/none font-semibold tracking-[0.06em] text-gold-dark"
                >
                    ÉTAPE 2 SUR 2
                </div>
                <h1 class="mt-[9px] text-[18px]/[1.25] font-bold text-ink">
                    Avec quels assureurs travaillez-vous ?
                </h1>
                <p class="mt-[7px] text-[12px]/[1.5] text-ink/[0.55]">
                    Une seule fois. Modifiable ensuite dans les réglages.
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
            <button
                type="submit"
                :disabled="processing || count === 0"
                class="flex h-[50px] w-full items-center justify-center rounded-[11px] bg-primary text-[14px] font-bold text-primary-foreground transition-opacity disabled:opacity-50"
            >
                {{ label }}
            </button>
        </div>
    </Form>
</template>
