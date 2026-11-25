<?php

use App\Authorization\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Writing the letters, module 09 step 2.
 *
 * **Its own action rather than the one for rewording emails** (Ankit, 25 November 2026, P10):
 * every client ticked that one for emails, and a letter goes out over somebody's signature.
 * On every client's Administrator role, because a role's actions are rows and a name added
 * now reaches nobody on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'insert into role_permissions (tenant_id, role_id, permission, created_at, updated_at)
                   select roles.tenant_id, roles.id, ?, now(), now()
                     from roles
                    where roles.key = ?
                 on conflict (tenant_id, role_id, permission) do nothing',
                [Permission::WriteLetters, Role::AdministratorKey],
            );
        }, 'granting writing the letters to every client administrator');
    }

    /** Only off the Administrator roles it was put on. */
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
                [Role::AdministratorKey, Permission::WriteLetters],
            );
        }, 'removing writing the letters from every client administrator');
    }
};
