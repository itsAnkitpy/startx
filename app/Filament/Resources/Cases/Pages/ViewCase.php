<?php

namespace App\Filament\Resources\Cases\Pages;

use App\Exceptions\ProcessRefused;
use App\Filament\Resources\Cases\CaseResource;
use App\Models\ProcessCase;
use App\Models\Succession;
use App\Models\User;
use App\Process\CaseHistory;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * One case, with the whole story of it underneath — and, on the exit of somebody who has
 * left, the one thing that can be done from this screen.
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
            $this->handOverTheirWork(),
        ];
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
                    ->default(CarbonImmutable::now()->toDateString())
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
            .'It happens in one go and there is no undo. What '.$leaver->name
            .' has already answered stays recorded in their name.';
    }
}
