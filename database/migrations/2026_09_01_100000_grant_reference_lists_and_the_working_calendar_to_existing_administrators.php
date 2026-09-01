<?php

use App\Authorization\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Put "edit the designation and office lists" and "set an office's working calendar"
     * on every client's Administrator role.
     *
     * The same cost module 01 chose knowingly, paid a second time: a role's actions are
     * rows, so a new action reaches nobody on its own. A company created from here on
     * gets both with the rest — the starter Administrator is granted every action there
     * is — but a company already using the product would open the new screens and be
     * refused. Letting the check pass because the role happens to be called Administrator
     * is the one thing module 01 refuses.
     *
     * Across every client company at once, which is the audited path that exists for
     * exactly this and logs itself. Written as one statement rather than through the
     * models, because those stamp the company onto a new row from whichever one is in
     * scope and here there is none.
     */
    private const Added = [
        Permission::ManageReferenceList,
        Permission::ManageWorkingCalendar,
    ];

    public function up(): void
    {
        TenantContext::cross(function (): void {
            foreach (self::Added as $permission) {
                DB::statement(
                    'insert into role_permissions (tenant_id, role_id, permission, created_at, updated_at)
                       select roles.tenant_id, roles.id, ?, now(), now()
                         from roles
                        where roles.key = ?
                     on conflict (tenant_id, role_id, permission) do nothing',
                    [$permission, Role::AdministratorKey],
                );
            }
        }, 'granting the designation, office and working-calendar actions to every client administrator');
    }

    public function down(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'delete from role_permissions where permission in (?, ?)',
                self::Added,
            );
        }, 'removing the designation, office and working-calendar actions from every client administrator');
    }
};
