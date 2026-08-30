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
import { destroy as destroyInvitation } from '@/routes/pharmacies/invitations';
import type { Pharmacy, PharmacyInvitation } from '@/types';

type Props = {
    pharmacy: Pharmacy;
    invitation: PharmacyInvitation | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const processing = ref(false);

const cancelInvitation = () => {
    if (!props.invitation) {
        return;
    }

    router.visit(
        destroyInvitation([props.pharmacy.slug, props.invitation.code]),
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => emit('update:open', false),
        },
    );
};
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Annuler l'invitation</DialogTitle>
                <DialogDescription>
                    Voulez-vous vraiment annuler l'invitation envoyée à
                    <strong>{{ props.invitation?.email }}</strong> ?
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">
                        Conserver l'invitation
                    </Button>
                </DialogClose>

                <Button
                    data-test="cancel-invitation-confirm"
                    variant="destructive"
                    :disabled="processing"
                    @click="cancelInvitation"
                >
                    Annuler l'invitation
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
