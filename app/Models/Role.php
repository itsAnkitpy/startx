<?php

namespace App\Models;

use App\Exceptions\SystemRoleProtected;
use App\Tenancy\BelongsToTenant;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role belonging to one client company. Meridian and Vertex can both hold a role
 * whose permanent internal name is `hr_head`, label it differently, and give it
 * different actions, without either affecting the other's answers.
 *
 * `key` is the permanent internal name. It is set once and never edited, because it
 * is the only thing that may safely be named in code — a seeded process template
 * pointing at a seeded role, and the rule that a client keeps two administrators.
 * Everywhere else, code asks whether a person may perform an action and never which
 * role they hold, so that a client renaming "HR head" to "People Lead" changes no
 * answer anywhere.
 */
/*
 * `is_system` is deliberately not a field a form may fill. It marks a role we seeded
 * and point at from code, and it is what makes a role undeletable — that is ours to
 * decide, not a submitted field's. `key` stays fillable because a client creating a
 * role of their own does need an internal name generated for it.
 */
#[Fillable(['key', 'name', 'description'])]
class Role extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /** The one role named in code, by the rule that a client keeps two of them. */
    public const AdministratorKey = 'administrator';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $role): void {
            if ($role->isDirty('key')) {
                throw SystemRoleProtected::keyIsPermanent(
                    (string) $role->getOriginal('key'),
                    (string) $role->key,
                );
            }
        });

        // Deleting a role cascades to its grants in the database, which no model event
        // sees — so deleting the administrator role would remove every administrator in
        // one statement and walk straight past the two-administrator rule.
        static::deleting(function (self $role): void {
            if ($role->is_system) {
                throw SystemRoleProtected::cannotBeDeleted((string) $role->key);
            }
        });
    }

    /**
     * The actions this role may perform. Rows rather than a list on the role, so a
     * client's own tick-box screen in module 12 writes one row per tick.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Who holds this role, and over which part of the structure.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->permissions()->pluck('permission')->all();
    }
}
