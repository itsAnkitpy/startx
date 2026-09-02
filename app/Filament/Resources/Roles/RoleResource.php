<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RelationManagers\WhoHoldsItRelationManager;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use App\Models\Role;
use App\Policies\RolePolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The client's own roles, and under each one the people who hold it.
 *
 * One page per role rather than the pop-ups the two short lists use, because a role is
 * two things at once: what it can do, and who has it. The comparable products put both on
 * the role's own screen — the Google Admin console draws them as two tabs — and step 4
 * already settled the same shape here, with a person's job history sitting under the
 * person. Two separate screens would make a client carry a role's name in their head to
 * another list.
 *
 * Adding a role asks only for its name, and lands on the role's own page with the
 * tick-boxes empty and asking to be filled. Naming a role and deciding what it does are
 * two decisions, and both BambooHR and Google separate them.
 *
 * **There is no row narrowing here, unlike the structure and people screens.** A role
 * belongs to the client company rather than to one of its branches, so there is nothing
 * for a grant over one branch to cut down to. The narrowing lands one level in, on who
 * holds a role: a grant *is* on a part of the company. {@see WhoHoldsItRelationManager}.
 *
 * Whether somebody may open this screen at all, or change a role, is {@see RolePolicy}.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'Roles';

    /** After the people at 40, before the settings at 90. */
    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'role';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            WhoHoldsItRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
