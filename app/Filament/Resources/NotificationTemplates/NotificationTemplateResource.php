<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplates\Schemas\NotificationTemplateForm;
use App\Filament\Resources\NotificationTemplates\Tables\NotificationTemplatesTable;
use App\Models\NotificationTemplate;
use App\Policies\NotificationTemplatePolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The messages this client sends, and the wording of each one.
 *
 * **A list and an edit page, with no create button and no delete button.** The client
 * cannot invent a message, because a message nothing sends is words nobody reads, and
 * cannot remove one, because a step sends a message by naming it. That is the whole
 * difference between this screen and the client's other lists, and it is why the two
 * buttons every other resource here carries are absent rather than hidden.
 *
 * **Its own page rather than a modal**, unlike the designations screen. The record is a
 * subject line and several paragraphs of a letter somebody will actually receive, so it
 * needs the width of a page and the list underneath it would be in the way.
 *
 * Whether somebody may open this screen at all is {@see NotificationTemplatePolicy}.
 */
class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'Messages we send';

    /** After cover at 60, before the settings at 90. */
    protected static ?int $navigationSort = 70;

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record instanceof NotificationTemplate ? $record->name() : null;
    }

    public static function form(Schema $schema): Schema
    {
        return NotificationTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
