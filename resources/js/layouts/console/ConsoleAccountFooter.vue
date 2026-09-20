<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LogoutLink from '@/components/aphaspb/LogoutLink.vue';
import type { ConsoleAccount } from '@/types/console';

defineProps<{ account: ConsoleAccount }>();
</script>

<template>
    <div class="mt-auto flex flex-col gap-3 pt-4">
        <!--
            Listed in place rather than behind a floating dropdown: the rail is
            212px of flat colour, and a menu that escapes it reads as belonging
            to another application. Absent below two officines — nothing to
            choose.
        -->
        <!--
            `account-switcher` n'a pas de style : elle existe pour que le rail
            replié puisse masquer ce bloc entier. Sans elle, ses liens — qui
            portent `w-full` comme le bouton de déconnexion — se réduisaient à
            des pastilles de 44 px sans libellé, qui changent pourtant
            d'officine au clic.
        -->
        <div
            v-if="account.pharmacies.length > 1"
            class="account-switcher border-t border-ink/[0.08] pt-3"
        >
            <div
                class="font-mono text-[9.5px] font-semibold tracking-[0.06em] text-ink/45"
            >
                OFFICINE
            </div>
            <div class="mt-[6px] flex flex-col gap-[2px]">
                <Link
                    v-for="pharmacy in account.pharmacies"
                    :key="pharmacy.slug"
                    :href="pharmacy.switchHref"
                    method="post"
                    as="button"
                    type="button"
                    class="flex min-h-[44px] w-full items-center rounded-lg px-[9px] text-left text-[11.5px] transition-colors"
                    :class="
                        pharmacy.current
                            ? 'bg-primary/[0.10] font-semibold text-ink'
                            : 'font-medium text-ink/[0.62] hover:bg-ink/[0.05]'
                    "
                >
                    <span class="truncate">{{ pharmacy.name }}</span>
                </Link>
            </div>
        </div>

        <!--
            Le nom a rejoint la barre du haut : ici il était en 11,5 px sous la
            navigation, et invisible sous 1024 px où ce pied est masqué. Le
            garder aux deux endroits créerait deux identités concurrentes.
        -->
        <div class="border-t border-ink/[0.08] pt-3">
            <LogoutLink :href="account.logoutHref" class="w-full" />
        </div>
    </div>
</template>
