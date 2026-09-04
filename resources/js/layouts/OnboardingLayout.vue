<script setup lang="ts">
import LogoutLink from '@/components/aphaspb/LogoutLink.vue';
import { useConsoleShell } from '@/composables/useConsoleShell';

const { account } = useConsoleShell();
</script>

<template>
    <!--
        No console navigation here: the officine does not exist yet, so there is
        nothing to navigate to. A single bounded column, centred at every width.
    -->
    <div
        class="flex min-h-screen justify-center bg-[#fdf8ef] px-4 py-8 sm:py-14"
    >
        <div class="w-full max-w-[420px]">
            <slot />

            <!--
                Onboarding has no rail to hang the account on, but it must not
                be a trap either: someone who arrived on the wrong Joomla
                account needs a way back out.
            -->
            <div
                v-if="account"
                class="mt-4 flex flex-col items-center gap-2 text-[11.5px] text-ink/50"
            >
                <span class="truncate">{{ account.name }}</span>
                <LogoutLink :href="account.logoutHref" />
            </div>
        </div>
    </div>
</template>
