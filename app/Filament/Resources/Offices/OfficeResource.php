<?php

namespace App\Filament\Resources\Offices;

use App\Filament\Resources\Offices\Pages\ManageOfficeHolidays;
use App\Filament\Resources\Offices\Pages\ManageOffices;
use App\Filament\Resources\Offices\Schemas\OfficeForm;
use App\Filament\Resources\Offices\Tables\OfficesTable;
use App\Models\Office;
use App\Policies\OfficePolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The places a client works from, and the calendar each one is closed on.
 *
 * One page with modals for the office itself, and a page of its own for the dates that
 * office is shut, reached from the row. The dates are a list rather than a field, so they
 * cannot sit in the same modal, and they belong to the office rather than to the company —
 * an office in Shimla and an office in Bengaluru have different public holidays, which is
 * what the whole per-office calendar exists for.
 *
 * There is no narrowing here, the same as the designations screen: an office belongs to
 * the client company rather than to one of its branches.
 *
 * Whether somebody may open this screen at all is {@see OfficePolicy}.
 */
class OfficeResource extends Resource
{
    protected static ?string $model = Office::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'Offices';

    /** After the designations at 20, before the settings at 90. */
    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return OfficeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OfficesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOffices::route('/'),
            'holidays' => ManageOfficeHolidays::route('/{record}/holidays'),
        ];
    }
}
