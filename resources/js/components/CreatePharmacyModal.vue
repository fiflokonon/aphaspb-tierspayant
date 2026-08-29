<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/pharmacies';

const open = ref(false);
const formKey = ref(0);

function handleOpenChange(value: boolean) {
    open.value = value;

    if (!value) {
        formKey.value++;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="store.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <DialogHeader>
                    <DialogTitle>Nouvelle officine</DialogTitle>
                    <DialogDescription>
                        Créez une officine pour y travailler à plusieurs.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="name">Nom de l'officine</Label>
                    <Input
                        id="name"
                        name="name"
                        data-test="create-pharmacy-name"
                        placeholder="Pharmacie Le Bon Secours"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Annuler </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="create-pharmacy-submit"
                        :disabled="processing"
                    >
                        Créer l'officine
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
