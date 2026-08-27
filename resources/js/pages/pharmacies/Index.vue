<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Eye, LogOut, Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import CreatePharmacyModal from '@/components/CreatePharmacyModal.vue';
import Heading from '@/components/Heading.vue';
import LeavePharmacyModal from '@/components/LeavePharmacyModal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { edit, index } from '@/routes/pharmacies';
import type { Pharmacy } from '@/types';

type Props = {
    pharmacies: Pharmacy[];
};

defineProps<Props>();

const leavePharmacyDialogOpen = ref(false);
const pharmacyLeaving = ref<Pharmacy | null>(null);

const canLeavePharmacy = (pharmacy: Pharmacy) => !pharmacy.isPersonal && pharmacy.role !== 'owner';

const openLeavePharmacyDialog = (pharmacy: Pharmacy) => {
    pharmacyLeaving.value = pharmacy;
    leavePharmacyDialogOpen.value = true;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Pharmacies',
                href: index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Pharmacies" />

    <h1 class="sr-only">Pharmacies</h1>

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                variant="small"
                title="Pharmacies"
                description="Manage your pharmacies and pharmacy memberships"
            />

            <CreatePharmacyModal>
                <Button data-test="pharmacies-new-pharmacy-button">
                    <Plus /> New pharmacy
                </Button>
            </CreatePharmacyModal>
        </div>

        <div class="space-y-3">
            <div
                v-for="pharmacy in pharmacies"
                :key="pharmacy.id"
                data-test="pharmacy-row"
                class="flex items-center justify-between gap-4 rounded-lg border p-4"
            >
                <div class="flex items-center gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ pharmacy.name }}</span>
                            <Badge v-if="pharmacy.isPersonal" variant="secondary">
                                Personal
                            </Badge>
                        </div>
                        <span class="text-sm text-muted-foreground">
                            {{ pharmacy.roleLabel }}
                        </span>
                    </div>
                </div>

                <TooltipProvider>
                    <div class="flex items-center gap-2">
                        <Tooltip v-if="canLeavePharmacy(pharmacy)">
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="pharmacy-leave-button"
                                    variant="ghost"
                                    size="sm"
                                    @click="openLeavePharmacyDialog(pharmacy)"
                                >
                                    <LogOut class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>Leave pharmacy</p>
                            </TooltipContent>
                        </Tooltip>

                        <Tooltip v-if="pharmacy.role === 'member'">
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="pharmacy-view-button"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="edit(pharmacy.slug)">
                                        <Eye class="h-4 w-4" />
                                    </Link>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>View pharmacy</p>
                            </TooltipContent>
                        </Tooltip>

                        <Tooltip v-else>
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="pharmacy-edit-button"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="edit(pharmacy.slug)">
                                        <Pencil class="h-4 w-4" />
                                    </Link>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>Edit pharmacy</p>
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </TooltipProvider>
            </div>

            <p
                v-if="pharmacies.length === 0"
                class="py-8 text-center text-muted-foreground"
            >
                You don't belong to any pharmacies yet.
            </p>
        </div>
    </div>

    <LeavePharmacyModal v-model:open="leavePharmacyDialogOpen" :pharmacy="pharmacyLeaving" />
</template>
