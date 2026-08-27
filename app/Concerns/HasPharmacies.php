<?php

namespace App\Concerns;

use App\Data\PharmacyPermissions;
use App\Data\UserPharmacy;
use App\Enums\PharmacyPermission;
use App\Enums\PharmacyRole;
use App\Models\Membership;
use App\Models\Pharmacy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

trait HasPharmacies
{
    /**
     * Get all of the pharmacies the user belongs to.
     *
     * @return BelongsToMany<Pharmacy, $this>
     */
    public function pharmacies(): BelongsToMany
    {
        return $this->belongsToMany(Pharmacy::class, 'pharmacy_members', 'user_id', 'pharmacy_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all of the pharmacies the user owns.
     *
     * @return HasManyThrough<Pharmacy, Membership, $this>
     */
    public function ownedPharmacies(): HasManyThrough
    {
        return $this->hasManyThrough(
            Pharmacy::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'pharmacy_id',
        )->where('pharmacy_members.role', PharmacyRole::Owner->value);
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function pharmacyMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current pharmacy.
     *
     * @return BelongsTo<Pharmacy, $this>
     */
    public function currentPharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class, 'current_pharmacy_id');
    }

    /**
     * Get the user's personal pharmacy.
     */
    public function personalPharmacy(): ?Pharmacy
    {
        return $this->pharmacies()
            ->where('is_personal', true)
            ->first();
    }

    /**
     * Switch to the given pharmacy.
     */
    public function switchPharmacy(Pharmacy $pharmacy): bool
    {
        if (! $this->belongsToPharmacy($pharmacy)) {
            return false;
        }

        $this->update(['current_pharmacy_id' => $pharmacy->id]);
        $this->setRelation('currentPharmacy', $pharmacy);

        URL::defaults(['current_pharmacy' => $pharmacy->slug]);

        return true;
    }

    /**
     * Determine if the user belongs to the given pharmacy.
     */
    public function belongsToPharmacy(Pharmacy $pharmacy): bool
    {
        return $this->pharmacies()->where('pharmacies.id', $pharmacy->id)->exists();
    }

    /**
     * Determine if the given pharmacy is the user's current pharmacy.
     */
    public function isCurrentPharmacy(Pharmacy $pharmacy): bool
    {
        return $this->current_pharmacy_id === $pharmacy->id;
    }

    /**
     * Determine if the user is the owner of the given pharmacy.
     */
    public function ownsPharmacy(Pharmacy $pharmacy): bool
    {
        return $this->pharmacyRole($pharmacy) === PharmacyRole::Owner;
    }

    /**
     * Get the user's role on the given pharmacy.
     */
    public function pharmacyRole(Pharmacy $pharmacy): ?PharmacyRole
    {
        return $this->pharmacyMemberships()
            ->where('pharmacy_id', $pharmacy->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's pharmacies as a collection of UserPharmacy objects.
     *
     * @return Collection<int, UserPharmacy>
     */
    public function toUserPharmacies(bool $includeCurrent = false): Collection
    {
        return $this->pharmacies()
            ->get()
            ->map(fn (Pharmacy $pharmacy) => ! $includeCurrent && $this->isCurrentPharmacy($pharmacy) ? null : $this->toUserPharmacy($pharmacy))
            ->filter()
            ->values();
    }

    /**
     * Get the user's pharmacy as a UserPharmacy object.
     */
    public function toUserPharmacy(Pharmacy $pharmacy): UserPharmacy
    {
        $role = $this->pharmacyRole($pharmacy);

        return new UserPharmacy(
            id: $pharmacy->id,
            name: $pharmacy->name,
            slug: $pharmacy->slug,
            isPersonal: $pharmacy->is_personal,
            role: $role?->value,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentPharmacy($pharmacy),
        );
    }

    /**
     * Get the standard permissions for a pharmacy as a PharmacyPermissions object.
     */
    public function toPharmacyPermissions(Pharmacy $pharmacy): PharmacyPermissions
    {
        $role = $this->pharmacyRole($pharmacy);

        return new PharmacyPermissions(
            canUpdatePharmacy: $role?->hasPermission(PharmacyPermission::UpdatePharmacy) ?? false,
            canDeletePharmacy: $role?->hasPermission(PharmacyPermission::DeletePharmacy) ?? false,
            canAddMember: $role?->hasPermission(PharmacyPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(PharmacyPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(PharmacyPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(PharmacyPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(PharmacyPermission::CancelInvitation) ?? false,
        );
    }

    public function fallbackPharmacy(?Pharmacy $excluding = null): ?Pharmacy
    {
        return $this->pharmacies()
            ->when($excluding, fn ($query) => $query->where('pharmacies.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(pharmacies.name)')
            ->first();
    }

    /**
     * Determine if the user has the given permission on the pharmacy.
     */
    public function hasPharmacyPermission(Pharmacy $pharmacy, PharmacyPermission $permission): bool
    {
        return $this->pharmacyRole($pharmacy)?->hasPermission($permission) ?? false;
    }
}
