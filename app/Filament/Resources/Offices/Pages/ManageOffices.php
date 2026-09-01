<?php

namespace App\Filament\Resources\Offices\Pages;

use App\Filament\Resources\Offices\OfficeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOffices extends ManageRecords
{
    protected static string $resource = OfficeResource::class;

    protected static ?string $title = 'Offices';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add an office'),
        ];
    }
}
