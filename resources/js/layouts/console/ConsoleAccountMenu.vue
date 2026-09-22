<script setup lang="ts">
/**
 * L'identité, les officines et la déconnexion, à toutes les largeurs.
 *
 * Le seul endroit où vivent les actions de compte : le bandeau supérieur le
 * monte à partir de lg, l'en-tête de la barre latérale en dessous. Le pied du
 * rail les portait auparavant, et disparaissait sous 1024 px — on avait alors
 * le nom en haut à droite et la déconnexion en bas à gauche, pour le même
 * compte.
 */
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
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
            Le nom, pas les initiales : c'est la seule trace de l'identité à
            l'écran, et « AH » obligeait à déplier pour savoir qui était
            connecté.

            Le chevron dit que ça s'ouvre : sans lui, le nom se lit comme une
            étiquette, et la déconnexion devient introuvable — c'est le défaut
            qu'on vient de corriger en la sortant du pied du rail.

            `lg:shrink-0` : dans le bandeau supérieur, c'est la puce d'espace
            qui cède la place quand la rangée est trop courte. Le nom d'une
            personne est court et stable, celui d'une officine est long et
            porte déjà son ellipse. Sous lg, la puce n'est pas dans cette
            rangée et le déclencheur doit pouvoir rétrécir.
        -->
        <DropdownMenuTrigger
            class="flex h-9 min-w-0 shrink items-center gap-[6px] rounded-[10px] border border-ink/[0.10] bg-white/80 px-[10px] text-[13px] font-bold text-ink/75 transition-colors hover:bg-cream-header lg:h-10 lg:shrink-0 lg:text-[14.5px]"
            :aria-label="`Compte de ${account.name}`"
        >
            <span class="truncate">{{ account.name }}</span>

            <ChevronDown class="size-[15px] shrink-0 opacity-45" />
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
