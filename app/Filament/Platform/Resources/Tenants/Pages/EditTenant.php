<?php

namespace App\Filament\Platform\Resources\Tenants\Pages;

use App\Filament\Platform\Resources\Tenants\Tables\TenantsTable;
use App\Filament\Platform\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\EditRecord;

/**
 * The company's own details and whether its people may sign in. Nothing about the
 * people themselves — see {@see TenantsTable}
 * for why, and no delete action for the same reason.
 */
class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;
}
