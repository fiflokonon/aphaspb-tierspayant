<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PeriodPicker from '@/components/aphaspb/PeriodPicker.vue';
import type { SelectablePeriod } from '@/types/aphaspb';

const props = defineProps<{
    declared: number;
    period: { year: number; month: number; label: string };
    periods: SelectablePeriod[];
    dashboardUrl: string;
}>();
</script>

<template>
    <Head title="Mois déclaré" />

    <div class="mx-auto w-full max-w-[430px] px-5 pt-10">
        <div
            class="rounded-xl border border-officine/30 bg-officine/[0.08] px-[22px] py-6"
        >
            <div
                class="grid size-9 place-items-center rounded-full bg-officine text-[15px] font-bold text-white"
            >
                ✓
            </div>
            <h1 class="mt-4 font-serif text-[26px]/[1.18] text-ink">
                {{ period.label }} est déclaré.
            </h1>
            <p class="mt-3 text-[13px]/[1.5] text-ink/60">
                {{ props.declared }} déclaration{{
                    props.declared > 1 ? 's' : ''
                }}
                enregistrée{{ props.declared > 1 ? 's' : '' }}. Vous pouvez
                revenir les corriger à tout moment.
            </p>
        </div>

        <div class="mt-4 flex flex-col gap-2">
            <Link
                :href="dashboardUrl"
                class="flex h-13 items-center justify-center rounded-xl bg-ink text-[14.5px] font-bold text-white"
            >
                Voir mon tableau de bord
            </Link>
            <Link
                :href="'/pharmacy/history'"
                class="flex h-12 items-center justify-center rounded-xl border border-input bg-card text-[13px] font-semibold text-ink/70"
            >
                Revoir mes déclarations
            </Link>
        </div>

        <!--
            Without this the screen was a dead end: a month reached from the
            picker and already complete landed here, and the only way to
            another month was back through the dashboard.
        -->
        <div class="mt-6 rounded-xl border border-input bg-card px-[18px] py-4">
            <p class="text-[12px]/[1.5] text-ink/60">
                Un autre mois à déclarer ? Le rattrapage reste possible douze
                mois en arrière.
            </p>

            <PeriodPicker :periods="periods" :current="period" class="mt-3" />
        </div>
    </div>
</template>
