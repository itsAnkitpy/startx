<?php

namespace App\Filament\Resources\Designations;

use App\Filament\Resources\Designations\Pages\ManageDesignations;
use App\Filament\Resources\Designations\Schemas\DesignationForm;
use App\Filament\Resources\Designations\Tables\DesignationsTable;
use App\Models\Designation;
use App\Policies\DesignationPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The client's own list of the jobs people hold — "Regional Head", "Driver".
 *
 * One page with modals, the shape settled on the company structure screen: the record is
 * two fields, and staying on the list keeps the whole list on screen while one is added.
 *
 * There is no narrowing here, unlike the structure screen. A designation belongs to the
 * client company rather than to one of its branches, so there is nothing for a grant over
 * one branch to cut the list down to — rule 2 of this module's three screen rules has
 * nothing to apply.
 *
 * Whether somebody may open this screen at all is {@see DesignationPolicy}.
 */
class DesignationResource extends Resource
{
    protected static ?string $model = Designation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'Designations';

    /** After the structure at 10, before the offices at 30 and the settings at 90. */
    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DesignationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DesignationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDesignations::route('/'),
        ];
    }
}
