<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { SelectablePeriod } from '@/types/aphaspb';

const props = defineProps<{
    periods: SelectablePeriod[];
    current: { year: number; month: number };
}>();

/**
 * Changing month navigates rather than submitting.
 *
 * The picker is rendered inside the declaration form, so it deliberately
 * carries no `name`: a named control would be serialised with the amounts and
 * the server would see a field it never asked for. What it changes is the
 * round being displayed, and the round is rebuilt from what is stored — so
 * anything typed and not yet saved is dropped, which is why this sits above
 * the amounts rather than beside the save button.
 */
const switchPeriod = (event: Event) => {
    const chosen = (event.target as HTMLSelectElement).value;
    const target = props.periods.find(
        (period) => `${period.year}-${period.month}` === chosen,
    );

    if (target) {
        router.visit(target.url);
    }
};
</script>

<template>
    <div class="flex flex-col gap-[6px]">
        <label
            for="declared-month"
            class="block font-mono text-[10.5px]/none font-semibold tracking-[0.05em] text-ink/[0.45]"
        >
            MOIS DÉCLARÉ
        </label>

        <select
            id="declared-month"
            :value="`${current.year}-${current.month}`"
            class="h-[46px] w-full rounded-[10px] border-[1.5px] border-ink/[0.13] bg-card px-3 text-[13px] font-semibold text-ink outline-none focus:border-gold-mid/[0.55]"
            @change="switchPeriod"
        >
            <option
                v-for="period in periods"
                :key="period.url"
                :value="`${period.year}-${period.month}`"
            >
                {{ period.label
                }}{{ period.isComplete ? ' · déjà déclaré' : '' }}
            </option>
        </select>
    </div>
</template>
