<script setup lang="ts">
import LogoutLink from '@/components/aphaspb/LogoutLink.vue';
import type { ConsoleAccount } from '@/types/console';

defineProps<{ account: ConsoleAccount }>();
</script>

<template>
    <div class="mt-auto border-t border-white/[0.12] pt-3">
        <div class="truncate text-[11.5px] font-semibold text-white/80">
            {{ account.name }}
        </div>

        <LogoutLink
            :href="account.logoutHref"
            class="mt-[2px] flex min-h-[44px] w-full items-center text-left text-[11.5px] font-medium text-white/[0.55] transition-colors hover:text-white"
        />
    </div>
</template>
