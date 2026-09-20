<script setup lang="ts">
/**
 * L'identité, les officines et la déconnexion, sur téléphone.
 *
 * Le pied de la barre latérale — qui porte ces trois choses — est masqué sous
 * 1024 px (`.apha-sidebar-footer { display: none }`). Un utilisateur sur
 * téléphone ne pouvait donc ni se déconnecter ni changer d'officine.
 *
 * Ce menu ne s'affiche que sous lg : le rail desktop garde son pied intact, et
 * son `mt-auto` — dont .ai/rules/layouts.md documente la fragilité — n'est pas
 * touché.
 */
import { Link } from '@inertiajs/vue3';
import LogoutLink from '@/components/aphaspb/LogoutLink.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ConsoleAccount } from '@/types/console';

defineProps<{ account: ConsoleAccount }>();
</script>

<template>
    <DropdownMenu>
        <!--
            Le nom, pas les initiales : ce menu ne s'affiche que sous 1024 px,
            où la barre du haut n'existe pas et le pied de la barre latérale
            est masqué. Il y est donc la seule trace de l'identité, et « AH »
            obligeait à déplier pour savoir qui était connecté. Les deux
            ensemble ne tiennent pas sur un téléphone.
        -->
        <DropdownMenuTrigger
            class="flex h-9 min-w-0 shrink items-center rounded-[10px] border border-ink/[0.10] bg-white/80 px-[10px] text-[12px] font-bold text-ink/75 transition-colors hover:bg-cream-header"
            :aria-label="`Compte de ${account.name}`"
        >
            <span class="truncate">{{ account.name }}</span>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel class="truncate">
                {{ account.name }}
            </DropdownMenuLabel>

            <template v-if="account.pharmacies.length > 1">
                <DropdownMenuSeparator />

                <div
                    class="px-2 pt-1 font-mono text-[9.5px] font-semibold tracking-[0.06em] text-ink/45"
                >
                    OFFICINE
                </div>

                <Link
                    v-for="pharmacy in account.pharmacies"
                    :key="pharmacy.slug"
                    :href="pharmacy.switchHref"
                    method="post"
                    as="button"
                    type="button"
                    class="flex min-h-[40px] w-full items-center rounded-md px-2 text-left text-[12px] transition-colors"
                    :class="
                        pharmacy.current
                            ? 'bg-primary/[0.10] font-semibold text-ink'
                            : 'font-medium text-ink/[0.62] hover:bg-ink/[0.05]'
                    "
                >
                    <span class="truncate">{{ pharmacy.name }}</span>
                </Link>
            </template>

            <DropdownMenuSeparator />

            <LogoutLink :href="account.logoutHref" class="mt-1 w-full" />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
