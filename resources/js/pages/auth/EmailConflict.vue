<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { UserRoundX } from '@lucide/vue';
import { login } from '@/routes';

defineProps<{
    siteUrl: string;
}>();
</script>

<template>
    <Head title="Compte en conflit" />

    <div
        class="flex min-h-screen justify-center bg-[#fdf8ef] px-4 py-8 sm:py-14"
    >
        <div class="w-full max-w-[420px]">
            <div class="overflow-hidden rounded-[14px] bg-card shadow-sm">
                <div class="px-[22px] pt-[26px] pb-6">
                    <div
                        class="grid size-9 place-items-center rounded-full bg-ink/[0.06] text-ink/70"
                    >
                        <UserRoundX class="size-[18px]" />
                    </div>

                    <h1 class="mt-4 font-serif text-[24px]/[1.2] text-ink">
                        Cette adresse e-mail est déjà rattachée à un autre
                        compte.
                    </h1>

                    <p class="mt-3 text-[13px]/[1.55] text-ink/60">
                        Votre connexion au site de l'APhaSPB a bien fonctionné.
                        Un espace tiers-payant existe cependant déjà sous cette
                        adresse, ouvert depuis un autre compte du site. Par
                        prudence, rien n'a été modifié ici et aucune session n'a
                        été ouverte : deux comptes distincts ne peuvent pas
                        partager les déclarations d'une même officine.
                    </p>

                    <p class="mt-3 text-[13px]/[1.55] text-ink/60">
                        Contactez l'APhaSPB pour faire rapprocher les deux
                        comptes. Si vous disposez encore de l'ancien, vous
                        pouvez aussi vous connecter avec celui-là.
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
