<?php

namespace App\Models;

use App\Authorization\AdministratorFloor;
use App\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person's login, owned by one client company like every other table. Priya
 * employed by both Meridian and Vertex has two accounts and never notices, because
 * each client is a different subdomain and the subdomain resolves the client before
 * anyone authenticates — so nothing ever has to work out which company an address
 * belongs to.
 *
 * This is the login half only. The job — org unit, designation, reporting manager,
 * employment status, all of it dated — arrives in step 4 as employment records
 * pointing here, so a promotion or a rehire adds rows rather than overwriting a
 * column.
 *
 * `tenant_id` is deliberately absent from the fields a form may fill: it is stamped
 * from the client company in scope, never chosen by a submitted field.
 */
#[Fillable(['name', 'work_email', 'password', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A grant goes with the account through a database cascade, which no model
        // event on the grant ever sees. So the two-administrator rule has to be asked
        // here as well as on the grant itself.
        static::deleting(function (self $user): void {
            AdministratorFloor::refuseAccountDeletion($user);
        });
    }

    /**
     * Every role this person holds, each over the whole client company or over one
     * part of its structure.
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * No account outlives the last working day. Documents owed after that date — the
     * relieving letter, the settlement statement, the Form 16 — reach the person on
     * signed links sent to their personal address, which needs no login.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->active;
    }

    /**
     * The address is `work_email`, not `email`. Both the reset flow and outbound mail
     * ask the model rather than reading a column, so naming it plainly costs these two
     * lines and nothing else.
     */
    public function getEmailForPasswordReset(): string
    {
        return (string) $this->work_email;
    }

    public function routeNotificationForMail(): string
    {
        return (string) $this->work_email;
    }
}
