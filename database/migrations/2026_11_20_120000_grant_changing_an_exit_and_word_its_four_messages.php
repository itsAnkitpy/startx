<?php

use App\Authorization\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Withdrawing an exit and changing its last working day, module 07 step 2.
 *
 * **The new action goes on every client's Administrator and HR Head roles.** A role's
 * actions are rows, so a name added now reaches nobody on its own. The Administrator holds
 * every action there is; the HR Head runs exits for the part of the company it is granted
 * over, and a company created from here on gets this on it from the starter roles, so an
 * existing one is brought level. Written as one statement for the reason the handover's
 * grant gives: there is no company in scope here.
 *
 * **Four new messages for every client who already exists**: a moved last working day and a
 * withdrawn exit, each worded once for the person leaving and once for everybody working on
 * it. Added only where missing, so nothing a client wrote is touched; the wording stays on
 * the way down, as in every migration of module 06.
 */
return new class extends Migration
{
    private const Roles = [Role::AdministratorKey, 'hr_head'];

    public function up(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'insert into role_permissions (tenant_id, role_id, permission, created_at, updated_at)
                   select roles.tenant_id, roles.id, ?, now(), now()
                     from roles
                    where roles.key in (?, ?)
                 on conflict (tenant_id, role_id, permission) do nothing',
                [Permission::ChangeExit, ...self::Roles],
            );
        }, 'granting changing an exit to every client administrator and HR head');

        Tenant::query()->get()->each(fn (Tenant $tenant) => TenantContext::run($tenant, fn () => TemplateCatalogue::seed()));
    }

    /** Only off the two roles it was put on, for the reason the handover's grant gives. */
    public function down(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'delete from role_permissions
                       using roles
                      where role_permissions.role_id = roles.id
                        and role_permissions.tenant_id = roles.tenant_id
                        and roles.key in (?, ?)
                        and role_permissions.permission = ?',
                [...self::Roles, Permission::ChangeExit],
            );
        }, 'removing changing an exit from every client administrator and HR head');
    }
};
