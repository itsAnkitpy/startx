<?php

namespace App\Filament\Resources\Cases\Tables;

use App\Filament\Resources\Cases\CaseResource;
use App\Models\ProcessCase;
use App\Process\CaseHistory;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CasesTable
{
    /**
     * Every case this client has run, newest first.
     *
     * **Filters rather than tabs or grouping**, which is settled and worth not reopening:
     * grouping eight identical rows under a ninth heading leaves eight identical rows, and
     * a client running eleven processes cannot have eleven tabs. Jira Service Management
     * offers request type and status as the two filters it always puts in front of a
     * request list and hides everything else, which is exactly these two.
     *
     * The one mark carried here rather than left to the case's own page is a step nobody
     * was ever asked for, because that is the whole reason this screen exists and a client
     * should not have to open two hundred cases to find the one that finished without an
     * approval.
     */
    public static function configure(Table $table): Table
    {
        // One reader for the whole page, so the answer about a process version is worked
        // out once however many of the client's cases ran on it.
        $history = new CaseHistory;

        return $table
            ->recordTitle(fn (ProcessCase $record): string => '#'.$record->number)
            ->columns([
                // Searched with or without the hash. The hash is only painted on, so a
                // client told to quote case #4 and typing it back exactly as they were
                // given it found nothing at all — on the one column this screen exists to
                // put in front of them. Matched as text, so a name typed into the same box
                // never asks Postgres to read a word as a number.
                TextColumn::make('number')
                    ->label('Case')
                    ->prefix('#')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $digits = ltrim(trim($search), '#');

                        return $digits === ''
                            ? $query
                            : $query->whereRaw('cast(cases.number as text) like ?', ['%'.$digits.'%']);
                    })
                    ->sortable(),

                // The client's own words for what a case is about: the person's name where
                // there is one, and otherwise the first things their own form asks, because
                // whoever wrote the form put the question that identifies a request at the
                // top of it. Searched on the person's name, which is the half a database
                // can look through.
                TextColumn::make('subject.first_name')
                    ->label('What it is about')
                    ->state(fn (ProcessCase $record): string => $history->whatItIsAbout($record))
                    ->searchable(['first_name', 'last_name'])
                    ->wrap(),

                TextColumn::make('template.name')
                    ->label('Process')
                    ->searchable()
                    ->sortable(),

                // Words rather than a colour standing alone, the same as every other list
                // in this module.
                TextColumn::make('closed_at')
                    ->label('State')
                    ->badge()
                    ->state(fn (ProcessCase $record): string => $history->stateOf($record))
                    ->color(fn (ProcessCase $record): string => match ($history->stateOf($record)) {
                        'Finished' => 'success',
                        'Cancelled' => 'gray',
                        default => 'info',
                    }),

                // The failure the check at publishing exists to prevent, said out loud on
                // the row it happened to. Without it the case reads exactly like one that
                // ran properly. Empty on every ordinary case, so the mark keeps meaning
                // something when it does appear.
                TextColumn::make('id')
                    ->label('Warning')
                    ->badge()
                    ->color('danger')
                    ->state(function (ProcessCase $record) use ($history): ?string {
                        $missing = $history->stepsNobodyWasAskedFor($record);

                        return match (true) {
                            $missing === 0 => null,
                            $missing === 1 => 'A step never happened',
                            default => $missing.' steps never happened',
                        };
                    }),

                TextColumn::make('opened_at')
                    ->label('Started')
                    ->date()
                    ->sortable(),

                TextColumn::make('initiatedBy.first_name')
                    ->label('Raised by')
                    ->state(fn (ProcessCase $record): string => $record->initiatedBy?->name ?? 'The system')
                    ->searchable(['first_name', 'last_name'])
                    ->toggleable(),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->label('State')
                    ->options([
                        'running' => 'Running',
                        'finished' => 'Finished',
                        'cancelled' => 'Cancelled',
                    ])
                    // Read off the two timestamps, because that is where a case's state
                    // lives — there is no status column for a filter to disagree with.
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'running' => $query->whereNull('closed_at')->whereNull('cancelled_at'),
                        'finished' => $query->whereNotNull('closed_at')->whereNull('cancelled_at'),
                        'cancelled' => $query->whereNotNull('cancelled_at'),
                        default => $query,
                    }),

                SelectFilter::make('process')
                    ->label('Process')
                    ->options(fn (): array => CaseResource::processNames())
                    // On the permanent name rather than the version, so a client asking for
                    // their exits gets every exit rather than the ones that opened on
                    // whichever version happens to be live today.
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('template', fn (Builder $version) => $version->where('key', $data['value']))
                        : $query),
            ])
            ->emptyStateHeading('No cases yet')
            ->emptyStateDescription('A case appears here as soon as one starts that you can see, including anything you raise yourself.')
            ->recordActions([
                ViewAction::make()->label('Open'),
            ]);
    }
}
