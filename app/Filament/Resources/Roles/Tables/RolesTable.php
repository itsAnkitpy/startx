<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Models\Role;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RolesTable
{
    /**
     * What somebody scanning this list wants: what each role is called, what it is for, how
     * much it can do, and how many people have it.
     *
     * The two counts are here because a role with no actions and a role nobody holds are
     * both worth spotting, and both are invisible from a name alone. They are words rather
     * than bare numbers so an empty one reads as a state rather than as a zero.
     *
     * Nothing deletes from this list. A role is deleted from its own page, where the
     * sentence about the grants going with it fits.
     */
    public static function configure(Table $table): Table
    {
        return $table
            // The holder count is narrowed the same way the list of holders under a role
            // is, so somebody responsible for one branch is not told three people hold a
            // role and then shown one.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'permissions',
                'assignments' => fn (Builder $grants) => $grants->visibleTo(auth()->user()),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->description(fn (Role $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('permissions_count')
                    ->label('What it can do')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => 'Nothing yet',
                        1 => '1 action',
                        default => $state.' actions',
                    })
                    ->color(fn (int $state): string => $state === 0 ? 'warning' : 'success')
                    ->sortable(),

                TextColumn::make('assignments_count')
                    ->label('Who holds it')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        0 => 'Nobody',
                        1 => '1 person',
                        default => $state.' people',
                    })
                    ->color('gray')
                    ->sortable(),

                // Words rather than an icon standing alone, the same as every other list in
                // this module. A role we seeded can be renamed and its actions changed; it
                // cannot be deleted, because a seeded process points at it.
                TextColumn::make('is_system')
                    ->label('Where it came from')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Came with your company' : 'You added it')
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No roles yet')
            ->emptyStateDescription('A role is a name you choose and a list of what it can do. Grant it to somebody over the whole company, or over one branch.')
            ->recordActions([
                EditAction::make()->label('Open'),
            ]);
    }
}
