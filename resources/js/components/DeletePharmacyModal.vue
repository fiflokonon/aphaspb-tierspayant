<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy } from '@/routes/pharmacies';
import type { Pharmacy } from '@/types';

type Props = {
    pharmacy: Pharmacy;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const confirmationName = ref('');
const formKey = ref(0);

const canDeletePharmacy = computed(() => {
    return confirmationName.value === props.pharmacy.name;
});

const handleOpenChange = (nextOpen: boolean) => {
    emit('update:open', nextOpen);

    if (!nextOpen) {
        confirmationName.value = '';
        formKey.value++;
    }
};
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="destroy.form(props.pharmacy.slug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="handleOpenChange(false)"
            >
                <DialogHeader>
                    <DialogTitle>Supprimer cette officine ?</DialogTitle>
                    <DialogDescription>
                        Cette action est irréversible. L'officine
                        <strong>« {{ props.pharmacy.name }} »</strong> et ses
                        déclarations seront définitivement supprimées.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 py-4">
                    <div class="grid gap-2">
                        <Label for="confirmation-name">
                            Saisissez
                            <strong>« {{ props.pharmacy.name }} »</strong> pour
                            confirmer
                        </Label>
                        <Input
                            id="confirmation-name"
                            name="name"
                            data-test="delete-pharmacy-name"
                            v-model="confirmationName"
                            placeholder="Nom de l'officine"
                            autocomplete="off"
                        />
                        <InputError :message="errors.name" />
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Annuler </Button>
                    </DialogClose>

                    <Button
                        data-test="delete-pharmacy-confirm"
                        variant="destructive"
                        type="submit"
                        :disabled="!canDeletePharmacy || processing"
                    >
                        Supprimer l'officine
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
