<?php

namespace App\Filament\Platform\Resources\Tenants;

use App\Filament\Platform\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Platform\Resources\Tenants\Pages\EditTenant;
use App\Filament\Platform\Resources\Tenants\Pages\ListTenants;
use App\Filament\Platform\Resources\Tenants\Schemas\TenantForm;
use App\Filament\Platform\Resources\Tenants\Tables\TenantsTable;
use App\Models\Tenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'client company';

    protected static ?string $pluralModelLabel = 'client companies';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TenantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}
