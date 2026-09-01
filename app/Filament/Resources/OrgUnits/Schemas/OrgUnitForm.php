<?php

namespace App\Filament\Resources\OrgUnits\Schemas;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\OrgUnit;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class OrgUnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->helperText('What your own people call it — "Shimla branch", "Finance".')
                    ->required()
                    ->maxLength(255),

                // The client's own word for the level, not a list of ours: a three-level
                // company and a five-level one describe themselves differently and
                // nothing in the product behaves differently because of what is typed
                // here.
                TextInput::make('type')
                    ->label('Level')
                    ->helperText('Your own word for this level — company, region, branch, department.')
                    ->required()
                    ->maxLength(255),

                Select::make('parent_id')
                    ->label('Sits under')
                    ->placeholder('Nothing — this is the top of the company')
                    ->helperText('Leave this empty for the top of the company. Everything else sits under something.')
                    ->relationship(
                        name: 'parent',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query, ?OrgUnit $record): Builder => self::partsTheyMayAddTo($query, $record),
                    )
                    ->searchable()
                    ->preload()
                    // Somebody whose grant covers one branch cannot start a second
                    // company alongside it, which is what a part with nothing above it
                    // would be.
                    ->required(fn (?OrgUnit $record): bool => self::partsTheyCover($record) !== null)
                    ->validationMessages([
                        'in' => 'That is not a part of the company you can add to.',
                        'required' => 'Choose the part of the company this sits under.',
                    ]),

                TextInput::make('code')
                    ->helperText('Optional. Your own reference for this part of the company.')
                    ->maxLength(255)
                    ->scopedUnique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'Another part of your company already uses this code.',
                    ]),

                Toggle::make('active')
                    ->label('In use')
                    ->helperText('Turn this off to archive it. Everything already recorded against it stays as it is.')
                    ->default(true),
            ]);
    }

    /**
     * The parts of the company this person may put something under: what their own grant
     * covers plus wherever the part being edited already sits, less that part itself and
     * everything already below it.
     *
     * **This is the guard, not a convenience.** "May this person create a department" is
     * asked before the department exists, so it cannot say where the department is going —
     * somebody who covers Shimla alone passes it and would otherwise save a department
     * under Pune. Filament checks a saved answer by looking it up through this same query
     * and refuses one it cannot find, so narrowing what is offered is also what refuses
     * what is submitted.
     *
     * The second half is not a permission question. {@see OrgUnit} refuses a part moved
     * under its own child, and that refusal would reach a client as an error page — so the
     * form does not offer what it is going to refuse.
     */
    private static function partsTheyMayAddTo(Builder $query, ?OrgUnit $record): Builder
    {
        $covered = self::partsTheyCover($record);

        if ($covered !== null) {
            // Where it already sits stays on the list even when that is above what this
            // person covers. Renaming a branch is not moving it, and dropping the answer
            // already in the box refused every edit to the top of somebody's own patch of
            // the company: the branch's own administrator could see one row and save none
            // of it. Nothing else is added, so it can be kept and never changed.
            $query->whereKey([
                ...$covered,
                ...($record?->parent_id === null ? [] : [(int) $record->parent_id]),
            ]);
        }

        if ($record !== null) {
            $query->whereKeyNot($record->selfAndDescendantIds());
        }

        return $query;
    }

    /**
     * Null means the whole company. An empty list means nowhere, which is what somebody
     * with no grant at all gets.
     *
     * @return list<int>|null
     */
    private static function partsTheyCover(?OrgUnit $record): ?array
    {
        $person = auth()->user();

        if (! $person instanceof User) {
            return [];
        }

        return app(PermissionResolver::class)->reachableUnitIds(
            $person,
            $record === null ? Permission::CreateOrgUnit : Permission::UpdateOrgUnit,
        );
    }
}
