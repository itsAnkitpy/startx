<?php

namespace App\Models;

use App\Authorization\Permission;
use App\Exceptions\UnknownPermission;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One action one role may perform. This is the table a client's own tick-box screen
 * writes to, one row per tick, and it is where "a new client is configuration rather
 * than a fork" is actually cashed in.
 *
 * The guard below is on the model rather than in a service method, so that every
 * write path is covered — including a direct create in a seeder or a test, which is
 * where an invented name would otherwise slip in and then silently deny forever.
 */
#[Fillable(['role_id', 'permission'])]
class RolePermission extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $permission = (string) $row->permission;

            if (! Permission::exists($permission)) {
                throw UnknownPermission::named($permission);
            }
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
