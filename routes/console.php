<?php

use App\Models\PharmacyInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    PharmacyInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired pharmacy invitations');
