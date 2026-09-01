<?php

namespace App\Filament\Resources\OrgUnits\Pages;

use App\Filament\Resources\OrgUnits\OrgUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOrgUnits extends ManageRecords
{
    protected static string $resource = OrgUnitResource::class;

    /**
     * Set here rather than left to the record's own name, which would title the page
     * "Departments And Branches".
     */
    protected static ?string $title = 'Company structure';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
