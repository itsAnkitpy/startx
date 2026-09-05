<?php

namespace App\Filament\Resources\Cases\Schemas;

use App\Models\ProcessCase;
use App\Process\CaseHistory;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class WhatHappenedOnIt
{
    /**
     * One case: what it is, what happened at every step of it, and the handover settled
     * inside it if there was one.
     *
     * **Every step of the frozen version the case opened on has a line here, including the
     * ones nobody ever touched.** That is the whole reason the page exists: a step that
     * never opened is missing from every other list in the product, because those only
     * hold steps that did happen — so an exit could reach the end with an approval never
     * given and look identical to one done properly.
     *
     * Built from Filament's own repeated entries rather than from a hand-written template,
     * which is what this module's hard gate asks for and the one place it was genuinely in
     * doubt. The step's sentence and the earlier passes at it are composed by the reader
     * next door; all this does is lay them out.
     */
    public static function configure(Schema $schema): Schema
    {
        $history = new CaseHistory;

        return $schema
            ->components([
                Section::make('What this case is')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('template.name')
                            ->label('Process'),

                        TextEntry::make('template.version')
                            ->label('Version')
                            ->prefix('v'),

                        TextEntry::make('state')
                            ->label('State')
                            ->badge()
                            ->state(fn (ProcessCase $record): string => $history->stateOf($record))
                            ->color(fn (ProcessCase $record): string => match ($history->stateOf($record)) {
                                'Finished' => 'success',
                                'Cancelled' => 'gray',
                                default => 'info',
                            }),

                        TextEntry::make('opened_at')
                            ->label('Started')
                            ->date(),

                        TextEntry::make('initiatedBy.first_name')
                            ->label('Raised by')
                            ->state(fn (ProcessCase $record): string => $record->initiatedBy?->name ?? 'The system'),

                        // The failure the check at publishing exists to prevent, said at
                        // the top of the case it happened to as well as against the step
                        // itself further down. Absent on every ordinary case.
                        TextEntry::make('never_happened_count')
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
                            })
                            ->visible(fn (?string $state): bool => filled($state)),
                    ]),

                Section::make('The handover settled here')
                    ->columnSpanFull()
                    ->description('What moved when this person left, and to whom.')
                    ->visible(fn (ProcessCase $record): bool => $history->handoverSettled($record) !== null)
                    ->columns(4)
                    ->schema([
                        TextEntry::make('handover_to')
                            ->label('Taken over by')
                            ->state(fn (ProcessCase $record): string => $history->handoverSettled($record)['to'] ?? ''),

                        TextEntry::make('handover_on')
                            ->label('From')
                            ->state(fn (ProcessCase $record): string => $history->handoverSettled($record)['on'] ?? '')
                            ->date(),

                        TextEntry::make('handover_roles')
                            ->label('Roles moved')
                            ->state(fn (ProcessCase $record): int => $history->handoverSettled($record)['moved']['roles'] ?? 0),

                        TextEntry::make('handover_reports')
                            ->label('People who now report to them')
                            ->state(fn (ProcessCase $record): int => $history->handoverSettled($record)['moved']['reporting_lines'] ?? 0),
                    ]),
                Section::make('Step by step')
                    ->columnSpanFull()
                    ->description('Every step of the version this case opened on, whether or not it happened.')
                    ->schema([
                        RepeatableEntry::make('steps')
                            ->hiddenLabel()
                            ->state(fn (ProcessCase $record): array => self::laidOut($history, $record))
                            ->schema([
                                TextEntry::make('step')
                                    ->hiddenLabel()
                                    ->weight(FontWeight::Medium),

                                // Two entries for one sentence, so that the one saying an
                                // approval never happened reads in the colour that means
                                // it while the ordinary ones stay quiet. The words carry
                                // the meaning either way — the colour is only there to be
                                // found while scrolling.
                                TextEntry::make('said')
                                    ->hiddenLabel()
                                    ->color('gray')
                                    ->visible(fn (?string $state): bool => filled($state)),

                                TextEntry::make('never_happened')
                                    ->hiddenLabel()
                                    ->color('danger')
                                    ->weight(FontWeight::Medium)
                                    ->visible(fn (?string $state): bool => filled($state)),

                                // A step that came round more than once. The line above
                                // says where it stands; these say what happened before it,
                                // oldest first, so a correction stops erasing the send-back
                                // that asked for it.
                                TextEntry::make('earlier')
                                    ->label('Earlier at this step')
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->visible(fn (?array $state): bool => filled($state)),
                            ]),
                    ]),

            ]);
    }

    /**
     * The reader's answer, with each step's sentence sorted into the entry that shows it.
     *
     * The split into two sentences is laid out here rather than in the reader, because
     * which of them is filled in is a question about how the page draws a failure and not
     * a question about what happened on the case.
     *
     * @return list<array<string, mixed>>
     */
    private static function laidOut(CaseHistory $history, ProcessCase $case): array
    {
        return array_map(fn (array $step): array => [
            'step' => $step['sequence'].'. '.$step['name'],
            'said' => $step['tone'] === 'missed' ? null : $step['said'],
            'never_happened' => $step['tone'] === 'missed' ? $step['said'] : null,
            'earlier' => $step['earlier'],
        ], $history->stepByStep($case));
    }
}
