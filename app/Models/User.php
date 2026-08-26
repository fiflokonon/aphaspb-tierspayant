<?php

namespace App\Models;

use App\Concerns\HasTeams;
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
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 */
#[Fillable(['joomla_user_id', 'name', 'email', 'joomla_groups', 'token_version', 'current_team_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable;

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
     * Determine whether the user belongs to any of the given Joomla groups.
     *
     * @param  list<int>  $groups
     */
    public function hasAnyJoomlaGroup(array $groups): bool
    {
        return array_intersect($this->joomla_groups ?? [], $groups) !== [];
    }
}
