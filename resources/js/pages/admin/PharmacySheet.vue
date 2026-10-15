<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Phone } from '@lucide/vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

defineProps<{
    pharmacy: {
        name: string;
        city: string | null;
        region: string | null;
        ownerName: string | null;
        phone: { href: string; label: string } | null;
        registeredAt: string | null;
    };
    insurers: { id: number; name: string; owesMoney: boolean }[];
}>();
</script>

<template>
    <Head :title="pharmacy.name" />

    <div class="flex flex-col gap-6">
        <ConsoleHeader eyebrow="FICHE OFFICINE" :title="pharmacy.name" />

        <Link
            href="/admin/pharmacies"
            class="flex items-center gap-1 self-start text-[13px] font-semibold text-ink/60 hover:text-ink"
        >
            <ArrowLeft :size="14" aria-hidden="true" />
            Pharmacies inscrites
        </Link>

        <section
            class="grid gap-4 rounded-[var(--radius-card)] border border-ink/[0.06] bg-card p-5 shadow-[var(--surface-shadow)] sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="item in [
                    { label: 'VILLE', value: pharmacy.city },
                    { label: 'RÉGION', value: pharmacy.region },
                    { label: 'TITULAIRE', value: pharmacy.ownerName },
                    { label: 'INSCRITE LE', value: pharmacy.registeredAt },
                ]"
                :key="item.label"
            >
                <div
                    class="font-mono text-[10.5px] font-semibold tracking-[0.05em] text-ink/45"
                >
                    {{ item.label }}
                </div>
                <div class="mt-1 text-[14px] font-semibold text-ink">
                    {{ item.value ?? '—' }}
                </div>
            </div>

            <div>
                <div
                    class="font-mono text-[10.5px] font-semibold tracking-[0.05em] text-ink/45"
                >
                    WHATSAPP
                </div>
                <a
                    v-if="pharmacy.phone"
                    :href="pharmacy.phone.href"
                    class="mt-1 flex items-center gap-1 text-[14px] font-semibold text-primary"
                >
                    <Phone :size="13" aria-hidden="true" />
                    {{ pharmacy.phone.label }}
                </a>
                <div v-else class="mt-1 text-[14px] font-semibold text-ink">
                    —
                </div>
            </div>
        </section>

        <section
            class="rounded-[var(--radius-card)] border border-ink/[0.06] bg-card shadow-[var(--surface-shadow)]"
        >
            <h2
                class="border-b border-ink/[0.06] px-5 py-4 text-[15px] font-bold text-ink"
            >
                Assureurs avec lesquels elle travaille
            </h2>

            <p
                v-if="insurers.length === 0"
                class="px-5 py-4 text-[14px] text-ink/50"
            >
                Aucun assureur déclaré.
            </p>

            <ul v-else>
                <li
                    v-for="insurer in insurers"
                    :key="insurer.id"
                    class="flex items-center gap-3 border-t border-ink/[0.05] px-5 py-3 first:border-t-0"
                >
                    <Link
                        :href="`/admin/insurers/${insurer.id}`"
                        class="flex-1 text-[14px] font-semibold text-ink hover:text-primary"
                    >
                        {{ insurer.name }}
                    </Link>

                    <span
                        v-if="insurer.owesMoney"
                        class="rounded-full bg-terracotta/10 px-[10px] py-[3px] text-[12px] font-semibold text-terracotta-dark"
                    >
                        Lui doit de l'argent
                    </span>
                </li>
            </ul>

            <p
                class="border-t border-ink/[0.06] px-5 py-3 text-[13px] text-ink/45"
            >
                Aucun montant n'est affiché par officine : seuls les totaux
                agrégés, au-delà du seuil d'anonymat, figurent sur la fiche de
                l'assureur.
            </p>
        </section>
    </div>
</template>
