<?php

namespace App\Enums;

enum PharmacyPermission: string
{
    case UpdatePharmacy = 'pharmacy:update';
    case DeletePharmacy = 'pharmacy:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';
}
