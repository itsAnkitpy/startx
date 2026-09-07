<?php

namespace App\Filament\Resources\NotificationTemplates\Tables;

use App\Models\NotificationTemplate;
use App\Notifications\TemplateCatalogue;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The messages this client sends, one per row.
 *
 * **What each row says is mostly not in the database.** A message's name, who reads it
 * and what sends it live in code beside the wording we ship, because none of them is the
 * client's to change — so the three columns that matter most are read off the record
 * rather than selected, which is also why none of them sorts or searches. The subject
 * line does both, because it is the client's own and it is what they will look for.
 *
 * There is no delete action and no bulk action group. Nothing on this screen removes a
 * message, and nothing switches one off either.
 */
class NotificationTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Message')
                    ->formatStateUsing(fn (NotificationTemplate $record): string => $record->name())
                    ->description(fn (NotificationTemplate $record): string => 'Sent when: '.lcfirst($record->sentWhen())),

                TextColumn::make('goes_to')
                    ->label('Goes to')
                    ->state(fn (NotificationTemplate $record): string => $record->goesTo())
                    ->wrap(),

                TextColumn::make('subject')
                    ->label('Subject line')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                // Words rather than an icon standing alone for a state, the same as every
                // other list a client keeps here.
                TextColumn::make('reworded')
                    ->label('Wording')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yours' : 'Ours')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            // The order the catalogue itself is written in, which reads down the page by
            // who receives each message: the person the case is about, then whoever
            // raised it, then whoever a step is waiting on, then the two about the
            // product itself failing. Sorting on the message's own key put "your case
            // has been completed" above "your case has been started", because that order
            // is alphabetical in a word nobody on this screen can see.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw(
                'array_position(?::text[], key)',
                ['{'.implode(',', array_keys(TemplateCatalogue::definitions())).'}'],
            ))
            // Every message on one screen. The catalogue is fixed and short, so paging
            // through it would hide half of it behind a button for no reason.
            ->paginated(false)
            ->filters([
                TernaryFilter::make('reworded')
                    ->label('Wording')
                    ->trueLabel('The ones you have changed')
                    ->falseLabel('The ones still in our words')
                    ->placeholder('All'),
            ])
            // Worded for the filter rather than for an empty table, because an empty
            // table is not a state a client can reach — every message is put in front
            // of a company while it is being set up. Filtering to the ones they have
            // changed when they have changed none is, and the words that were here
            // before told them to ring us about it.
            ->emptyStateHeading('No message matches the filter')
            ->emptyStateDescription('Clear the wording filter above and every message this product sends is back.')
            ->recordActions([
                EditAction::make()->label('Change the wording'),
            ]);
    }
}
