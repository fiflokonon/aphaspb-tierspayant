<script setup lang="ts">
/**
 * A working filter, styled like the canvas's chip.
 *
 * A native <select> rather than a custom dropdown: it is keyboard accessible
 * for free, and on a phone it opens the platform picker instead of a list
 * squeezed into 375 px. The chevron is drawn by the wrapper because native
 * appearance varies too much between browsers to be relied on.
 */
withDefaults(
    defineProps<{
        options: { value: string | number | null; label: string }[];
        size?: 'default' | 'compact';
    }>(),
    { size: 'default' },
);

// Attributes land on the control, not the wrapper: aria-label belongs to the
// select, and so would any future id or form association.
defineOptions({ inheritAttrs: false });

const model = defineModel<string | number | null>({ default: null });
</script>

<template>
    <div
        class="relative flex shrink-0 items-center rounded-[10px] border border-input bg-card"
        :class="size === 'compact' ? 'h-[32px]' : 'h-[42px]'"
    >
        <select
            v-model="model"
            v-bind="$attrs"
            class="h-full w-full cursor-pointer appearance-none bg-transparent pr-7 font-medium whitespace-nowrap text-ink/70 outline-none"
            :class="
                size === 'compact'
                    ? 'pl-[11px] text-[11.5px]'
                    : 'pl-[13px] text-xs'
            "
        >
            <option
                v-for="option in options"
                :key="String(option.value)"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
        <span
            class="pointer-events-none absolute right-[10px] text-[9px] text-ink/45"
        >
            ▾
        </span>
    </div>
</template>
