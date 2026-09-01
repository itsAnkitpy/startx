<?php

namespace App\Filament\Resources\OrgUnits;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\OrgUnits\Pages\ManageOrgUnits;
use App\Filament\Resources\OrgUnits\Schemas\OrgUnitForm;
use App\Filament\Resources\OrgUnits\Tables\OrgUnitsTable;
use App\Models\OrgUnit;
use App\Models\User;
use App\Policies\OrgUnitPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The client's own departments and branches — the first screen their administrator
 * touches, and the one every other setup screen points at.
 *
 * One page with modals rather than separate create and edit pages: the record is five
 * short fields, and staying on the list means the shape of the company is still on
 * screen while a part of it is being added. The longer records later in this module —
 * a person and their job history — get their own pages.
 *
 * Whether somebody may open this screen at all, see one row, or change one, is
 * {@see OrgUnitPolicy}. Nothing here asks about a role.
 */
class OrgUnitResource extends Resource
{
    protected static ?string $model = OrgUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'Structure';

    /**
     * Before the settings screen, which sits at 90. An entry with no sort is read as
     * coming before everything, which is how the Cases screen ended up first in the
     * menu.
     */
    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'department or branch';

    protected static ?string $pluralModelLabel = 'departments and branches';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return OrgUnitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrgUnitsTable::configure($table);
    }

    /**
     * Only the parts of the company this person's own grant covers.
     *
     * The permission check that opens the screen answers "may they do this anywhere at
     * all", so a list that trusted it would show Rakesh — HR head over Shimla alone —
     * every branch Meridian has. Asking which parts he covers is the difference, and it
     * is asked here rather than in each of this module's six lists.
     *
     * No signed-in person means no rows, so a missing session shows nothing rather than
     * everything.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $person = auth()->user();

        if (! $person instanceof User) {
            return $query->whereKey([]);
        }

        $reachable = app(PermissionResolver::class)->reachableUnitIds($person, Permission::ViewOrgUnit);

        return $reachable === null ? $query : $query->whereKey($reachable);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOrgUnits::route('/'),
        ];
    }
}
