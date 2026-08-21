<?php

namespace App\Filament\Platform\Resources\Tenants\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantsTable
{
    /**
     * Every client company, and only what is true about the company itself. How many
     * people they have and what those people are doing is behind the database wall,
     * which this area has no tenant in scope to open — deliberately, because reading a
     * client's own rows is the separate, time-limited thing they switch on themselves.
     *
     * There is no delete action anywhere on this resource. Deleting a client company
     * takes every person, role and record with it through a database cascade, and
     * nothing here needs it: a company that has left is switched off, which is the
     * thing their own people see explained.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label('Address')
                    ->formatStateUsing(fn (string $state): string => $state.'.'.config('tenancy.central_domain'))
                    ->searchable()
                    ->copyable(),

                IconColumn::make('active')
                    ->label('Can sign in')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('onboarded_at')
                    ->label('Set up')
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
