<?php

namespace App\Models;

use App\Concerns\GeneratesUniquePharmacySlugs;
use App\Enums\PharmacyRole;
use Database\Factories\PharmacyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $onpb_license
 * @property string|null $city
 * @property string|null $owner_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, PharmacyInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 */
#[Fillable(['name', 'slug', 'onpb_license', 'city', 'owner_name'])]
class Pharmacy extends Model
{
    /** @use HasFactory<PharmacyFactory> */
    use GeneratesUniquePharmacySlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Pharmacy $pharmacy) {
            if (empty($pharmacy->slug)) {
                $pharmacy->slug = static::generateUniquePharmacySlug($pharmacy->name);
            }
        });

        static::updating(function (Pharmacy $pharmacy) {
            if ($pharmacy->isDirty('name')) {
                $pharmacy->slug = static::generateUniquePharmacySlug($pharmacy->name, $pharmacy->id);
            }
        });
    }

    /**
     * Get the pharmacy owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', PharmacyRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this pharmacy.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pharmacy_members', 'pharmacy_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this pharmacy.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this pharmacy.
     *
     * @return HasMany<PharmacyInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(PharmacyInvitation::class);
    }

    /**
     * Get the insurers this pharmacy works with.
     *
     * @return BelongsToMany<Insurer, $this>
     */
    public function insurers(): BelongsToMany
    {
        return $this->belongsToMany(Insurer::class);
    }

    /**
     * Determine whether the pharmacy knows who and where it is.
     *
     * The onboarding flow gates on this: a pharmacy created from a Joomla
     * ticket alone has neither a city nor an owner yet.
     */
    public function hasCompleteProfile(): bool
    {
        return filled($this->city) && filled($this->owner_name);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
