<?php

use App\Authorization\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Put "change the company's settings" on every client's Administrator role.
     *
     * A role's actions are rows, so a new action reaches nobody on its own. A company
     * created from here on gets it with the rest — the starter Administrator is granted
     * every action there is — but a company already using the product would have a
     * settings screen its own administrator could not open. Letting the check pass on the
     * strength of the role being called Administrator is the one thing module 01 refuses,
     * because that is what makes a role's name mean something in code.
     *
     * Across every client company at once, which is the audited path that exists for
     * exactly this and logs itself. Written as one statement rather than through the
     * models because those stamp the company onto a new row from whichever one is in
     * scope, and here there is none.
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
                [Permission::ManageSettings, Role::AdministratorKey],
            );
        }, 'granting the new settings action to every client administrator');
    }

    public function down(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'delete from role_permissions where permission = ?',
                [Permission::ManageSettings],
            );
        }, 'removing the settings action from every client administrator');
    }
};
