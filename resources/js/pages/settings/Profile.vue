<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profil & réglages" />

    <ConsoleHeader eyebrow="MON COMPTE" title="Profil & réglages" />

    <div
        class="mt-5 max-w-[560px] rounded-[11px] border border-border bg-card p-4"
    >
        <div class="text-[12.5px] font-bold text-ink">Identité</div>
        <p class="mt-1 text-[11px]/[1.4] text-ink/[0.45]">
            Ces informations vous identifient auprès de l'APhaSPB.
        </p>

        <Form
            v-bind="ProfileController.update.form()"
            class="mt-4 flex flex-col gap-[11px]"
            #default="{ errors, processing }"
        >
            <FormField label="NOM" :error="errors.name">
                <TextInput
                    name="name"
                    :model-value="user.name"
                    :invalid="!!errors.name"
                    placeholder="Nom et prénom"
                />
            </FormField>

            <FormField label="ADRESSE E-MAIL" :error="errors.email">
                <TextInput
                    name="email"
                    type="email"
                    :model-value="user.email"
                    :invalid="!!errors.email"
                    placeholder="vous@exemple.bj"
                />
            </FormField>

            <p
                v-if="page.props.mustVerifyEmail && !user.email_verified_at"
                class="text-[11px]/[1.5] text-ink/[0.45]"
            >
                Votre adresse n'est pas vérifiée. La vérification est assurée
                par votre compte Joomla, pas ici.
            </p>

            <button
                type="submit"
                :disabled="processing"
                class="mt-[6px] flex h-[46px] items-center justify-center self-start rounded-[10px] bg-primary px-5 text-[12.5px] font-bold text-primary-foreground transition-opacity disabled:opacity-60"
                data-test="update-profile-button"
            >
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </Form>
    </div>
</template>
