<?php

namespace App\Filament\Platform\Resources\Tenants\Pages;

use App\Authorization\StarterRoles;
use App\Filament\Platform\Resources\Tenants\TenantResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Setting a client company up is the one thing that genuinely cannot belong to a
     * client's own administrator, because the company does not exist yet and so nobody
     * inside it can act. Three writes, all or nothing: the company, its starting roles,
     * and its first administrators.
     *
     * The company row itself is the one table the tenant wall is built around, so it
     * writes on the plain address like any other row. Everything after it is walled and
     * would be refused by Postgres with no company in scope — which is what
     * {@see TenantContext::run()} is for. Not the audited cross-company path: that one
     * is for reading across companies, and this is one company being set up.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $administrators = $data['administrators'] ?? [];
        unset($data['administrators']);

        return DB::transaction(function () use ($data, $administrators): Tenant {
            /** @var Tenant $tenant */
            $tenant = Tenant::create($data + ['onboarded_at' => now()]);

            TenantContext::run($tenant, function () use ($administrators): void {
                // Its first caller outside the test suite. The labels are theirs to
                // rename from here on, and no permission check reads any of them by name.
                $administratorRole = StarterRoles::seed()[Role::AdministratorKey];

                foreach ($administrators as $administrator) {
                    $person = User::create($administrator);

                    // No org unit named, so the grant covers the whole company — which is
                    // the only sensible scope for the two people who have to set the
                    // structure up in the first place.
                    $person->roleAssignments()->create([
                        'role_id' => $administratorRole->getKey(),
                    ]);
                }
            });

            return $tenant;
        });
    }
}
