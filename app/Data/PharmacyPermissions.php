<?php

namespace App\Data;

readonly class PharmacyPermissions
{
    public function __construct(
        public bool $canUpdatePharmacy,
        public bool $canDeletePharmacy,
        public bool $canAddMember,
        public bool $canUpdateMember,
        public bool $canRemoveMember,
        public bool $canCreateInvitation,
        public bool $canCancelInvitation,
    ) {
        //
    }
}
