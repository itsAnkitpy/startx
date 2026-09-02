<?php

namespace App\Filament\Resources\Users\Tables;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\OrgUnit;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    /**
     * The five things somebody scanning this list is looking for: who, where they sit, what
     * they are called, where they work from, and whether they can still sign in.
     *
     * Each of the middle three is read from the job that is true today, so a leaver shows
     * plainly as having no current job rather than showing three empty cells. Their history
     * is still there under their own page.
     *
     * Nothing here deletes. A person's record is the evidence behind their exit and their
     * settlement, and a disputed settlement is argued after they have gone, so an account
     * that should no longer sign in is switched off on the form.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'currentEmployment.orgUnit',
                'currentEmployment.office',
            ]))
            ->columns([
                // The name is assembled from its parts, so searching and sorting are told
                // which real columns to use.
                TextColumn::make('name')
                    ->label('Name')
                    ->description(fn (User $record): ?string => $record->preferred_name)
                    ->searchable(['first_name', 'middle_name', 'last_name', 'preferred_name'])
                    ->sortable(['first_name', 'last_name']),

                TextColumn::make('work_email')
                    ->label('Work email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('currentEmployment.orgUnit.name')
                    ->label('Department or branch')
                    ->placeholder('No current job')
                    ->sortable(),

                TextColumn::make('currentEmployment.recorded_designation_name')
                    ->label('Designation')
                    ->placeholder('None recorded'),

                TextColumn::make('currentEmployment.office.name')
                    ->label('Office')
                    ->placeholder('None recorded'),

                // Words rather than a tick or a cross on its own, the same as every other
                // list in this module. The colour sits on top of the words, not instead of
                // them.
                TextColumn::make('active')
                    ->label('Sign-in')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Can sign in' : 'Switched off')
                    ->color(fn (User $record): string => $record->active ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->defaultSort('first_name')
            ->filters([
                SelectFilter::make('department')
                    ->label('Department or branch')
                    ->options(fn (): array => self::partsOfTheCompanyWorthNarrowingTo())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas(
                            'currentEmployment',
                            fn (Builder $job) => $job->where('org_unit_id', $data['value']),
                        )),

                TernaryFilter::make('active')
                    ->label('Sign-in')
                    ->trueLabel('Can sign in')
                    ->falseLabel('Switched off')
                    ->placeholder('All'),
            ])
            ->emptyStateHeading('Nobody added yet')
            ->emptyStateDescription('Add everybody who works here. Each person gets their job recorded on their own page, so a move between departments is a new dated row rather than an edit.')
            ->recordActions([
                EditAction::make()->label('Open'),
            ]);
    }

    /**
     * The parts of the company this list can actually be narrowed to.
     *
     * Narrowed the same way the rows are, and for the same reason. Offering every branch
     * told Rakesh, who runs HR over Shimla alone, that the company has a Pune branch —
     * which the structure screen hides from him — and choosing it handed him an empty list,
     * because the rows behind it were never his.
     *
     * Archived parts stay on offer: somebody can still sit in one, so it is still worth
     * narrowing to.
     *
     * @return array<int, string>
     */
    private static function partsOfTheCompanyWorthNarrowingTo(): array
    {
        $person = auth()->user();

        if (! $person instanceof User) {
            return [];
        }

        $reachable = app(PermissionResolver::class)->reachableUnitIds($person, Permission::ViewPerson);

        return OrgUnit::query()
            ->when($reachable !== null, fn (Builder $covered): Builder => $covered->whereKey($reachable))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
