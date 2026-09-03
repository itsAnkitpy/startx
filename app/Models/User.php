<?php

namespace App\Models;

use App\Authorization\AdministratorFloor;
use App\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person's login, owned by one client company like every other table. Priya
 * employed by both Meridian and Vertex has two accounts and never notices, because
 * each client is a different subdomain and the subdomain resolves the client before
 * anyone authenticates — so nothing ever has to work out which company an address
 * belongs to.
 *
 * This holds who somebody is, and nothing about their job: the department, the
 * manager and the employment status are dated history in {@see EmploymentRecord}, so a
 * promotion or a rehire adds rows rather than overwriting a column.
 *
 * The name is held in parts because letters, statutory forms and the directory handoff
 * in module 11 all ask for the parts. {@see name()} puts them back together for
 * anything that just wants to show a person.
 *
 * The durable address is the personal one, not the work one: Deel treats it the same
 * way, and every document owed after the last working day — the relieving letter, the
 * settlement statement — has to reach somebody whose account is already closed.
 *
 * `tenant_id` is deliberately absent from the fields a form may fill: it is stamped
 * from the client company in scope, never chosen by a submitted field.
 */
#[Fillable([
    'first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth',
    'work_email', 'personal_email', 'personal_phone', 'password', 'timezone', 'locale', 'active',
])]
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
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'active' => 'boolean',
            'deactivated_at' => 'datetime',
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

        // So `deactivated_at` can never disagree with `active`. A rehire clears it,
        // because a rehire is this same account picking up new employment rows.
        static::saving(function (self $user): void {
            if ($user->isDirty('active')) {
                $user->deactivated_at = $user->active ? null : now();
            }
        });
    }

    /**
     * The full name, assembled from its parts. Nothing stores it, so the parts cannot
     * drift out of step with it.
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter([
            $this->first_name, $this->middle_name, $this->last_name,
        ])));
    }

    /**
     * Everybody at this client company who can still sign in, ready for a picker.
     *
     * Assembled here rather than in a screen because the full name is not a column — it
     * is put together from three of them — so a picker cannot be filled from a
     * relationship the way every other one in the product is. Two screens now ask the
     * same question: who a role is granted to, and who is away or covering.
     *
     * Not narrowed to any part of the company, deliberately and in both places. Somebody
     * sitting in Pune can perfectly well hold a role covering Shimla, or cover a Shimla
     * manager's approvals for a fortnight, so the part of the company somebody sits in is
     * not what either question is about. It carries the same known cost the "Reports to"
     * box on a person's job carries: somebody responsible for one branch reads every name
     * in the company here. Revisit when a client has thousands of staff, not before.
     *
     * @return array<int, string>
     */
    public static function everybodyHere(): array
    {
        return static::query()
            ->where('active', true)
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (self $person): array => [$person->getKey() => $person->name])
            ->all();
    }

    /**
     * Every job this person has held here, newest first. A promotion, a transfer or a
     * rehire is a row in this list.
     */
    public function employmentRecords(): HasMany
    {
        return $this->hasMany(EmploymentRecord::class)->orderByDesc('effective_from');
    }

    /**
     * The job that is true today. Null for somebody with an account and no job row,
     * which is a legal state — every account created before employment records existed
     * is one.
     */
    public function currentEmployment(): HasOne
    {
        return $this->hasOne(EmploymentRecord::class)->whereNull('effective_to');
    }

    /**
     * The job that was true on a given date, which is what any question about the past
     * has to read instead of the job that is true now.
     */
    public function employmentAsOf(DateTimeInterface|string $date): ?EmploymentRecord
    {
        return $this->employmentRecords()->asOf($date)->first();
    }

    /**
     * The part of the client company's structure this person belongs to, which every
     * permission question about them is narrowed by. Their most recent job row — not
     * only the row that is true today.
     *
     * The difference is the whole point of the method. A leaver has no row that is true
     * today, and reading only the current row answered "nowhere" for them, which the
     * permission check treats as "do not narrow this question at all". So the day after
     * Rakesh left Retail, an HR head responsible for Freight alone could open his file,
     * edit it and read his bank account — none of which they could do the day before.
     * Exits are the flow this product is sold on, so that was the record it guarded
     * worst.
     *
     * Null only for somebody who has never held a job row here: every account before
     * its first one is written, and every account whose only row was withdrawn. That
     * case falls back to holding the action anywhere, and has to.
     */
    public function lastKnownOrgUnit(): ?OrgUnit
    {
        return $this->employmentRecords()->first()?->orgUnit;
    }

    public function assets(): HasMany
    {
        return $this->hasMany(EmployeeAsset::class);
    }

    public function statutoryIds(): HasMany
    {
        return $this->hasMany(EmployeeStatutoryId::class);
    }

    public function aadhaarVerification(): HasOne
    {
        return $this->hasOne(AadhaarVerification::class);
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
