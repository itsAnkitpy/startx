<?php

use App\Authorization\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Put "settle who takes over a leaver's work" on every client's Administrator role.
     *
     * The fourth time this cost is paid, and for the same reason each time: a role's
     * actions are rows, so a name added by a later module reaches nobody on its own. A
     * company created from here on gets it with the rest, because the starter
     * Administrator is granted every action there is; a company already using the product
     * would open a leaver's exit and find the handover missing from it.
     *
     * Across every client company at once through the audited path that exists for this
     * and logs itself, and written as one statement rather than through the models,
     * because those stamp the company onto a new row from whichever one is in scope and
     * here there is none.
     */
    public function up(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'insert into role_permissions (tenant_id, role_id, permission, created_at, updated_at)
                   select roles.tenant_id, roles.id, ?, now(), now()
                     from roles
                    where roles.key = ?
                 on conflict (tenant_id, role_id, permission) do nothing',
                [Permission::SettleHandover, Role::AdministratorKey],
            );
        }, 'granting the handover action to every client administrator');
    }

    /**
     * Only off the Administrator roles it was put on, rather than off every role holding
     * it. Rolling an earlier grant migration back took its two actions off every role that
     * held them, including one a client had ticked on themselves, and that was noted at
     * the time as worth not repeating.
     */
    public function down(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'delete from role_permissions
                       using roles
                      where role_permissions.role_id = roles.id
                        and role_permissions.tenant_id = roles.tenant_id
                        and roles.key = ?
                        and role_permissions.permission = ?',
                [Role::AdministratorKey, Permission::SettleHandover],
            );
        }, 'removing the handover action from every client administrator');
    }
};
