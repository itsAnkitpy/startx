<?php

namespace App\Filament\Resources\Designations\Schemas;

use App\Filament\Forms\UniqueNameInThisCompany;
use App\Models\Designation;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DesignationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // One name per company whichever way it is capitalised, which the
                // database enforces with an index that lowercases first. Without this
                // the client is told about it on an error page instead of under the box.
                TextInput::make('name')
                    ->helperText('The words that appear on a job and on a letter — "Branch Manager".')
                    ->required()
                    ->maxLength(255)
                    ->rule(fn (?Designation $record): UniqueNameInThisCompany => new UniqueNameInThisCompany(
                        Designation::class,
                        $record,
                        'You already have a designation with this name.',
                    )),

                Toggle::make('active')
                    ->label('In use')
                    ->helperText('Turn this off to retire it. It stops being offered on new jobs, and every record that already names it stays as it is.')
                    ->default(true),
            ]);
    }
}
