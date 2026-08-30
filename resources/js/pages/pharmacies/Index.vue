<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import CreatePharmacyModal from '@/components/CreatePharmacyModal.vue';
import LeavePharmacyModal from '@/components/LeavePharmacyModal.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { edit } from '@/routes/pharmacies';
import type { Pharmacy } from '@/types';

defineProps<{ pharmacies: Pharmacy[] }>();

const TEMPLATE = '2fr 1fr 1.2fr';
const COLUMNS = ['OFFICINE', 'RÔLE', 'ACTIONS'];

const leavePharmacyDialogOpen = ref(false);
const pharmacyLeaving = ref<Pharmacy | null>(null);

const canLeavePharmacy = (pharmacy: Pharmacy) => pharmacy.role !== 'owner';

const openLeavePharmacyDialog = (pharmacy: Pharmacy) => {
    pharmacyLeaving.value = pharmacy;
    leavePharmacyDialogOpen.value = true;
};
</script>

<template>
    <Head title="Mes officines" />

    <ConsoleHeader eyebrow="MON COMPTE" title="Mes officines">
        <template #action>
            <CreatePharmacyModal>
                <button
                    type="button"
                    data-test="pharmacies-new-pharmacy-button"
                    class="flex h-[42px] items-center justify-center rounded-[10px] bg-primary px-4 text-[12.5px] font-bold text-primary-foreground transition-colors hover:bg-officine-dark"
                >
                    + Nouvelle officine
                </button>
            </CreatePharmacyModal>
        </template>
    </ConsoleHeader>

    <DataTable
        title="Officines dont vous êtes membre"
        :columns="COLUMNS"
        :template="TEMPLATE"
    >
        <DataTableRow
            v-for="pharmacy in pharmacies"
            :key="pharmacy.id"
            :template="TEMPLATE"
            data-test="pharmacy-row"
        >
            <div class="truncate">{{ pharmacy.name }}</div>
            <div class="font-medium text-ink/60">{{ pharmacy.roleLabel }}</div>
            <div class="flex flex-wrap items-center gap-3 text-[11.5px]">
                <Link
                    :href="edit(pharmacy.slug)"
                    :data-test="
                        pharmacy.role === 'member'
                            ? 'pharmacy-view-button'
                            : 'pharmacy-edit-button'
                    "
                    class="font-semibold text-officine underline underline-offset-2 hover:text-officine-dark"
                >
                    {{ pharmacy.role === 'member' ? 'Ouvrir' : 'Modifier' }}
                </Link>
                <button
                    v-if="canLeavePharmacy(pharmacy)"
                    type="button"
                    data-test="pharmacy-leave-button"
                    class="font-semibold text-terracotta-dark underline underline-offset-2 hover:text-terracotta"
                    @click="openLeavePharmacyDialog(pharmacy)"
                >
                    Quitter
                </button>
            </div>
        </DataTableRow>

        <p
            v-if="pharmacies.length === 0"
            class="border-t border-ink/[0.06] px-4 py-8 text-center text-[12px] text-ink/[0.45]"
        >
            Vous n'appartenez à aucune officine pour l'instant.
        </p>
    </DataTable>

    <LeavePharmacyModal
        v-model:open="leavePharmacyDialogOpen"
        :pharmacy="pharmacyLeaving"
    />
</template>
