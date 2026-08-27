<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';

defineProps<{
    pharmacy: {
        name: string | null;
        onpb_license: string | null;
        city: string | null;
        owner_name: string | null;
    } | null;
    cities: string[];
}>();
</script>

<template>
    <Head title="Votre officine" />

    <div class="overflow-hidden rounded-[14px] bg-card shadow-sm">
        <div class="bg-officine px-[22px] pt-[26px] pb-5 text-white">
            <img
                src="/logo-aphaspb.webp"
                alt="APhaSPB"
                class="size-[38px] rounded-full bg-white p-[3px]"
            />
            <h1 class="mt-[14px] font-serif text-[24px]/[1.2]">
                Combien de temps les assureurs mettent-ils à vous payer ?
            </h1>
            <p class="mt-[10px] text-[12px]/[1.55] opacity-[0.82]">
                Déclarez en 1 minute par mois. L'APhaSPB ne voit que les
                moyennes du réseau — jamais vos montants, jamais vos factures.
            </p>
        </div>

        <Form
            action="/onboarding"
            method="post"
            class="flex flex-col gap-[11px] px-[22px] pt-5 pb-6"
            #default="{ errors, processing }"
        >
            <FormField label="NOM DE L'OFFICINE" :error="errors.name">
                <TextInput
                    name="name"
                    :model-value="pharmacy?.name ?? ''"
                    :invalid="!!errors.name"
                    placeholder="Pharmacie Le Bon Secours"
                />
            </FormField>

            <div class="flex flex-col gap-[11px] sm:flex-row sm:gap-[10px]">
                <div class="flex-1">
                    <FormField
                        label="N° ONPB"
                        :error="errors.onpb_license"
                        hint="Optionnel"
                    >
                        <TextInput
                            name="onpb_license"
                            :model-value="pharmacy?.onpb_license ?? ''"
                            :invalid="!!errors.onpb_license"
                            placeholder="Optionnel"
                        />
                    </FormField>
                </div>
                <div class="flex-1">
                    <FormField label="VILLE" :error="errors.city">
                        <TextInput
                            name="city"
                            :model-value="pharmacy?.city ?? ''"
                            :invalid="!!errors.city"
                            placeholder="Cotonou"
                            list="known-cities"
                        />
                        <datalist id="known-cities">
                            <option v-for="c in cities" :key="c" :value="c" />
                        </datalist>
                    </FormField>
                </div>
            </div>

            <FormField label="NOM DU TITULAIRE" :error="errors.owner_name">
                <TextInput
                    name="owner_name"
                    :model-value="pharmacy?.owner_name ?? ''"
                    :invalid="!!errors.owner_name"
                    placeholder="Nom et prénom"
                />
            </FormField>

            <button
                type="submit"
                :disabled="processing"
                class="mt-[6px] flex h-[50px] items-center justify-center rounded-[11px] bg-ink text-[14px] font-bold text-white transition-opacity disabled:opacity-60"
            >
                {{ processing ? 'Enregistrement…' : 'Continuer' }}
            </button>

            <p class="text-center text-[11px]/[1.5] text-ink/[0.45]">
                Votre compte est géré par votre espace APhaSPB.<br />
                Ces informations identifient votre officine.
            </p>
        </Form>
    </div>
</template>
