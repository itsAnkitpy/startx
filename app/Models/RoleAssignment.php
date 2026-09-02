<?php

namespace App\Models;

use App\Authorization\AdministratorFloor;
use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Policies\RoleAssignmentPolicy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person holding one role over one part of a client company's structure — or
 * over the whole of it, when no org unit is named.
 *
 * This is the row that makes "the director of *this* business line" a lookup rather
 * than an email address written into a process template. Module 03 resolves a step's
 * owner against it.
 *
 * `includes_descendants` is what separates "HR head for this one branch" from
 * "finance controller for this business line and everything under it". Without it
 * every grant would silently cover the whole subtree.
 */
#[Fillable(['user_id', 'role_id', 'org_unit_id', 'includes_descendants'])]
class RoleAssignment extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'includes_descendants' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Taking a grant away by hand, and moving a grant off the administrator role,
        // are the two manual routes below two administrators. Deactivating a leaver's
        // account is deliberately not one of them: an exit carries a two-working-day
        // statutory clock, and module 07's exit step catches the same problem weeks
        // earlier by making the leaver appoint a replacement.
        static::deleting(function (self $assignment): void {
            AdministratorFloor::refuseRemoval($assignment);
        });

        static::updating(function (self $assignment): void {
            // Both edits take an administrator away: pointing the grant at a different
            // role, and pointing it at a different person. The second is the quieter of
            // the two — moving Anjali's administrator grant onto a leaver's account
            // reduces the count without deleting anything.
            if ($assignment->isDirty('role_id') || $assignment->isDirty('user_id')) {
                AdministratorFloor::refuseRemoval($assignment);
            }
        });
    }

    /**
     * The grants this person may see: the ones over the parts of the company their own
     * grant covers, plus every grant covering the whole company — because a question
     * asked about no part of the company is the question "do they hold this action
     * anywhere", which is what {@see RoleAssignmentPolicy::view()} answers for one row.
     *
     * Here rather than on the screen because two screens ask it: the list of who holds a
     * role, and the count of holders beside the role's name. A count that disagreed with
     * the list would tell somebody responsible for one branch that three people hold a
     * role and then show them one.
     */
    public function scopeVisibleTo(Builder $query, ?User $person): void
    {
        if (! $person instanceof User) {
            $query->whereRaw('1 = 0');

            return;
        }

        $covered = app(PermissionResolver::class)->reachableUnitIds($person, Permission::ViewRole);

        if ($covered === null) {
            return;
        }

        $query->where(fn (Builder $theirs): Builder => $theirs
            ->whereIn('org_unit_id', $covered)
            ->orWhereNull('org_unit_id'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }
}
