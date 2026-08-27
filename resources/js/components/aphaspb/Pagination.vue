<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    page: number;
    lastPage: number;
    from: number | null;
    to: number | null;
    total: number;
    /** What the rows are, for the count line: « déclaration », « officine ». */
    noun: string;
}>();

const emit = defineEmits<{ 'update:page': [page: number] }>();

/**
 * A window of page numbers around the current one, with ellipses.
 *
 * Rendering every page is fine at ten and unusable at two hundred, which is the
 * order the CDC projects for an officine's register over several years.
 */
const pages = computed<(number | '…')[]>(() => {
    const last = props.lastPage;
    const current = props.page;

    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const window = new Set<number>([1, last, current]);

    for (const offset of [-1, 1]) {
        const candidate = current + offset;

        if (candidate > 1 && candidate < last) {
            window.add(candidate);
        }
    }

    const sorted = [...window].sort((a, b) => a - b);
    const out: (number | '…')[] = [];

    sorted.forEach((value, index) => {
        if (index > 0 && value - (sorted[index - 1] as number) > 1) {
            out.push('…');
        }

        out.push(value);
    });

    return out;
});

const go = (page: number) => {
    if (page >= 1 && page <= props.lastPage && page !== props.page) {
        emit('update:page', page);
    }
};
</script>

<template>
    <div
        v-if="total > 0"
        class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
        <p class="text-[11.5px] text-ink/[0.55]">
            {{ from }}–{{ to }} sur {{ total }} {{ noun
            }}{{ total > 1 ? 's' : '' }}
        </p>

        <nav
            v-if="lastPage > 1"
            class="flex items-center gap-1"
            aria-label="Pagination"
        >
            <button
                type="button"
                :disabled="page === 1"
                aria-label="Page précédente"
                class="grid size-9 place-items-center rounded-lg border border-input bg-card text-[12px] text-ink/60 disabled:opacity-40"
                @click="go(page - 1)"
            >
                ‹
            </button>

            <template
                v-for="(entry, index) in pages"
                :key="`${entry}-${index}`"
            >
                <span
                    v-if="entry === '…'"
                    class="grid size-9 place-items-center text-[12px] text-ink/40"
                >
                    …
                </span>
                <button
                    v-else
                    type="button"
                    class="grid size-9 place-items-center rounded-lg border text-[12px] font-semibold transition-colors"
                    :class="
                        entry === page
                            ? 'border-transparent bg-ink text-white'
                            : 'border-input bg-card text-ink/70 hover:bg-cream-header'
                    "
                    :aria-current="entry === page ? 'page' : undefined"
                    @click="go(entry)"
                >
                    {{ entry }}
                </button>
            </template>

            <button
                type="button"
                :disabled="page === lastPage"
                aria-label="Page suivante"
                class="grid size-9 place-items-center rounded-lg border border-input bg-card text-[12px] text-ink/60 disabled:opacity-40"
                @click="go(page + 1)"
            >
                ›
            </button>
        </nav>
    </div>
</template>
