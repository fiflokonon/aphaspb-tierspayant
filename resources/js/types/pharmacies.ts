export type PharmacyRole = 'owner' | 'admin' | 'member';

export type Pharmacy = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: PharmacyRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type PharmacyMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: PharmacyRole;
    role_label: string;
};

export type PharmacyInvitation = {
    code: string;
    email: string;
    role: PharmacyRole;
    role_label: string;
    created_at: string;
};

export type PharmacyInvitationContext = {
    code: string;
    pharmacyName: string;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    pharmacy: {
        name: string;
        slug: string;
    };
};

export type PharmacyPermissions = {
    canUpdatePharmacy: boolean;
    canDeletePharmacy: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
};

export type RoleOption = {
    value: PharmacyRole;
    label: string;
};
