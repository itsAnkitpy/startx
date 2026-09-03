<?php

namespace App\Filament\Resources\Roles\RelationManagers;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\TooFewAdministrators;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who holds this role, and over which part of the company — drawn underneath the role's
 * own page, the way the Google Admin console puts a role's holders beside its privileges.
 *
 * **A grant is given and taken away. It is never edited.** Those are the two honest acts,
 * and holding to them also closes the quieter of the two routes below two administrators:
 * a grant moved onto somebody else rather than deleted.
 *
 * Rule 1 of this module is at its sharpest here, and it is where the rule was first found.
 * Whether somebody may *create* a grant is asked before the grant exists, so it cannot say
 * which part of the company the grant will name — somebody who manages roles for one
 * branch passes that check and would otherwise hand out a role over another branch
 * entirely. What refuses it is the picker's own narrowed query, which Filament re-runs
 * against the answer submitted.
 */
class WhoHoldsItRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Who holds it';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Who')
                    ->options(fn (): array => User::everybodyHere())
                    ->required()
                    ->searchable()
                    ->rule(fn (Get $get): Closure => $this->notHeldOverTheSamePartAlready($get))
                    ->validationMessages([
                        'required' => 'Choose who this role is for.',
                    ]),

                Select::make('org_unit_id')
                    ->label('Over which part of the company')
                    ->placeholder('The whole company')
                    ->helperText('Leave this empty and the role covers your whole company.')
                    ->relationship(
                        'orgUnit',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $this->partsTheyMayGrantOver($query),
                    )
                    ->searchable()
                    ->preload()
                    // Somebody whose own grant covers one branch cannot hand out a role over
                    // the whole company, which is what leaving this empty would do.
                    ->required(fn (): bool => $this->partsTheyCover() !== null)
                    ->validationMessages([
                        'in' => 'That is not a part of the company you can grant a role over.',
                        'exists' => 'That is not a part of the company you can grant a role over.',
                        'required' => 'Choose the part of the company this covers. Only the parts your own role covers are offered.',
                    ]),

                Toggle::make('includes_descendants')
                    ->label('And everything under it')
                    ->helperText('On, the role also covers every department and branch below the one chosen above. Off, it covers that one alone.')
                    ->rule(fn (Get $get): Closure => $this->reachesNoFurtherThanTheirOwnGrant($get)),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            // The narrowing lives on the grant itself, because the count of holders beside
            // a role's name on the list has to agree with the rows shown here.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->visibleTo(auth()->user()))
            ->columns([
                TextColumn::make('user.first_name')
                    ->label('Who')
                    ->state(fn (RoleAssignment $record): ?string => $record->user?->name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),

                TextColumn::make('orgUnit.name')
                    ->label('Over')
                    ->placeholder('The whole company')
                    ->sortable(),

                // Words, not a tick: "and everything under it" is the difference between HR
                // head for one branch and HR head for a whole business line.
                TextColumn::make('includes_descendants')
                    ->label('How far it reaches')
                    ->badge()
                    ->formatStateUsing(fn (bool $state, RoleAssignment $record): string => match (true) {
                        $record->org_unit_id === null => 'The whole company',
                        $state => 'That part and everything under it',
                        default => 'That part only',
                    })
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Granted')
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Grant this role')
                    ->modalHeading('Grant this role to somebody')
                    ->modalDescription('Choose who, and which part of the company it covers. The same person can hold the same role over two different parts.')
                    ->modalSubmitActionLabel('Grant it'),
            ])
            ->emptyStateHeading('Nobody holds this role yet')
            ->emptyStateDescription('Grant it to somebody over your whole company, or over one department or branch.')
            ->recordActions([
                Action::make('revoke')
                    ->label('Take it away')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Take this role away')
                    ->modalDescription('They lose whatever this role let them do over that part of the company, from their next click. Their own records are untouched.')
                    ->modalSubmitActionLabel('Take it away')
                    ->visible(fn (RoleAssignment $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->action(function (RoleAssignment $record): void {
                        try {
                            $record->delete();
                        } catch (TooFewAdministrators) {
                            // The one refusal reachable from here in a single click. Said in
                            // ordinary words naming the way out, because what the comparable
                            // products' stuck customers lacked was not an earlier message but
                            // a message that said what to do next.
                            Notification::make()
                                ->danger()
                                ->title('This one cannot be taken away')
                                ->body('Your company would be left with fewer than two administrators covering the whole company, and it has to keep at least two — we have no way of letting you back in if the last one is lost. Grant Administrator to somebody else over your whole company first, then take this one away.')
                                ->send();

                            return;
                        }

                        app(PermissionResolver::class)->forget();

                        Notification::make()
                            ->success()
                            ->title('Role taken away')
                            ->send();
                    }),
            ]);
    }

    /**
     * The parts of the company this person may grant a role over, which is also what is
     * refused if another is submitted.
     *
     * Not filtered to the parts still in use: a branch being wound down still needs
     * somebody responsible for it, and a grant is not a record of where anybody works.
     */
    private function partsTheyMayGrantOver(Builder $units): Builder
    {
        $covered = $this->partsTheyCover();

        return $covered === null ? $units : $units->whereKey($covered);
    }

    /**
     * Null means the whole company. An empty list means nowhere.
     *
     * @return list<int>|null
     */
    private function partsTheyCover(): ?array
    {
        $person = auth()->user();

        if (! $person instanceof User) {
            return [];
        }

        return app(PermissionResolver::class)->reachableUnitIds($person, Permission::ManageRole);
    }

    /**
     * Refuse reaching downwards past what the granter's own grant covers.
     *
     * Without this, somebody who manages roles for the Shimla branch alone could grant a
     * role over "Shimla and everything under it" — more of the company than they cover, and
     * a role that keeps growing as departments are added underneath.
     */
    private function reachesNoFurtherThanTheirOwnGrant(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $covered = $this->partsTheyCover();

            if (! $value || $covered === null) {
                return;
            }

            $unit = OrgUnit::query()->find($get('org_unit_id'));

            if ($unit === null) {
                return;
            }

            $beyond = array_diff($unit->selfAndDescendantIds(), $covered);

            if ($beyond !== []) {
                $fail('Your own role does not cover everything under '.$unit->name
                    .', so this grant cannot reach down there. Leave this off to cover '.$unit->name.' alone.');
            }
        };
    }

    /**
     * Refuse a grant the client already has.
     *
     * The database holds one grant per person per role per part of the company, counting
     * the whole-company grant as one of them, and a refused insert reaches a client as an
     * error page saying nothing.
     */
    private function notHeldOverTheSamePartAlready(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (blank($value)) {
                return;
            }

            $unitId = $get('org_unit_id') ?: null;

            $held = RoleAssignment::query()
                ->where('role_id', $this->roleBeingKept()->getKey())
                ->where('user_id', $value)
                ->where('org_unit_id', $unitId)
                ->exists();

            if ($held) {
                $named = User::query()->whereKey($value)->first()?->name ?? 'That person';
                $over = $unitId === null
                    ? 'the whole company'
                    : (OrgUnit::query()->find($unitId)?->name ?? 'that part of the company');

                $fail($named.' already holds this role over '.$over.'. Choose a different part of the company, or somebody else.');
            }
        };
    }

    private function roleBeingKept(): Role
    {
        /** @var Role $role */
        $role = $this->getOwnerRecord();

        return $role;
    }
}
