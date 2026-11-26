<?php

namespace App\Filament\Resources\Cases\Schemas;

use App\Models\IssuedDocument;
use App\Models\NotificationDelivery;
use App\Models\ProcessCase;
use App\Models\SettlementLine;
use App\Models\Succession;
use App\Models\User;
use App\Process\CaseHistory;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Facades\FilamentTimezone;

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
                // Weeks before HR's final check, not only when it is refused: the same
                // sentence the refusal gives, so HR can hand the work over while the
                // clearances are still running (module 07 step 4).
                Callout::make('Waiting on a handover')
                    ->key('waitingOnAHandover')
                    ->warning()
                    ->columnSpanFull()
                    ->description(fn (ProcessCase $record): string => (string) Succession::whatStillHasToMove($record))
                    ->visible(fn (ProcessCase $record): bool => $record->state === ProcessCase::Open
                        && Succession::whatStillHasToMove($record) !== null),

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

                        // A moment shown as a day, which Filament reads on UTC's calendar
                        // unless told otherwise.
                        TextEntry::make('opened_at')
                            ->label('Started')
                            ->date('j F Y', FilamentTimezone::get()),

                        TextEntry::make('initiatedBy.first_name')
                            ->label('Raised by')
                            ->state(fn (ProcessCase $record): string => $record->initiatedBy?->name ?? 'The system'),

                        // An exit's legal clock: the day it counts from, how the employment
                        // ended, and the two deadlines that follow from both. Plain dates, so
                        // deliberately not given the reader's clock the way "Started" is: a
                        // company behind UTC would see every deadline a day early.
                        TextEntry::make('statutory_from')
                            ->label('Last working day')
                            ->date('j F Y')
                            ->visible(fn (ProcessCase $record): bool => $record->statutory_from !== null),

                        // Beside the day it follows from (module 07 step 5).
                        TextEntry::make('sign_in')
                            ->label('Sign-in')
                            ->state(fn (ProcessCase $record): ?string => $history->signIn($record))
                            ->visible(fn (?string $state): bool => filled($state)),

                        TextEntry::make('employment_ended_by')
                            ->label('How their employment ends')
                            ->state(fn (ProcessCase $record): string => ProcessCase::WaysEmploymentEnds[$record->employment_ended_by] ?? 'Not recorded')
                            ->visible(fn (ProcessCase $record): bool => $record->statutory_from !== null),

                        TextEntry::make('statutory_due_at')
                            ->label('Settlement due by')
                            ->date('j F Y')
                            ->visible(fn (ProcessCase $record): bool => $record->statutory_from !== null),

                        TextEntry::make('gratuity_due_at')
                            ->label('Gratuity due by')
                            ->date('j F Y')
                            ->placeholder('Not owed')
                            ->visible(fn (ProcessCase $record): bool => $record->statutory_from !== null),

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

                        // A moved last working day and a withdrawal happen to the case
                        // rather than at a step, so the step list below never shows them.
                        TextEntry::make('changes_since_it_opened')
                            ->label('Changed since it opened')
                            ->state(fn (ProcessCase $record): array => $history->changesSinceItOpened($record))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull()
                            ->visible(fn (?array $state): bool => filled($state)),
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
                            ->date('j F Y'),

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

                // Module 08's statement. Shown only to whoever may see the money on this
                // exit, not to everybody who can open it: a department clearing it reads
                // no amount anywhere else on the page (Ankit, 25 November 2026).
                Section::make('Settlement')
                    ->columnSpanFull()
                    ->description('Every amount a clearance has claimed from the person leaving or owes them. The net of claims is what the departments raised, not what is paid: payroll applies the wages, the statutory deductions and the limit on what may be deducted.')
                    // On every exit, and wherever money was filed: a figure is never hidden
                    // because the case it is on has no last working day.
                    ->visible(fn (ProcessCase $record): bool => ($record->statutory_from !== null || $record->settlementLines->isNotEmpty())
                        && (auth()->user()?->can('viewSettlement', $record) ?? false))
                    ->schema([
                        TextEntry::make('nothing_filed')
                            ->hiddenLabel()
                            ->color('gray')
                            // Money filed and none of it counted can only be a send-back,
                            // the one thing that takes an answer off the statement, while
                            // the claim email below still says it was claimed.
                            ->state(fn (ProcessCase $record): string => $record->settlementLines->isEmpty()
                                ? 'No clearance has claimed or owed any money yet.'
                                : 'Nothing counts yet. Money was filed, but the clearance was sent back, and only what it files when it is answered again will count.')
                            ->visible(fn (ProcessCase $record): bool => SettlementLine::onTheStatementOf($record) === []),

                        self::amounts('claimed', SettlementLine::Recovery, 'Claimed from'),
                        self::amounts('owed', SettlementLine::Payable, 'Owed to'),

                        Group::make([
                            TextEntry::make('claimed_total')
                                ->label(fn (ProcessCase $record): string => 'Claimed from '.$record->subject?->name)
                                ->state(fn (ProcessCase $record): string => SettlementLine::inRupees(SettlementLine::total(SettlementLine::onTheStatementOf($record), SettlementLine::Recovery))),

                            TextEntry::make('owed_total')
                                ->label(fn (ProcessCase $record): string => 'Owed to '.$record->subject?->name)
                                ->state(fn (ProcessCase $record): string => SettlementLine::inRupees(SettlementLine::total(SettlementLine::onTheStatementOf($record), SettlementLine::Payable))),

                            TextEntry::make('net_of_claims')
                                ->label('Net of claims')
                                ->weight(FontWeight::Medium)
                                ->state(fn (ProcessCase $record): string => SettlementLine::netOfClaims($record)),
                        ])
                            ->columns(3)
                            ->visible(fn (ProcessCase $record): bool => SettlementLine::onTheStatementOf($record) !== []),

                        // Referenced, never worked out: computing it is payroll's statutory
                        // surface, not ours. A plain date, so not given the reader's clock.
                        TextEntry::make('gratuity')
                            ->label('Gratuity')
                            ->state(fn (ProcessCase $record): string => 'Due by '.$record->gratuity_due_at?->format('j F Y').'. Payroll works it out and pays it, so it is not in the net of claims.')
                            ->visible(fn (ProcessCase $record): bool => $record->gratuity_due_at !== null),
                    ]),

                // Module 09's documents. Each row shows only to whoever its own question lets
                // open it: a letter to whoever may see the person, the statement to whoever
                // may see the money, so Farhan clearing IT never learns there is one.
                Section::make('Documents issued')
                    ->columnSpanFull()
                    ->description('Each document exactly as it was issued. The file never changes, whatever happens to the wording afterwards.')
                    ->visible(fn (ProcessCase $record): bool => self::issuedTheyMayOpen($record) !== [])
                    ->schema([
                        RepeatableEntry::make('issued')
                            ->hiddenLabel()
                            ->state(fn (ProcessCase $record): array => self::issuedTheyMayOpen($record))
                            ->table([
                                TableColumn::make('Document'),
                                TableColumn::make('Issued by'),
                                TableColumn::make('When'),
                            ])
                            ->schema([
                                TextEntry::make('kind')
                                    ->hiddenLabel()
                                    ->weight(FontWeight::Medium)
                                    ->state(fn (IssuedDocument $record): string => $record->name())
                                    ->url(fn (IssuedDocument $record): string => route('issued-document', $record), shouldOpenInNewTab: true),

                                TextEntry::make('issuedBy.name')->hiddenLabel(),

                                TextEntry::make('created_at')->hiddenLabel()->dateTime('j F Y, H:i'),
                            ]),
                    ]),

                Section::make('What we sent about this case')
                    ->columnSpanFull()
                    ->description('Every message this product has sent about this case, newest first. "Sent" means our mail server accepted the message — it is not proof that anybody read it.')
                    ->schema([
                        // A case that has had nothing sent about it is a real answer and
                        // reads as one. Without this the heading sits above blank space,
                        // which looks like a screen that failed to load.
                        TextEntry::make('nothing_sent')
                            ->hiddenLabel()
                            ->color('gray')
                            ->state('Nothing has been sent about this case.')
                            ->visible(fn (ProcessCase $record): bool => $record->messagesSent->isEmpty()),

                        RepeatableEntry::make('messagesSent')
                            ->hiddenLabel()
                            ->visible(fn (ProcessCase $record): bool => $record->messagesSent->isNotEmpty())
                            ->table([
                                TableColumn::make('Message'),
                                TableColumn::make('Sent to'),
                                TableColumn::make('Why it went out'),
                                TableColumn::make('When'),
                                TableColumn::make('Sent'),
                                TableColumn::make('What went wrong'),
                            ])
                            ->schema([
                                TextEntry::make('template_key')
                                    ->hiddenLabel()
                                    ->weight(FontWeight::Medium)
                                    ->state(fn (NotificationDelivery $record): string => $record->name()),

                                // The address is always here and the name only sometimes,
                                // which is the record doing its job: a message to somebody
                                // with no account — a leaver answering through a link — has
                                // an address to show and nobody to link it to, and the
                                // address is what a tribunal reads either way.
                                // A link that could not be sent for want of a personal
                                // address has no address to show, and says so.
                                TextEntry::make('recipient_address')
                                    ->hiddenLabel()
                                    ->state(fn (NotificationDelivery $record): string => $record->recipient instanceof User
                                        ? $record->recipient->name.' · '.($record->recipient_address !== '' ? $record->recipient_address : 'no address on record')
                                        : $record->recipient_address.' (no account here)'),

                                TextEntry::make('why')
                                    ->hiddenLabel()
                                    ->color('gray')
                                    ->state(fn (NotificationDelivery $record): string => $record->whyItWentOut()),

                                // When it left, which for a message in somebody's daily
                                // summary is when the summary went, not when it was written.
                                TextEntry::make('created_at')
                                    ->hiddenLabel()
                                    ->state(fn (NotificationDelivery $record) => $record->sent_at ?? $record->created_at)
                                    ->dateTime('j F Y, H:i'),

                                TextEntry::make('status')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->state(fn (NotificationDelivery $record): string => $record->howItWent())
                                    ->color(fn (NotificationDelivery $record): string => match ($record->status) {
                                        NotificationDelivery::Sent => 'success',
                                        NotificationDelivery::Failed => 'warning',
                                        NotificationDelivery::Undelivered => 'danger',
                                        default => 'gray',
                                    }),

                                // Empty on almost every row, and its own column anyway: the
                                // one time it is filled, whoever raised the case has to be
                                // able to read why beside the message it happened to rather
                                // than open something to find out.
                                TextEntry::make('failure_reason')
                                    ->hiddenLabel()
                                    ->color('danger')
                                    ->placeholder(''),
                            ]),
                    ]),

            ]);
    }

    /**
     * The documents issued on this case that the person reading the page may open.
     *
     * @return list<IssuedDocument>
     */
    private static function issuedTheyMayOpen(ProcessCase $case): array
    {
        $reader = auth()->user();

        return $case->issuedDocuments
            ->filter(fn (IssuedDocument $document): bool => $reader?->can($document->openedBy(), $case) ?? false)
            ->values()->all();
    }

    /**
     * One side of the statement: what the clearances claimed from the person leaving, or what
     * they owe them. Only a claim says when the leaver was told, since only a claim starts
     * their seven days to explain.
     */
    private static function amounts(string $name, string $direction, string $heading): RepeatableEntry
    {
        $claims = $direction === SettlementLine::Recovery;

        return RepeatableEntry::make($name)
            ->label(fn (ProcessCase $record): string => $heading.' '.$record->subject?->name)
            ->state(fn (ProcessCase $record): array => self::laidOutAmounts($record, $direction))
            ->visible(fn (?array $state): bool => filled($state))
            ->table([
                TableColumn::make('Clearance'),
                TableColumn::make('Asked as'),
                TableColumn::make('Reason'),
                TableColumn::make('Amount'),
                TableColumn::make('Filed'),
                TableColumn::make('Disputed'),
                ...($claims ? [TableColumn::make('Leaver told')] : []),
            ])
            ->schema([
                TextEntry::make('clearance')->hiddenLabel()->weight(FontWeight::Medium),
                TextEntry::make('question')->hiddenLabel(),
                TextEntry::make('reason')->hiddenLabel()->placeholder(''),
                TextEntry::make('amount')->hiddenLabel(),
                TextEntry::make('filed')->hiddenLabel()->color('gray'),
                TextEntry::make('disputed')->hiddenLabel()->badge()->color('warning')->placeholder(''),
                ...($claims ? [TextEntry::make('told')->hiddenLabel()] : []),
            ]);
    }

    /**
     * @return list<array{clearance: string, question: string, reason: ?string, amount: string, filed: string, disputed: ?string, told: ?string}>
     */
    private static function laidOutAmounts(ProcessCase $case, string $direction): array
    {
        $clock = FilamentTimezone::get();

        return collect(SettlementLine::onTheStatementOf($case))
            ->where('line.direction', $direction)
            ->map(fn (array $row): array => [
                'clearance' => $row['clearance'],
                'question' => $row['line']->label,
                'reason' => $row['line']->reason,
                'amount' => SettlementLine::inRupees($row['line']->amount_paise),
                // Somebody with no account answers through a link, and their row names them.
                'filed' => ($row['line']->enteredBy?->name ?? ($row['attempt']->external_assignee['name'] ?? 'Somebody').' through a link')
                    .' on '.$row['filed_at']->timezone($clock)->format('j F Y'),
                'disputed' => $row['disputed'] ? 'Disputed' : null,
                'told' => $direction === SettlementLine::Recovery ? self::whenTold($row['told'], $clock) : null,
            ])
            ->values()->all();
    }

    /**
     * The day the claim email went, which starts the leaver's seven days to explain, and never
     * the day the amount was filed: a mail server that was down sends it a day late. A claim
     * email not sent yet says how it stands instead.
     */
    private static function whenTold(?NotificationDelivery $email, string $clock): string
    {
        if ($email === null) {
            return 'No email sent';
        }

        return $email->status === NotificationDelivery::Sent
            ? $email->sent_at->timezone($clock)->format('j F Y')
            : $email->howItWent();
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
