<?php

namespace App\Filament\Resources\Offices\Tables;

use App\Filament\Resources\Offices\OfficeResource;
use App\Filament\Resources\Offices\Schemas\OfficeForm;
use App\Models\Office;
use App\Models\OfficeHoliday;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class OfficesTable
{
    /**
     * Nothing here deletes, the same as the two lists beside it: a job row keeps its own
     * copy of the country and state it read, so an office that has closed is switched
     * off rather than removed.
     *
     * The row's second action opens that office's own list of the dates it is shut.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                // "State code", not "State". The column beside it saying whether the
                // office is in use is a state as well, and one table using the same word
                // for a province and for whether something is switched on is a table
                // nobody reads twice the same way. This is the code that is typed —
                // IN-HP — so the label says so.
                TextColumn::make('state_code')
                    ->label('State code')
                    ->placeholder('None')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('country')
                    ->sortable(),

                // Read as one line of day names rather than as the numbers the database
                // holds. An office that works every day says so instead of showing a
                // blank cell nobody can tell from a missing answer.
                TextColumn::make('weekly_off_days')
                    ->label('Days off each week')
                    ->state(fn (Office $record): string => self::daysOffNamed($record))
                    ->placeholder('Open every day'),

                TextColumn::make('holidays_count')
                    ->label('Dates closed')
                    ->counts('holidays')
                    ->alignEnd(),

                TextColumn::make('active')
                    ->label('State of the office')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'In use' : 'Closed')
                    ->color(fn (Office $record): string => $record->active ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('active')
                    ->label('State of the office')
                    ->trueLabel('In use')
                    ->falseLabel('Closed')
                    ->placeholder('All'),
            ])
            ->emptyStateHeading('No offices yet')
            ->emptyStateDescription('Add every place your people work from. A deadline is counted against the working days of the office somebody works in.')
            ->recordActions([
                EditAction::make(),

                Action::make('holidays')
                    ->label('Dates closed')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(fn (Office $record): string => OfficeResource::getUrl('holidays', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->can('viewAny', OfficeHoliday::class) ?? false),
            ]);
    }

    private static function daysOffNamed(Office $office): string
    {
        $days = array_map('intval', (array) $office->weekly_off_days);
        sort($days);

        return implode(', ', array_map(
            fn (int $day): string => OfficeForm::Weekdays[$day] ?? (string) $day,
            $days,
        ));
    }
}
