<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FormField from '@/components/aphaspb/FormField.vue';
import TextInput from '@/components/aphaspb/TextInput.vue';
import CancelInvitationModal from '@/components/CancelInvitationModal.vue';
import DeletePharmacyModal from '@/components/DeletePharmacyModal.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import RemoveMemberModal from '@/components/RemoveMemberModal.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/composables/useInitials';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { index, update } from '@/routes/pharmacies';
import { update as updateMember } from '@/routes/pharmacies/members';
import type {
    RoleOption,
    Pharmacy,
    PharmacyInvitation,
    PharmacyMember,
    PharmacyPermissions,
} from '@/types';

type Props = {
    pharmacy: Pharmacy;
    members: PharmacyMember[];
    invitations: PharmacyInvitation[];
    permissions: PharmacyPermissions;
    availableRoles: RoleOption[];
};

const props = defineProps<Props>();

const MEMBERS_TEMPLATE = '2fr 1.2fr 1.2fr';
const MEMBERS_COLUMNS = ['MEMBRE', 'RÔLE', 'ACTIONS'];

const INVITATIONS_TEMPLATE = '2fr 1.2fr 1fr 1fr';
const INVITATIONS_COLUMNS = ['E-MAIL', 'RÔLE', 'ENVOYÉE LE', 'ACTIONS'];

const { getInitials } = useInitials();

const inviteDialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const removeMemberDialogOpen = ref(false);
const memberToRemove = ref<PharmacyMember | null>(null);
const cancelInvitationDialogOpen = ref(false);
const invitationToCancel = ref<PharmacyInvitation | null>(null);

const sentOn = (iso: string) => new Date(iso).toLocaleDateString('fr-FR');

const updateMemberRole = (member: PharmacyMember, newRole: string) => {
    router.visit(updateMember([props.pharmacy.slug, member.id]), {
        data: { role: newRole },
        preserveScroll: true,
    });
};

const confirmRemoveMember = (member: PharmacyMember) => {
    memberToRemove.value = member;
    removeMemberDialogOpen.value = true;
};

const confirmCancelInvitation = (invitation: PharmacyInvitation) => {
    invitationToCancel.value = invitation;
    cancelInvitationDialogOpen.value = true;
};
</script>

