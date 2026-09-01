<?php

namespace App\Filament\Resources\OrgUnits\Tables;

use App\Models\OrgUnit;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class OrgUnitsTable
{
    /**
     * A flat list with a column saying what each part sits under, rather than the
     * indented tree the plan first asked for. Filament ships no tree table, and an
     * indentation only reads as one while the list is in its own order — the moment
     * somebody sorts by another heading or searches, an indented row with no parent
     * above it says nothing. A "sits under" column is true in every order.
     *
     * There is no delete action. A part of the company that is finished is archived,
     * because deleting one takes every job row, role grant and case that named it with
     * it through a database cascade.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Level')
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->sortable(),

                TextColumn::make('parent.name')
                    ->label('Sits under')
                    ->placeholder('Top of the company')
                    ->sortable(),

                TextColumn::make('code')
                    ->placeholder('None')
                    ->searchable(),

                // Words rather than a tick or a cross on its own: an icon standing alone
                // for a state is the thing this module said it would not ship. The colour
                // is on top of the words, not instead of them.
                TextColumn::make('active')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'In use' : 'Archived')
                    ->color(fn (OrgUnit $record): string => $record->active ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('active')
                    ->label('State')
                    ->trueLabel('In use')
                    ->falseLabel('Archived')
                    ->placeholder('All'),
            ])
            ->emptyStateHeading('No departments or branches yet')
            ->emptyStateDescription('Start with the top of your company, then add everything that sits under it.')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
