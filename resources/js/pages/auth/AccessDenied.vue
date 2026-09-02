<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { LockKeyhole } from '@lucide/vue';
import { login } from '@/routes';

defineProps<{
    siteUrl: string;
}>();
</script>

<template>
    <Head title="Accès non autorisé" />

    <div
        class="flex min-h-screen justify-center bg-[#fdf8ef] px-4 py-8 sm:py-14"
    >
        <div class="w-full max-w-[420px]">
            <div class="overflow-hidden rounded-[14px] bg-card shadow-sm">
                <div class="px-[22px] pt-[26px] pb-6">
                    <div
                        class="grid size-9 place-items-center rounded-full bg-ink/[0.06] text-ink/70"
                    >
                        <LockKeyhole class="size-[18px]" />
                    </div>

                    <h1 class="mt-4 font-serif text-[24px]/[1.2] text-ink">
                        Ce compte n'a pas accès au tiers-payant.
                    </h1>

                    <p class="mt-3 text-[13px]/[1.55] text-ink/60">
                        Votre connexion au site de l'APhaSPB a bien fonctionné.
                        Cet espace est en revanche réservé aux officines
                        déclarantes et à l'équipe du réseau : votre compte n'y
                        est pas rattaché, aucune session n'a été ouverte ici.
                    </p>

                    <p class="mt-3 text-[13px]/[1.55] text-ink/60">
                        Si vous tenez une officine et pensez qu'il s'agit d'une
                        erreur, contactez l'APhaSPB pour faire rattacher votre
                        compte.
                    </p>
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-2">
                <a
                    :href="siteUrl"
                    class="flex h-13 items-center justify-center rounded-xl bg-ink text-[14.5px] font-bold text-white"
                >
                    Retour au site de l'APhaSPB
                </a>
                <Link
                    :href="login()"
                    class="flex h-12 items-center justify-center rounded-xl border border-input bg-card text-[13px] font-semibold text-ink/70"
                >
                    Essayer avec un autre compte
                </Link>
            </div>
        </div>
    </div>
</template>
