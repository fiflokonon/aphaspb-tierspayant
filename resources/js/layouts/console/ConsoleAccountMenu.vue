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
import { computed } from 'vue';
import LogoutLink from '@/components/aphaspb/LogoutLink.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/composables/useInitials';
import type { ConsoleAccount } from '@/types/console';

const props = defineProps<{ account: ConsoleAccount }>();

const { getInitials } = useInitials();

const initials = computed(() => getInitials(props.account.name));
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            class="grid size-9 shrink-0 place-items-center rounded-[10px] border border-ink/[0.10] bg-white/80 text-[11px] font-bold text-ink/70 transition-colors hover:bg-cream-header"
            :aria-label="`Compte de ${account.name}`"
        >
            {{ initials }}
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

            <LogoutLink
                :href="account.logoutHref"
                class="flex min-h-[40px] w-full items-center rounded-md px-2 text-left text-[12px] font-medium text-ink/[0.62] transition-colors hover:bg-ink/[0.05]"
            />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
