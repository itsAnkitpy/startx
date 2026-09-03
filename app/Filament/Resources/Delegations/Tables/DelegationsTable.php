<?php

namespace App\Filament\Resources\Delegations\Tables;

use App\Filament\Resources\Delegations\DelegationResource;
use App\Models\Delegation;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DelegationsTable
{
    /**
     * Every cover the company has set, the ones that have finished included.
     *
     * A finished cover stays on the list and reads as finished rather than dropping off
     * it. What administrators of the comparable products actually complain about is a
     * cover that quietly stopped working, and a list that hides one the day it ends is
     * the screen that leaves them with nowhere to look — the same reasoning that keeps a
     * retired designation on its own list.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Delegation $record): string => $record->delegate?->name
                .' covering for '
                .$record->user?->name)
            ->columns([
                TextColumn::make('user.first_name')
                    ->label('Who is away')
                    ->state(fn (Delegation $record): ?string => $record->user?->name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),

                TextColumn::make('delegate.first_name')
                    ->label('Who holds their approvals')
                    ->state(fn (Delegation $record): ?string => $record->delegate?->name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),

                // The row stores the process's permanent name, which no client ever sees.
                // A process retired since the cover was set falls back to what is stored,
                // because a blank cell would read as a cover for nothing.
                TextColumn::make('process_key')
                    ->label('Which process')
                    ->formatStateUsing(fn (string $state): string => DelegationResource::liveProcessNames()[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('effective_from')
                    ->label('From')
                    ->date()
                    ->sortable(),

                TextColumn::make('effective_to')
                    ->label('To')
                    ->date()
                    ->sortable(),

                // Words rather than a colour standing alone, the same as every other list
                // in this module.
                TextColumn::make('id')
                    ->label('State')
                    ->badge()
                    ->state(fn (Delegation $record): string => self::whereItHasGotTo($record))
                    ->color(fn (Delegation $record): string => match (self::whereItHasGotTo($record)) {
                        'Running now' => 'success',
                        'Not started yet' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('effective_from', 'desc')
            ->filters([
                Filter::make('running_now')
                    ->label('Running now')
                    // The record's own reading of a live cover, both ends counted in, so
                    // the list and the product can never disagree about which covers are
                    // working today.
                    ->query(fn (Builder $query): Builder => $query->asOf(CarbonImmutable::now())),
            ])
            ->emptyStateHeading('No cover set')
            ->emptyStateDescription('Set cover when somebody is going away, so their approvals keep moving while they are gone. Somebody leaving for good is handed on from their exit instead.')
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Change this cover')
                    ->using(function (Model $record, array $data, EditAction $action): void {
                        DelegationResource::orSayWhyItWasRefused(
                            $action,
                            fn () => $record->update($data),
                        );
                    }),

                DeleteAction::make()
                    ->modalHeading('Remove this cover')
                    ->modalDescription('Their approvals go back to reaching them alone, from the next click. Anything already answered under the cover keeps the record of who answered it.'),
            ]);
    }

    /**
     * Where a cover has got to today, in the words the list shows.
     *
     * Worked out from the two dates rather than stored, because nothing about a cover is
     * stored — the same reason it needs no job to start it and none to end it.
     */
    private static function whereItHasGotTo(Delegation $cover): string
    {
        $today = CarbonImmutable::now()->startOfDay();

        return match (true) {
            $cover->effective_from->greaterThan($today) => 'Not started yet',
            $cover->effective_to->lessThan($today) => 'Finished',
            default => 'Running now',
        };
    }
}
