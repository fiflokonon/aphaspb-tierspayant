<script setup lang="ts">
/**
 * Plusieurs régions pharmaceutiques à la fois, dans une puce de filtre.
 *
 * Même habit que FilterSelect, mais un menu à cases plutôt qu'un <select> :
 * un select multiple natif est inutilisable à la souris comme sur un
 * téléphone. Le menu reste ouvert pendant qu'on coche (`@select.prevent`) ;
 * chaque case émet aussitôt la nouvelle sélection, à charge pour la page de
 * recharger ses props.
 *
 * Le serveur fait primer une ville choisie sur les régions : la page vide
 * l'une quand on choisit l'autre, pour que l'écran dise ce qui est filtré.
 */
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const props = withDefaults(
    defineProps<{
        regions: { id: number; name: string }[];
        size?: 'default' | 'compact';
        label?: string;
    }>(),
    { size: 'default', label: 'Régions' },
);

const model = defineModel<number[]>({ default: () => [] });

const summary = computed(() => {
    const chosen = props.regions.filter((region) =>
        model.value.includes(region.id),
    );

    if (chosen.length === 0) {
        return props.label ? 'Toutes' : 'Toutes les régions';
    }

    return chosen.length <= 2
        ? chosen.map((region) => region.name).join(', ')
        : `${chosen.length} régions`;
});

function toggle(id: number, checked: boolean | 'indeterminate') {
    model.value =
        checked === true
            ? [...model.value, id]
            : model.value.filter((one) => one !== id);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            :disabled="regions.length === 0"
            class="relative flex shrink-0 cursor-pointer items-baseline rounded-[10px] border border-input bg-card pr-7 whitespace-nowrap outline-none disabled:cursor-not-allowed disabled:opacity-50"
            :class="size === 'compact' ? 'h-[32px]' : 'h-[42px]'"
            :aria-label="`Filtrer par régions : ${summary}`"
        >
            <span
                v-if="label"
                class="shrink-0 self-center font-mono font-bold tracking-[0.05em] uppercase"
                :class="
                    size === 'compact'
                        ? 'pl-[11px] text-[9px]'
                        : 'pl-[13px] text-[12px]'
                "
            >
                {{ label }}
            </span>
            <span
                class="self-center"
                :class="[
                    size === 'compact' ? 'text-[11.5px]' : 'text-[15px]',
                    label ? 'pl-[6px]' : 'pl-[13px]',
                ]"
            >
                {{ summary }}
            </span>
            <span
                class="pointer-events-none absolute right-[10px] self-center text-[9px] text-ink/45"
            >
                ▾
            </span>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="max-h-[320px] min-w-[220px]">
            <DropdownMenuCheckboxItem
                v-for="region in regions"
                :key="region.id"
                :model-value="model.includes(region.id)"
                @update:model-value="toggle(region.id, $event)"
                @select.prevent
            >
                {{ region.name }}
            </DropdownMenuCheckboxItem>

            <template v-if="model.length > 0">
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="model = []">
                    Toutes les régions
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
