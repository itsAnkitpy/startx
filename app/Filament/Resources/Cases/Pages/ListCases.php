<?php

namespace App\Filament\Resources\Cases\Pages;

use App\Filament\Resources\Cases\CaseResource;
use Filament\Resources\Pages\ListRecords;

class ListCases extends ListRecords
{
    protected static string $resource = CaseResource::class;

    protected static ?string $title = 'Cases';

    public function getSubheading(): ?string
    {
        return 'Everything your company has run. Open one to see what happened at each of its steps.';
    }
}
