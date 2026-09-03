<?php

namespace App\Filament\Resources\Delegations\Pages;

use App\Filament\Resources\Delegations\DelegationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;

class ManageCover extends ManageRecords
{
    protected static string $resource = DelegationResource::class;

    protected static ?string $title = 'Cover while somebody is away';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Set cover')
                ->modalHeading('Set cover for somebody who is going away')
                ->modalDescription('Their approvals reach the person covering as well as themselves, for these dates only, and stop reaching them the day after.')
                ->modalSubmitActionLabel('Set the cover')
                ->using(fn (array $data, string $model, CreateAction $action): ?Model => DelegationResource::orSayWhyItWasRefused(
                    $action,
                    fn (): Model => $model::create($data),
                )),
        ];
    }
}
