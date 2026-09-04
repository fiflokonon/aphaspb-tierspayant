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
        <div
            v-if="account.pharmacies.length > 1"
            class="border-t border-ink/[0.08] pt-3"
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

        <div class="border-t border-ink/[0.08] pt-3">
            <div class="truncate text-[11.5px] font-semibold text-ink">
                {{ account.name }}
            </div>

            <LogoutLink :href="account.logoutHref" class="mt-[10px] w-full" />
        </div>
    </div>
</template>
