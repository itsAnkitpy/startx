<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Authorization\Permission;
use App\Filament\Forms\UniqueNameInThisCompany;
use App\Models\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleForm
{
    /**
     * A role's name, what it is for, and what it can do.
     *
     * The tick-boxes are hidden while a role is being added, because adding one asks for a
     * name and nothing else. They appear on the role's own page, which is where it lands.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('What you call this role')
                    ->helperText('Your own words — "People Lead", "Branch HR". Nothing in the product behaves differently because of what is typed here, so rename it whenever you like.')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn (?Role $record): bool => ! self::mayChangeWhatItIs($record))
                    ->rule(fn (?Role $record): UniqueNameInThisCompany => new UniqueNameInThisCompany(
                        Role::class,
                        $record,
                        'You already have a role with this name.',
                    )),

                TextInput::make('description')
                    ->label('What this role is for')
                    ->helperText('Read by whoever grants it later — "Runs hiring and exits for one branch".')
                    ->maxLength(255)
                    ->disabled(fn (?Role $record): bool => ! self::mayChangeWhatItIs($record)),

                ...self::whatItCanDo(),
            ]);
    }

    /**
     * Whether the person reading this may change what the role is called and what it can
     * do, as against only handing it out over their own part of the company.
     *
     * A role's actions are one list for the whole client company, so changing them needs
     * a grant covering the whole company. Somebody responsible for roles in one branch
     * still opens this page — the people who hold the role are listed underneath it —
     * and reads the tick-boxes without being able to move them. The refusal itself is on
     * the page, because a disabled tick-box is a courtesy and not a wall.
     */
    private static function mayChangeWhatItIs(?Role $record): bool
    {
        return $record === null
            || (auth()->user()?->can('changeWhatItCanDo', $record) ?? false);
    }

    /**
     * The actions, as tick-boxes under a heading per group with a sentence on each.
     *
     * Grouped and worded rather than listed raw: the name the code uses is
     * `view_org_unit`, which is not something a client can decide about. None of the
     * comparable products shows a code name anywhere — BambooHR groups by the record a
     * permission is about and words each item as an ordinary action, and Rippling groups
     * by module and by verb.
     *
     * Built from the same list the checks themselves read, so a name added by a later
     * module appears here without this file being touched.
     *
     * @return list<Section>
     */
    private static function whatItCanDo(): array
    {
        return array_map(
            fn (array $group): Section => Section::make($group['heading'])
                ->description($group['description'])
                ->hiddenOn('create')
                ->disabled(fn (?Role $record): bool => ! self::mayChangeWhatItIs($record))
                ->schema([
                    CheckboxList::make('actions.'.$group['key'])
                        ->hiddenLabel()
                        ->options(array_map(
                            fn (array $action): string => $action['label'],
                            $group['actions'],
                        ))
                        ->descriptions(array_map(
                            fn (array $action): string => $action['description'],
                            $group['actions'],
                        ))
                        // Nobody may untick the two actions the Administrator role needs to
                        // put anything back. The role itself forces them on either way, so
                        // this is what stops a client trying and finding it did nothing.
                        ->disableOptionWhen(fn (string $value, ?Role $record): bool => $record?->key === Role::AdministratorKey
                            && in_array($value, [Permission::ViewRole, Permission::ManageRole], true))
                        // Every action in this group counts as an answer, including the two
                        // just disabled. Filament otherwise takes a disabled tick-box to be
                        // an answer nobody may give, and the Administrator role opens with
                        // both of them already ticked — so saving that role at all would be
                        // refused, over a box with no label to name in the complaint.
                        ->in(array_keys($group['actions']))
                        ->bulkToggleable(),
                ]),
            Permission::describedForAClient(),
        );
    }
}
