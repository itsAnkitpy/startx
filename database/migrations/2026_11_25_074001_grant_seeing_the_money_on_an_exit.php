<?php

use App\Authorization\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeing the money on somebody's exit, module 08 step 3.
 *
 * **The new action goes on every client's Administrator, HR Head and Finance Approver
 * roles**, for the reason module 07's grant of overriding a hold gives: a role's actions
 * are rows, so a name added now reaches nobody on its own. Settled by Ankit on
 * 25 November 2026 at step 3's security review.
 */
return new class extends Migration
{
    private const Roles = [Role::AdministratorKey, 'hr_head', 'finance_approver'];

    public function up(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'insert into role_permissions (tenant_id, role_id, permission, created_at, updated_at)
                   select roles.tenant_id, roles.id, ?, now(), now()
                     from roles
                    where roles.key in (?, ?, ?)
                 on conflict (tenant_id, role_id, permission) do nothing',
                [Permission::ViewSettlement, ...self::Roles],
            );
        }, 'granting seeing the money on an exit to every client administrator, HR head and finance approver');
    }

    /** Only off the three roles it was put on, for the reason the handover's grant gives. */
    public function down(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'delete from role_permissions
                       using roles
                      where role_permissions.role_id = roles.id
                        and role_permissions.tenant_id = roles.tenant_id
                        and roles.key in (?, ?, ?)
                        and role_permissions.permission = ?',
                [...self::Roles, Permission::ViewSettlement],
            );
        }, 'removing seeing the money on an exit from every client administrator, HR head and finance approver');
    }
};
