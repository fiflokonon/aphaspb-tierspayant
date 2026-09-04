<?php

namespace App\Models;

use App\Concerns\HasPharmacies;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $joomla_user_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property list<int>|null $joomla_groups
 * @property int $token_version
 * @property int|null $current_pharmacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pharmacy|null $currentPharmacy
 * @property-read Collection<int, Pharmacy> $ownedPharmacies
 * @property-read Collection<int, Membership> $pharmacyMemberships
 * @property-read Collection<int, Pharmacy> $pharmacies
 */
#[Fillable(['joomla_user_id', 'name', 'email', 'joomla_groups', 'token_version', 'current_pharmacy_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPharmacies, Notifiable;

    /**
     * Joomla owns the credentials, so this application has no remember token.
     *
     * @var string
     */
    protected $rememberTokenName = '';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'joomla_groups' => 'array',
            'token_version' => 'integer',
        ];
    }

    /**
     * Laravel never validates a password for this model: authentication is
     * delegated to Joomla and the users table holds no password column.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * Determine whether the user still has to finish setting their officine up.
     *
     * Three things must hold before declaring is possible: an officine exists,
     * it knows its city and owner, and it has ticked at least one insurer.
     */
    public function needsOnboarding(): bool
    {
        $pharmacy = $this->currentPharmacy;

        return $pharmacy === null
            || ! $pharmacy->hasCompleteProfile()
            || $pharmacy->insurers()->doesntExist();
    }

    /**
     * Determine whether the user belongs to any of the given Joomla groups.
     *
     * @param  list<int>  $groups
     */
    public function hasAnyJoomlaGroup(array $groups): bool
    {
        return array_intersect($this->joomla_groups ?? [], $groups) !== [];
    }

    /**
     * The screen this account starts on once a session is open.
     *
     * Both the Joomla callback and the bare root URL have to answer this, and
     * they must not drift: a user who lands somewhere different depending on
     * how they arrived reports it as two separate bugs.
     */
    public function landingRoute(): string
    {
        if ($this->hasAnyJoomlaGroup(config('joomla.groups.admin'))) {
            return route('admin.network');
        }

        if ($this->needsOnboarding()) {
            return route('onboarding.profile');
        }

        return route('dashboard', ['current_pharmacy' => $this->currentPharmacy->slug]);
    }
}
