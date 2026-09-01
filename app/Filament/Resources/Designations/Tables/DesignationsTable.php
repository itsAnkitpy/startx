<?php

namespace App\Filament\Resources\Designations\Tables;

use App\Models\Designation;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DesignationsTable
{
    /**
     * There is no delete action, and there is no bulk action group either. A designation
     * that is finished with is retired through the "in use" switch, because a job row
     * keeps its own copy of the words it read and the record refuses a delete outright.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                // Words rather than a tick or a cross on its own, the same as every other
                // list in this module: an icon standing alone for a state is the thing
                // this module said it would not ship.
                TextColumn::make('active')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'In use' : 'Retired')
                    ->color(fn (Designation $record): string => $record->active ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('active')
                    ->label('State')
                    ->trueLabel('In use')
                    ->falseLabel('Retired')
                    ->placeholder('All'),
            ])
            ->emptyStateHeading('No designations yet')
            ->emptyStateDescription('Add the jobs your people hold. They are offered when somebody records a job or raises a hiring request.')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
