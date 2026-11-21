<?php

namespace App\Filament\Resources\Cases\Pages;

use App\Exceptions\ProcessRefused;
use App\Filament\Resources\Cases\CaseResource;
use App\Models\ProcessCase;
use App\Models\Succession;
use App\Models\User;
use App\Notifications\Chasing;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Process\CaseHistory;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Facades\FilamentTimezone;

/**
 * One case, with the whole story of it underneath — and, on somebody's exit, the four
 * things that can be done to it from this screen: change its last working day, withdraw
 * it, override a clearance on hold, and hand over their work.
 */
class ViewCase extends ViewRecord
{
    protected static string $resource = CaseResource::class;

    public function getTitle(): string
    {
        /** @var ProcessCase $case */
        $case = $this->getRecord();

        return '#'.$case->number.' · '.(new CaseHistory)->whatItIsAbout($case);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->chase(),
            $this->changeLastWorkingDay(),
            $this->withdraw(),
            $this->overrideAHold(),
            $this->handOverTheirWork(),
        ];
    }

    /**
     * Close a clearance on hold over the department holding it, because whoever holds it is
     * away or the argument is holding up a legal settlement (module 07, "A hold is never a
     * wall").
     *
     * Nothing is approved: the step reads as overridden, in this person's name with the
     * holder's beside it, anything the department recorded stays on it, and the holder is
     * emailed. A hold this person holds themselves is not offered; they end it from their
     * own queue.
     */
    private function overrideAHold(): Action
    {
        return Action::make('overrideHold')
            ->label('Override the hold')
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (): bool => $this->holdsThatCanBeOverridden() !== [])
            ->modalHeading(fn (): string => 'Override a hold on '.$this->leaver()?->name.'\'s exit')
            ->modalDescription('The clearance closes without saying the department agreed, so the exit can go on. Their hold, your name and your reason stay on the record, anything they recorded such as an amount to recover stays on it, and whoever was holding it is emailed.')
            ->modalSubmitActionLabel('Override it')
            ->schema([
                Select::make('sequence')
                    ->label('Which clearance')
                    ->options(fn (): array => $this->holdsThatCanBeOverridden())
                    ->default(fn (): ?int => count($this->holdsThatCanBeOverridden()) === 1 ? array_key_first($this->holdsThatCanBeOverridden()) : null)
                    ->native(false)
                    ->required()
                    ->validationMessages([
                        'required' => 'Choose which clearance to override.',
                    ]),

                Textarea::make('reason')
                    ->label('Why you are overriding it')
                    ->helperText('Whoever was holding it is emailed this reason.')
                    ->required()
                    ->maxLength(255)
                    ->rows(2)
                    ->validationMessages([
                        'required' => 'Say why you are overriding it.',
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                $case = $this->theCase();

                try {
                    $overridden = (new CaseEngine)->resolveHold($case, (int) $data['sequence'], 'force_closed', auth()->user(), (string) $data['reason']);
                } catch (ProcessRefused $refused) {
                    Notification::make()
                        ->danger()
                        ->title('The hold was not overridden')
                        ->body($refused->getMessage())
                        ->send();

                    $action->halt();

                    return;
                }

                // The story below is read off the case's trail, loaded before this line was.
                $case->refresh();

                Notification::make()
                    ->success()
                    ->title('Hold overridden')
                    ->body($overridden->assignee?->name.' has been emailed.')
                    ->send();
            });
    }

    /**
     * Each clearance on hold this person may override, named with who is holding it.
     *
     * **Private, for the reason {@see self::whatWouldMove()} gives**: a public method on a
     * page is one the browser may call by name.
     *
     * @return array<int, string>
     */
    private function holdsThatCanBeOverridden(): array
    {
        $case = $this->theCase();
        $person = auth()->user();

        if ($case->state !== ProcessCase::Open || ! $person instanceof User || $person->cannot('overrideHold', $case)) {
            return [];
        }

        // Not remembered for the request, unlike the chase list: the override itself and the
        // redraw after it are one request, and a remembered list would keep the button there.
        return (new AvailableSteps)->for($case)
            ->filter(fn (AvailableStep $waiting): bool => $waiting->attempt?->outcome === 'held'
                && (int) $waiting->attempt->assignee_id !== (int) $person->getKey())
            ->mapWithKeys(fn (AvailableStep $waiting): array => [
                (int) $waiting->step->sequence => $waiting->step->name.' — held by '.$waiting->attempt->assignee?->name,
            ])
            ->all();
    }

    /**
     * Move the last working day, or correct how the employment is ending, and both legal
     * deadlines with them.
     *
     * **The new deadlines are on screen before anybody saves**, and the dialog says plainly
     * when the settlement date has already passed: an absconder's real last day is in the
     * past, and refusing it would make HR type a false date (module 07, grill 4).
     */
    private function changeLastWorkingDay(): Action
    {
        return Action::make('changeLastWorkingDay')
            ->label('Change last working day')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => $this->mayBeChangedHere())
            ->modalHeading(fn (): string => 'Change '.$this->leaver()?->name.'\'s last working day')
            ->modalDescription('Both legal deadlines are worked out again from what you choose here. If the date changes, everybody working on the exit and the person leaving are emailed the new date.')
            ->modalSubmitActionLabel('Save the change')
            ->fillForm(fn (): array => [
                'last_working_day' => $this->theCase()->statutory_from?->toDateString(),
                'employment_ended_by' => $this->theCase()->employment_ended_by,
            ])
            ->schema([
                DatePicker::make('last_working_day')
                    ->label('Last working day')
                    ->required()
                    ->live()
                    ->validationMessages([
                        'required' => 'Give their last working day.',
                    ]),

                Select::make('employment_ended_by')
                    ->label('How their employment ends')
                    ->options(ProcessCase::WaysEmploymentEnds)
                    ->native(false)
                    ->required()
                    ->live()
                    ->validationMessages([
                        'required' => 'Choose how their employment is ending.',
                    ]),

                Text::make(fn (Get $get): string => $this->theDeadlinesItWouldHave($get('last_working_day'), $get('employment_ended_by')))
                    ->key('deadlines'),

                $this->theHandoverStaysAsItIs(),

                Textarea::make('reason')
                    ->label('Why it is changing')
                    ->helperText('If the date changes, the person leaving is emailed this reason.')
                    ->required()
                    ->maxLength(255)
                    ->rows(2)
                    ->validationMessages([
                        'required' => 'Say why it is changing.',
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                $case = $this->theCase();

                try {
                    $changed = (new CaseEngine)->amendTheDateTheClocksCountFrom(
                        $case,
                        (string) $data['last_working_day'],
                        auth()->user(),
                        (string) $data['reason'],
                        (string) $data['employment_ended_by'],
                    );
                } catch (ProcessRefused $refused) {
                    // The engine may have written the new dates onto this copy before the
                    // database refused them.
                    $case->refresh();

                    Notification::make()
                        ->danger()
                        ->title('The last working day was not changed')
                        ->body($refused->getMessage())
                        ->send();

                    $action->halt();

                    return;
                }

                // The page reads the change off the case's own trail and lists what was
                // sent, and the copy it holds was loaded before either existed.
                $case->refresh();

                if (! $changed) {
                    Notification::make()
                        ->info()
                        ->title('Nothing changed')
                        ->body('That is already its last working day and how their employment ends.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Last working day changed')
                    ->body('Settlement is now due by '.$case->statutory_due_at->format('j F Y').'.')
                    ->send();
            });
    }

    /**
     * Withdraw the exit, because the person is staying.
     *
     * Nothing already done is undone: what each department said stays readable, and the
     * case reads as cancelled for ever. Deepak withdrawing his own resignation does it from
     * the raise screen, because this page is not his to read.
     */
    private function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label('Withdraw')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (): bool => $this->mayBeChangedHere())
            ->modalHeading(fn (): string => 'Withdraw '.$this->leaver()?->name.'\'s exit')
            ->modalDescription('Nothing already done on it is undone or deleted, and it can never be finished afterwards. Everybody working on it, the person leaving and whoever raised it are emailed.')
            ->modalSubmitActionLabel('Withdraw it')
            ->schema([
                $this->theHandoverStaysAsItIs(),

                Textarea::make('reason')
                    ->label('Why it is being withdrawn')
                    ->helperText('The person leaving is emailed this reason.')
                    ->required()
                    ->maxLength(255)
                    ->rows(2)
                    ->validationMessages([
                        'required' => 'Say why it is being withdrawn.',
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                $case = $this->theCase();

                try {
                    (new CaseEngine)->cancel($case, auth()->user(), (string) $data['reason']);
                } catch (ProcessRefused $refused) {
                    Notification::make()
                        ->danger()
                        ->title('This exit was not withdrawn')
                        ->body($refused->getMessage())
                        ->send();

                    $action->halt();

                    return;
                }

                $case->refresh();

                Notification::make()
                    ->success()
                    ->title('Exit withdrawn')
                    ->body('Everybody working on it has been emailed.')
                    ->send();
            });
    }

    /**
     * Whether this person may change this case from here: an exit still running, in the
     * part of the company they look after, and not their own.
     */
    private function mayBeChangedHere(): bool
    {
        $case = $this->theCase();

        return $case->state === ProcessCase::Open
            && $case->subject_user_id !== null
            && (auth()->user()?->can('changeExit', $case) ?? false);
    }

    /**
     * The settlement and gratuity deadlines the dialog's answers would give, said before
     * anybody saves.
     */
    private function theDeadlinesItWouldHave(?string $day, ?string $endedBy): string
    {
        if (blank($day)) {
            return '';
        }

        $case = $this->theCase();

        try {
            [$settlement, $gratuity] = (new CaseEngine)->deadlinesIfItCountedFrom($case, $day, $endedBy);
        } catch (ProcessRefused $refused) {
            return $refused->getMessage();
        } catch (InvalidFormatException) {
            return '';
        }

        $passed = $settlement->toDateString() < CarbonImmutable::now($case->tenant->timezone)->toDateString();

        return 'Settlement would be due by '.$settlement->format('j F Y').'.'
            .($passed ? ' That date has already passed.' : '')
            .' Gratuity: '.($gratuity === null ? 'not owed.' : 'due by '.$gratuity->format('j F Y').'.');
    }

    /**
     * The warning both dialogs carry once the leaver's work has been handed on: who took it,
     * from when and what moved, and that it stays where it is. Withdrawing is still allowed,
     * because refusing it would switch off somebody who is staying (module 07, grill 5), and
     * there is no undo for a handover.
     */
    private function theHandoverStaysAsItIs(): Callout
    {
        return Callout::make('Their work has already been handed over')
            ->key('handover')
            ->warning()
            ->description(function (): string {
                $handover = (new CaseHistory)->handoverSettled($this->theCase());

                if ($handover === null) {
                    return '';
                }

                return $handover['to'].' took it on from '.CarbonImmutable::parse($handover['on'])->format('j F Y').': '
                    .$handover['moved']['roles'].' '.($handover['moved']['roles'] === 1 ? 'role' : 'roles').', '
                    .$handover['moved']['reporting_lines'].' '.($handover['moved']['reporting_lines'] === 1 ? 'person' : 'people').' reporting to them and '
                    .$handover['moved']['approvals'].' '.($handover['moved']['approvals'] === 1 ? 'approval' : 'approvals').'. '
                    .($handover['moved']['stand_in'] ? 'They also became the one who picks up a step nobody holds. ' : '')
                    .'That stays as it is. Give roles and people back by hand on the roles and people screens'
                    .($handover['moved']['stand_in'] ? ', and who picks up a step nobody holds on the settings screen.' : '.');
            })
            ->visible(fn (): bool => (new CaseHistory)->handoverSettled($this->theCase()) !== null);
    }

    private function theCase(): ProcessCase
    {
        /** @var ProcessCase $case */
        $case = $this->getRecord();

        return $case;
    }

    /**
     * Chase whoever is holding a step up, by hand, with your name on it.
     *
     * Here rather than on a deadlines screen, because the case page is where somebody goes
     * to look at a stuck case. Anybody who can open the case may press it — whoever raised
     * it, and whoever holds a role over the person it is about — and nobody else can open
     * it, so the whole company cannot use it to pester one department.
     *
     * It goes at once, whatever the day, to whoever holds the step today, and only once a
     * day each. A step only a link can answer is chased with a fresh link. The second press
     * is refused with a sentence rather than the button disappearing, so it says why.
     */
    private function chase(): Action
    {
        return Action::make('chase')
            ->label('Chase')
            ->icon('heroicon-o-bell-alert')
            ->visible(fn (): bool => $this->whatCanBeChased() !== [])
            ->modalHeading('Chase whoever is holding this up')
            ->modalDescription('They are emailed straight away, with your name on it, and it shows below under what we sent. Somebody answering through a link is sent a fresh link instead. Each person can be chased about each step once a day.')
            ->modalSubmitActionLabel('Chase them')
            ->schema([
                Select::make('sequence')
                    ->label('Which step')
                    ->options(fn (): array => $this->whatCanBeChased())
                    ->default(fn (): ?int => count($this->whatCanBeChased()) === 1 ? array_key_first($this->whatCanBeChased()) : null)
                    ->native(false)
                    ->required()
                    ->validationMessages([
                        'required' => 'Choose which step to chase.',
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                /** @var ProcessCase $case */
                $case = $this->getRecord();

                // The list below reads what was sent off the copy of the case this page is
                // holding, which was loaded before the chase existed — and a chase that did
                // not go out shows there too.
                $case->unsetRelation('messagesSent');

                try {
                    $chased = (new Chasing)->byHand($case, (int) $data['sequence'], auth()->user());
                } catch (ProcessRefused $refused) {
                    Notification::make()
                        ->danger()
                        ->title('Nobody was chased')
                        ->body($refused->getMessage())
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Chased '.$chased->pluck('name')->join(', ', ' and '))
                    ->body('They have been emailed just now.')
                    ->send();
            });
    }

    /**
     * Each step waiting on somebody this person could chase, named with who holds it.
     *
     * **Private, for the same reason as the sentence below:** a public method on a page is
     * one the browser may call by name.
     *
     * @return array<int, string>
     */
    private function whatCanBeChased(): array
    {
        /** @var ProcessCase $case */
        $case = $this->getRecord();
        $person = auth()->user();

        if (! $person instanceof User) {
            return [];
        }

        // Asked by the button's visibility, its list and its default in one request; worked
        // out once.
        return once(function () use ($case, $person): array {
            $chasing = new Chasing;

            return (new AvailableSteps)->for($case)
                ->mapWithKeys(function (AvailableStep $step) use ($chasing, $person): array {
                    $holders = $chasing->whoCanBeChasedBy($step, $person);

                    return $holders->isEmpty()
                        ? []
                        : [(int) $step->step->sequence => $step->step->name.' — '.$holders->pluck('name')->join(', ', ' and ')];
                })
                ->all();
        });
    }

    /**
     * Settle who takes on the work of the person this case is about.
     *
     * **It lives on the exit rather than on a form of its own**, because there is no
     * handover without a departure behind it: the record reads who is leaving off the case,
     * so the two can never disagree about whose work is moving. Workday reaches the same
     * shape from the other direction, settling a leaver's pending work as a step inside its
     * own termination process.
     *
     * **What would move is on screen before anybody confirms.** Reassigning approvals while
     * quietly rewriting the org chart is not acceptable; doing it deliberately with the
     * consequences in front of the person confirming is exactly right, because it is the one
     * moment the company knows it is happening.
     *
     * Hidden once it has been settled. A second attempt is refused by the record with a
     * sentence naming who holds the work now, and offering a control that can only be
     * refused is how a screen teaches somebody not to trust it.
     */
    private function handOverTheirWork(): Action
    {
        return Action::make('handOverTheirWork')
            ->label('Hand over their work')
            ->icon('heroicon-o-arrows-right-left')
            ->color('danger')
            ->visible(fn (): bool => $this->mayBeSettledHere())
            ->modalHeading(fn (): string => 'Hand over '.$this->leaver()?->name.'\'s work')
            ->modalDescription(fn (): string => $this->whatWouldMove())
            ->modalSubmitActionLabel('Hand it over')
            ->schema([
                Select::make('successor')
                    ->label('Who takes it on')
                    ->options(fn (): array => $this->whoCouldTakeItOn())
                    ->searchable()
                    ->required()
                    ->validationMessages([
                        'required' => 'Choose who takes this work on.',
                    ]),

                DatePicker::make('effective_at')
                    ->label('From which day')
                    // Today on the reader's calendar, not UTC's: at half past one in the
                    // morning in India, UTC's today is still yesterday.
                    ->default(fn (): string => today(FilamentTimezone::get())->toDateString())
                    ->required()
                    ->helperText('Usually the day after their last working day. Everybody who reported to them gets a new dated row starting on this date, and their old one keeps its end date.')
                    ->validationMessages([
                        'required' => 'Say which day the handover takes effect.',
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                /** @var ProcessCase $case */
                $case = $this->getRecord();

                $successor = User::query()->find($data['successor']);

                if (! $successor instanceof User) {
                    // The picker only ever offers people who work here, and what comes
                    // back is checked against that list before it reaches this, so nothing
                    // on the screen can get here today and there is no test that could.
                    // It is said rather than passed over anyway: the control closing with
                    // nothing on the screen looks exactly like a handover that worked, on
                    // the one action in the product that cannot be undone.
                    Notification::make()
                        ->danger()
                        ->title('This work cannot be handed over')
                        ->body('That person is no longer somebody the work can pass to. Choose somebody else.')
                        ->send();

                    $action->halt();

                    return;
                }

                try {
                    $moved = Succession::handOver($case, $successor, auth()->user(), $data['effective_at']);
                } catch (ProcessRefused $refused) {
                    // Every one of these already reads as a sentence written for a client,
                    // so it is said as it stands rather than translated a second time —
                    // unlike the cover screen's one refusal, whose sentence named a process
                    // by a permanent name no client ever sees.
                    Notification::make()
                        ->danger()
                        ->title('This work cannot be handed over')
                        ->body($refused->getMessage())
                        ->send();

                    $action->halt();

                    return;
                }

                // The page reads the handover off the case's own trail, and the copy of the
                // case it is holding was loaded before that line existed.
                $case->refresh();

                Notification::make()
                    ->success()
                    ->title($successor->name.' has taken the work on')
                    ->body('From '.$moved->effective_at->format('j F Y').'. Everything that moved is recorded on this case and on each case whose step changed hands.')
                    ->send();
            });
    }

    /**
     * Whether this case is one a handover can be settled from, and by this person.
     *
     * Asked of the policy, which wants the action over the whole client company for the
     * same reason cover does: the grants being moved can cover the whole company
     * themselves, so somebody responsible for one branch would otherwise inherit the
     * finance head's company-wide role by settling her exit.
     */
    private function mayBeSettledHere(): bool
    {
        /** @var ProcessCase $case */
        $case = $this->getRecord();

        return (new CaseHistory)->handoverSettled($case) === null
            && (auth()->user()?->can('settleHandover', $case) ?? false);
    }

    private function leaver(): ?User
    {
        /** @var ProcessCase $case */
        $case = $this->getRecord();

        return $case->subject;
    }

    /**
     * Everybody who could take the work on, which is everybody still working here bar the
     * person leaving.
     *
     * The person confirming is deliberately still on the list even though the record
     * refuses them: "you cannot be the one confirming that the work passes to you" is a
     * thing an HR person will genuinely try, and a sentence saying so teaches the rule
     * where a missing name teaches nothing.
     *
     * @return array<int, string>
     */
    private function whoCouldTakeItOn(): array
    {
        $leaver = (int) ($this->leaver()?->getKey() ?? 0);

        $everybody = User::everybodyHere();

        unset($everybody[$leaver]);

        return $everybody;
    }

    /**
     * The sentence above the confirm button: what moves, counted now.
     *
     * The approvals are counted through the reader that works out whose turn a step is
     * rather than from the rows people have already opened — most of a branch manager's
     * waiting approvals are steps nobody has touched, and a count taken from the rows would
     * show a reassuringly small number and miss exactly the work most likely to breach.
     *
     * **Private, and it has to stay private.** Every public method a Filament page declares
     * is one the browser may call by name and read the answer to, so this being public
     * handed the roles somebody holds, the size of their team and the work waiting on them
     * to anybody who could open their exit — the roles screen asks for a grant before it
     * shows any of that. A test reads the sentence off the mounted control instead, which
     * is the sentence a person is actually shown rather than merely the one this returns.
     */
    private function whatWouldMove(): string
    {
        $leaver = $this->leaver();

        if (! $leaver instanceof User) {
            return '';
        }

        $moving = Succession::whatWouldMove($leaver);

        $roles = $moving['roles'] === []
            ? 'no roles'
            : 'these roles: '.implode(', ', $moving['roles']);

        return 'Whoever takes this on inherits '
            .$moving['approvals_waiting'].' '.($moving['approvals_waiting'] === 1 ? 'approval' : 'approvals')
            .' waiting on '.$leaver->name.', '
            .$moving['direct_reports'].' '.($moving['direct_reports'] === 1 ? 'person' : 'people')
            .' reporting to them, and '.$roles.'. '
            .($moving['stand_in'] ? 'They also become the one who picks up a step nobody holds. ' : '')
            .'It happens in one go and there is no undo. What '.$leaver->name
            .' has already answered stays recorded in their name.';
    }
}
