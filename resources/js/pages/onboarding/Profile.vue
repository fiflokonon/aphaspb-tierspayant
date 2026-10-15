<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';

const props = defineProps<{
    pharmacy: {
        name: string | null;
        city: string | null;
        whatsapp_phone: string | null;
    } | null;
    cities: { name: string; region: string | null }[];
}>();

const page = usePage();

/**
 * The titulaire is read, never typed: the server writes the Joomla account
 * holder's name whatever the form carries, so showing anything else here
 * would promise an edit that does not happen.
 */
const ownerName = computed(() => page.props.auth.user?.name ?? '');

const city = ref(props.pharmacy?.city ?? '');

/**
 * The region is read off the chosen city, never typed: the association
 * attaches each commune to a region, and the server derives it the same way.
 */
const region = computed(
    () => props.cities.find((one) => one.name === city.value)?.region ?? null,
);
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
                    <FormField label="VILLE" :error="errors.city">
                        <select
                            v-model="city"
                            name="city"
                            class="h-[46px] w-full rounded-[10px] border-[1.5px] bg-card px-3 text-[13px] font-medium text-ink transition-shadow outline-none focus:border-gold-mid/[0.55] focus:shadow-[0_0_0_3px_rgb(217_163_37_/_0.13)]"
                            :class="
                                errors.city
                                    ? 'border-terracotta'
                                    : 'border-ink/[0.13]'
                            "
                        >
                            <option value="" disabled>Choisir une ville</option>
                            <option
                                v-for="c in cities"
                                :key="c.name"
                                :value="c.name"
                            >
                                {{ c.name }}
                            </option>
                        </select>
                    </FormField>
                </div>
                <div class="flex-1">
                    <FormField
                        label="RÉGION"
                        :hint="
                            city !== '' && region === null
                                ? 'Cette ville n’est encore rattachée à aucune région.'
                                : undefined
                        "
                    >
                        <p
                            class="flex h-[46px] items-center rounded-[10px] border-[1.5px] border-ink/[0.13] bg-ink/[0.03] px-3 text-[13px] font-medium text-ink/70"
                            aria-live="polite"
                        >
                            {{ region ?? '—' }}
                        </p>
                    </FormField>
                </div>
            </div>

            <FormField
                label="WHATSAPP DE CONTACT"
                :error="errors.whatsapp_phone"
                hint="Le réseau APhaSPB l'utilise pour vous rappeler une déclaration manquante."
            >
                <TextInput
                    name="whatsapp_phone"
                    type="tel"
                    :model-value="pharmacy?.whatsapp_phone ?? ''"
                    :invalid="!!errors.whatsapp_phone"
                    placeholder="01 97 00 00 00"
                />
            </FormField>

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
