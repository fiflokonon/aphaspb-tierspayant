<script setup lang="ts">
import { computed } from 'vue';
import { useConsoleShell } from '@/composables/useConsoleShell';

const { account } = useConsoleShell();

const space = computed(() => {
    const pharmacy = account.value?.pharmacy ?? null;

    if (pharmacy !== null) {
        return {
            name: pharmacy.name,
            complement: pharmacy.city,
        };
    }

    if (account.value?.administrator === true) {
        return {
            name: 'Espace administrateur',
            complement: 'Réseau des officines',
        };
    }

    return null;
});
</script>

<template>
    <div v-if="space" class="space-chip">
        <!-- <span class="space-icon" aria-hidden="true">
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M3 21h18" />
                <path d="M5 21V9l7-5 7 5v12" />
                <path d="M9 21v-5h6v5" />
                <path d="M8 11h.01" />
                <path d="M12 11h.01" />
                <path d="M16 11h.01" />
            </svg>
        </span> -->

        <!-- <span class="space-content">
            <span class="space-label">Espace de travail</span>

            <span class="space-name">
                {{ space.name }}
            </span>

            <span v-if="space.complement" class="space-city">
                {{ space.complement }}
            </span>
        </span> -->
    </div>
</template>

<style scoped>

.space-chip {
    display: inline-flex;
    align-items: center;
    gap: 10px;

    min-width: 0;
    max-width: 280px;

    padding: 8px 12px;

    border: 1px solid #e2ebe6;
    border-radius: 10px;

    background: #f7faf8;

    font-family: 'Manrope', sans-serif;

    color: #17372d;

    transition: all 0.2s ease;
}

.space-chip:hover {
    background: #f1f7f4;
    border-color: #cfe0d8;
}

.space-dot {
    flex-shrink: 0;

    width: 7px;
    height: 7px;

    border-radius: 50%;

    background: #00664c;

    box-shadow: 0 0 0 4px rgb(0 102 76 / 0.08);
}

.space-name {
    overflow: hidden;

    font-size: 12px;
    font-weight: 700;

    color: #17372d;

    white-space: nowrap;
    text-overflow: ellipsis;
}

.space-complement {
    flex-shrink: 0;

    font-size: 11px;
    font-weight: 500;

    color: #7a8983;
}

/* Icône */

.space-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    flex-shrink: 0;

    width: 34px;
    height: 34px;

    border-radius: 9px;

    background: #00664c;
    color: #ffffff;
}

.space-icon svg {
    width: 17px;
    height: 17px;
}

/* Contenu */

.space-content {
    display: flex;
    flex-direction: column;

    min-width: 0;

    line-height: 1.15;
}

/* Petit libellé */

.space-label {
    margin-bottom: 3px;

    font-size: 9px;
    font-weight: 700;

    letter-spacing: 0.10em;
    text-transform: uppercase;

    color: #6b7d76;
}

/* Nom de l'officine */


/* Ville */

.space-city {
    margin-top: 3px;

    font-size: 11px;
    font-weight: 500;

    color: #71827c;
}

/* Version utilisée dans le bandeau vert */

.space-chip.on-band {
    display: inline-flex;

    max-width: 100%;

    padding: 0;

    border: 0;

    background: transparent;

    box-shadow: none;

    color: #ffffff;
}

.space-chip.on-band:hover {
    background: transparent;
    border-color: transparent;
    box-shadow: none;
}

.space-chip.on-band .space-icon {
    width: 30px;
    height: 30px;

    background: rgb(255 255 255 / 0.14);
    color: #ffffff;
}

.space-chip.on-band .space-label {
    color: rgb(255 255 255 / 0.65);
}

.space-chip.on-band .space-name {
    color: #ffffff;
}

.space-chip.on-band .space-city {
    color: rgb(255 255 255 / 0.70);
}

/* Mobile */

@media (max-width: 640px) {
    .space-chip {
        max-width: 100%;
        padding: 8px 12px 8px 9px;
    }

    .space-icon {
        width: 31px;
        height: 31px;
    }

    .space-name {
        font-size: 12px;
    }

    .space-city {
        font-size: 10px;
    }
}
</style>