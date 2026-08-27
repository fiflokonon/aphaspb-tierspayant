<script setup lang="ts"></script>

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
        </div>
    </div>
</template>
