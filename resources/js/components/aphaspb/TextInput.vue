<script setup lang="ts">
withDefaults(
    defineProps<{
        invalid?: boolean;
        placeholder?: string;
        list?: string;
        /** `email` and `tel` earn a phone its right keyboard; nothing else needs one. */
        type?: 'text' | 'email' | 'tel';
    }>(),
    { type: 'text' },
);

const model = defineModel<string>({ default: '' });
</script>

<template>
    <input
        v-model="model"
        :type="type"
        :placeholder="placeholder"
        :list="list"
        class="h-[46px] w-full rounded-[10px] border-[1.5px] bg-card px-3 text-[13px] font-medium text-ink transition-shadow outline-none placeholder:font-normal placeholder:text-ink/[0.35] focus:border-gold-mid/[0.55] focus:shadow-[0_0_0_3px_rgb(217_163_37_/_0.13)]"
        :class="invalid ? 'border-terracotta' : 'border-ink/[0.13]'"
    />
</template>