<template>
    <Head :title="pharmacy.name" />

    <ConsoleHeader eyebrow="MON COMPTE" :title="pharmacy.name" />

    <Link
        :href="index()"
        class="mt-2 inline-flex min-h-[44px] items-center text-[11.5px] font-semibold text-ink/[0.55] transition-colors hover:text-ink"
    >
        ← Mes officines
    </Link>

    <div
        v-if="permissions.canUpdatePharmacy"
        class="mt-1 max-w-[560px] rounded-[11px] border border-border bg-card p-4"
    >
        <div class="text-[12.5px] font-bold text-ink">Officine</div>
        <p class="mt-1 text-[11px]/[1.4] text-ink/[0.45]">
            Le nom affiché à vos membres et dans vos déclarations.
        </p>

        <Form
            v-bind="update.form(pharmacy.slug)"
            class="mt-4 flex flex-col gap-[11px]"
            #default="{ errors, processing }"
        >
            <FormField label="NOM DE L'OFFICINE" :error="errors.name">
                <TextInput
                    name="name"
                    data-test="pharmacy-name-input"
                    :model-value="pharmacy.name"
                    :invalid="!!errors.name"
                    placeholder="Pharmacie Le Bon Secours"
                />
            </FormField>

            <button
                type="submit"
                data-test="pharmacy-save-button"
                :disabled="processing"
                class="mt-[6px] flex h-[46px] items-center justify-center self-start rounded-[10px] bg-primary px-5 text-[12.5px] font-bold text-primary-foreground transition-opacity disabled:opacity-60"
            >
                {{ processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </Form>
    </div>

    <DataTable
        title="Membres"
        :columns="MEMBERS_COLUMNS"
        :template="MEMBERS_TEMPLATE"
    >
        <template #filters>
            <button
                v-if="permissions.canCreateInvitation"
                type="button"
                data-test="invite-member-button"
                class="flex h-[34px] items-center rounded-lg border border-ink/[0.13] px-3 text-[11.5px] font-semibold text-ink transition-colors hover:bg-ink/[0.04]"
                @click="inviteDialogOpen = true"
            >
                + Inviter un membre
            </button>
        </template>

        <DataTableRow
            v-for="member in members"
            :key="member.id"
            :template="MEMBERS_TEMPLATE"
            data-test="member-row"
        >
            <div class="flex min-w-0 items-center gap-2">
                <Avatar class="size-7 shrink-0">
                    <AvatarImage
                        v-if="member.avatar"
                        :src="member.avatar"
                        :alt="member.name"
                    />
                    <AvatarFallback class="text-[10px]">
                        {{ getInitials(member.name) }}
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <div class="truncate">{{ member.name }}</div>
                    <div
                        class="truncate text-[11px] font-medium text-ink/[0.45]"
                    >
                        {{ member.email }}
                    </div>
                </div>
            </div>

            <div class="font-medium text-ink/60">{{ member.role_label }}</div>

            <div class="flex flex-wrap items-center gap-3 text-[11.5px]">
                <DropdownMenu
                    v-if="
                        member.role !== 'owner' && permissions.canUpdateMember
                    "
                >
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            data-test="member-role-trigger"
                            class="flex h-[30px] items-center gap-1 rounded-lg border border-ink/[0.13] px-[9px] text-[11.5px] font-semibold text-ink transition-colors hover:bg-ink/[0.04]"
                        >
                            Changer de rôle
                            <span class="opacity-50" aria-hidden="true">▾</span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent>
                        <DropdownMenuItem
                            v-for="role in availableRoles"
                            :key="role.value"
                            data-test="member-role-option"
                            @click="updateMemberRole(member, role.value)"
                        >
                            {{ role.label }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <button
                    v-if="
                        member.role !== 'owner' && permissions.canRemoveMember
                    "
                    type="button"
                    data-test="member-remove-button"
                    class="font-semibold text-terracotta-dark underline underline-offset-2 hover:text-terracotta"
                    @click="confirmRemoveMember(member)"
                >
                    Retirer
                </button>
            </div>
        </DataTableRow>
    </DataTable>

    <DataTable
        v-if="invitations.length > 0"
        title="Invitations en attente"
        :columns="INVITATIONS_COLUMNS"
        :template="INVITATIONS_TEMPLATE"
        footer="Une invitation non acceptée expire automatiquement."
    >
        <DataTableRow
            v-for="invitation in invitations"
            :key="invitation.code"
            :template="INVITATIONS_TEMPLATE"
            data-test="invitation-row"
        >
            <div class="truncate">{{ invitation.email }}</div>
            <div class="font-medium text-ink/60">
                {{ invitation.role_label }}
            </div>
            <div class="font-mono text-[11px] text-ink/60">
                {{ sentOn(invitation.created_at) }}
            </div>
            <div class="text-[11.5px]">
                <button
                    v-if="permissions.canCancelInvitation"
                    type="button"
                    data-test="invitation-cancel-button"
                    class="font-semibold text-terracotta-dark underline underline-offset-2 hover:text-terracotta"
                    @click="confirmCancelInvitation(invitation)"
                >
                    Annuler
                </button>
            </div>
        </DataTableRow>
    </DataTable>

    <div
        v-if="permissions.canDeletePharmacy"
        class="mt-[22px] max-w-[560px] rounded-[11px] border border-terracotta/[0.35] bg-card p-4"
    >
        <div class="text-[12.5px] font-bold text-terracotta-dark">
            Supprimer l'officine
        </div>
        <p class="mt-1 text-[11px]/[1.4] text-ink/[0.45]">
            L'officine et ses déclarations disparaissent définitivement. Cette
            action est irréversible.
        </p>

        <button
            type="button"
            data-test="delete-pharmacy-button"
            class="mt-3 flex h-[42px] items-center rounded-[10px] bg-terracotta-dark px-4 text-[12.5px] font-bold text-white transition-opacity hover:opacity-90"
            @click="deleteDialogOpen = true"
        >
            Supprimer l'officine
        </button>
    </div>

    <InviteMemberModal
        v-if="permissions.canCreateInvitation"
        :pharmacy="pharmacy"
        :available-roles="availableRoles"
        :open="inviteDialogOpen"
        @update:open="inviteDialogOpen = $event"
    />

    <RemoveMemberModal
        :pharmacy="pharmacy"
        :member="memberToRemove"
        :open="removeMemberDialogOpen"
        @update:open="removeMemberDialogOpen = $event"
    />

    <CancelInvitationModal
        :pharmacy="pharmacy"
        :invitation="invitationToCancel"
        :open="cancelInvitationDialogOpen"
        @update:open="cancelInvitationDialogOpen = $event"
    />

    <DeletePharmacyModal
        v-if="permissions.canDeletePharmacy"
        :pharmacy="pharmacy"
        :open="deleteDialogOpen"
        @update:open="deleteDialogOpen = $event"
    />
</template>
