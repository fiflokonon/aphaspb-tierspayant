<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { leave as leavePharmacyAction } from '@/routes/pharmacies';
import type { Pharmacy } from '@/types';

type Props = {
    pharmacy: Pharmacy | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const processing = ref(false);

const leavePharmacy = () => {
    if (!props.pharmacy) {
        return;
    }

    router.visit(leavePharmacyAction(props.pharmacy.slug), {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: () => emit('update:open', false),
    });
};
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Quitter l'officine</DialogTitle>
                <DialogDescription>
                    Voulez-vous vraiment quitter
                    <strong>{{ props.pharmacy?.name }}</strong> ?
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> Annuler </Button>
                </DialogClose>

                <Button
                    data-test="leave-pharmacy-confirm"
                    variant="destructive"
                    :disabled="processing"
                    @click="leavePharmacy"
                >
                    Quitter l'officine
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
