<?php

namespace App\Models;

use App\Authorization\Permission;
use App\Exceptions\SystemRoleProtected;
use App\Tenancy\BelongsToTenant;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * A permanent internal name for a role a client has just named, unique within their
     * company.
     *
     * The client never sees it and never types it. It exists because the database holds
     * one name per company for it, and because two roles a client happens to name alike
     * would otherwise collide on a name nobody ever chose — an error page about a field
     * that is not on the form.
     */
    public static function keyFor(string $name): string
    {
        // Cut to fit the column with room for the number below. A name of 255 accented
        // letters slugs longer than it started, because each one is spelled out, and the
        // database refusing it is exactly the error page this method exists to avoid.
        $stem = Str::limit(Str::slug($name, '_'), 240, '') ?: 'role';
        $key = $stem;
        $next = 2;

        while (static::query()->where('key', $key)->exists()) {
            $key = $stem.'_'.$next++;
        }

        return $key;
    }

    /**
     * Make this role's actions exactly these, adding what is missing and removing what is
     * no longer ticked.
     *
     * **The Administrator role always keeps seeing roles and managing them.** Unticking
     * either would take the roles screen away from the only role that can put it back, and
     * we deliberately build no platform rescue path for a locked-out client — the same
     * reasoning that gives a client a two-administrator floor. Forced here rather than on
     * the screen so that every way of writing a role's actions is covered.
     *
     * @param  list<string>  $names
     */
    public function keepOnlyTheseActions(array $names): void
    {
        $names = array_values(array_unique(array_filter(
            $names,
            fn (mixed $name): bool => is_string($name) && Permission::exists($name),
        )));

        if ($this->key === self::AdministratorKey) {
            $names = array_values(array_unique([...$names, Permission::ViewRole, Permission::ManageRole]));
        }

        // Both halves together: the unticked ones go and the ticked ones arrive, or
        // neither happens. Otherwise a failure between the two leaves the role holding
        // less than either list, and everybody holding it quietly loses what it could do.
        DB::transaction(function () use ($names): void {
            $this->permissions()->whereNotIn('permission', $names)->delete();

            foreach ($names as $name) {
                $this->permissions()->firstOrCreate(['permission' => $name]);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->permissions()->pluck('permission')->all();
    }
}
