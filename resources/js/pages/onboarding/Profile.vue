<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';

defineProps<{
    pharmacy: {
        name: string | null;
        onpb_license: string | null;
        city: string | null;
    } | null;
    cities: string[];
}>();

const page = usePage();

/**
 * The titulaire is read, never typed: the server writes the Joomla account
 * holder's name whatever the form carries, so showing anything else here
 * would promise an edit that does not happen.
 */
const ownerName = computed(() => page.props.auth.user?.name ?? '');
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

            <FormField
                label="NOM DU TITULAIRE"
                hint="Repris de votre compte APhaSPB. Pour le corriger, modifiez votre profil sur le site de l'association."
            >
                <p
                    class="flex h-[46px] items-center rounded-[10px] border-[1.5px] border-ink/[0.13] bg-ink/[0.03] px-3 text-[13px] font-medium text-ink/70"
                >
                    {{ ownerName }}
                </p>
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
