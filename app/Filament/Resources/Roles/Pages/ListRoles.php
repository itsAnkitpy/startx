<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected static ?string $title = 'Roles and who holds them';

    /**
     * Adding a role asks for its name and lands on the role's own page, where the
     * tick-boxes are. The permanent internal name the database wants is worked out from
     * the name typed, because a client never sees it and should never be asked to type it.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add a role')
                ->modalHeading('Add a role')
                ->modalDescription('Name it now. You choose what it can do, and who holds it, on the next screen.')
                ->modalSubmitActionLabel('Add it')
                ->mutateDataUsing(fn (array $data): array => [
                    ...$data,
                    'key' => Role::keyFor((string) $data['name']),
                ])
                ->successRedirectUrl(fn (Role $record): string => RoleResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
