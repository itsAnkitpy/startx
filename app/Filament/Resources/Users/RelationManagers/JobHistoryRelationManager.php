<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\EmployeeRecordRefused;
use App\Models\Designation;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Every job one person has held here, newest first, drawn underneath their own details.
 *
 * **Nothing on this list is edited.** That is the design rather than an omission. A
 * promotion, a transfer or a change of manager adds a dated row and leaves every earlier
 * row exactly as it was, which is what lets a case closed last March still show the
 * department that person was in last March. A row entered by mistake is withdrawn with a
 * reason, and the row before it takes its end date back. The products this was checked
 * against have arrived at the same place — Workday stopped letting its own administrators
 * correct a job change after the event, because the correction moves payroll and reporting
 * with it.
 *
 * So the wording throughout says "record a change" and never "edit". A form that reads as
 * correcting a mistake gets used as one.
 *
 * Rule 1 of this module lands here: a job row names a part of the company, so the picker
 * offers only the parts this person's own grant covers. Filament checks a submitted answer
 * by looking it up through the same narrowed query that filled the picker, so narrowing
 * what is offered is what refuses what is sent.
 */
class JobHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'employmentRecords';

    protected static ?string $title = 'Job history';

    /**
     * The kinds of employment this screen offers. Held here rather than in the database on
     * purpose: module 01 left the column a plain string because the words each later module
     * needs arrive with that module, and a list fixed in the database now would be
     * inventing them early. These three are the words the client's own hiring request form
     * already puts in front of them.
     */
    public const EmploymentTypes = [
        'permanent' => 'Permanent',
        'contract' => 'Fixed-term contract',
        'intern' => 'Intern',
    ];

    /**
     * Where somebody is in their employment. The same four words module 01's own migration
     * named when it said the list belongs with the processes that set it — probation with
     * onboarding, notice and exited with the exit flow.
     */
    public const EmploymentStatuses = [
        'probation' => 'On probation',
        'confirmed' => 'Confirmed',
        'notice' => 'Serving notice',
        'exited' => 'Left',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('effective_from')
                    ->label('This change takes effect from')
                    ->helperText('The job they hold now is closed off the day before this date. Nothing already recorded changes.')
                    ->required()
                    ->rule(fn (): Closure => $this->dateTheChangeMayStartOn()),

                Select::make('org_unit_id')
                    ->label('Department or branch')
                    ->relationship(
                        'orgUnit',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $this->partsOfTheCompanyOnOffer($query),
                    )
                    ->required()
                    ->searchable()
                    ->preload()
                    ->validationMessages([
                        'in' => 'That is not a department or branch you can record a job in.',
                        'exists' => 'That is not a department or branch you can record a job in.',
                        'required' => 'Choose the department or branch this job sits in.',
                    ]),

                Select::make('designation_id')
                    ->label('Designation')
                    ->helperText('From the list your company keeps. This row keeps its own copy of the words, so renaming the entry later does not change what this row says.')
                    ->relationship(
                        'designation',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $this->stillInUsePlusTheirs(
                            $query,
                            Designation::class,
                            'designation_id',
                        ),
                    )
                    ->searchable()
                    ->preload(),

                Select::make('office_id')
                    ->label('Office')
                    ->helperText('Which office their deadlines are counted against.')
                    ->relationship(
                        'office',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $this->stillInUsePlusTheirs(
                            $query,
                            Office::class,
                            'office_id',
                        ),
                    )
                    ->searchable()
                    ->preload(),

                Select::make('employment_type')
                    ->label('Employment type')
                    ->options(self::EmploymentTypes)
                    ->required()
                    ->default('permanent'),

                Select::make('employment_status')
                    ->label('Where they are in their employment')
                    ->options(self::EmploymentStatuses)
                    ->required()
                    ->default('confirmed'),

                Select::make('reports_to_id')
                    ->label('Reports to')
                    ->helperText('Left empty at the top of the company. Anybody here may be named, including somebody in another branch.')
                    ->options(fn (): array => $this->everybodyElseHere())
                    ->searchable()
                    ->rule(fn (): Closure => $this->managerDoesNotReportBackToThem()),

                TextInput::make('employee_code')
                    ->label('Employee number')
                    ->helperText('Shown to whoever approves a request about them. Leave it empty to carry forward the one they already have.')
                    ->maxLength(255),

                DatePicker::make('last_working_day')
                    ->label('Last working day')
                    ->helperText('Only on the row that records somebody leaving. Their documents and their settlement are counted from it.')
                    ->rule(fn (Get $get): Closure => $this->lastDayIsNotBeforeTheChange($get)),

                Textarea::make('change_reason')
                    ->label('Why it changed')
                    ->helperText('Read by anybody looking back at this person\'s history — "Promoted to Senior Manager", "Moved to the Pune branch".')
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('effective_from')
                    ->label('From')
                    ->date()
                    ->sortable(),

                TextColumn::make('effective_to')
                    ->label('Until')
                    ->date()
                    ->placeholder('The job they hold now'),

                TextColumn::make('orgUnit.name')
                    ->label('Department or branch'),

                // The words this row read when it was written, not the list entry's words
                // today. That is the whole reason a copy sits on the row.
                TextColumn::make('recorded_designation_name')
                    ->label('Designation')
                    ->placeholder('None recorded'),

                TextColumn::make('office.name')
                    ->label('Office')
                    ->placeholder('None recorded'),

                TextColumn::make('employment_status')
                    ->label('Employment')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::inOurWords(self::EmploymentStatuses, $state))
                    ->color(fn (?string $state): string => $state === 'exited' ? 'gray' : 'success')
                    ->description(fn (EmploymentRecord $record): string => self::inOurWords(
                        self::EmploymentTypes,
                        $record->employment_type,
                    )),

                TextColumn::make('reportsTo.first_name')
                    ->label('Reported to')
                    ->state(fn (EmploymentRecord $record): ?string => $record->reportsTo?->name)
                    ->placeholder('Nobody'),

                TextColumn::make('change_reason')
                    ->label('Why it changed')
                    ->placeholder('Not recorded')
                    ->wrap(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Record a change')
                    ->modalHeading('Record a change to their job')
                    ->modalDescription('This adds a dated row. Every row already recorded stays exactly as it is, so a case decided earlier still shows what was true then.')
                    ->modalSubmitActionLabel('Record it')
                    ->using(fn (array $data): EmploymentRecord => EmploymentRecord::recordAChange(
                        $this->personBeingKept(),
                        $data,
                    )),
            ])
            ->emptyStateHeading('No job recorded yet')
            ->emptyStateDescription('Record their joining — the department they start in, the date they started, and what they are called. Every later move adds another dated row rather than changing this one.')
            ->recordActions([
                Action::make('withdraw')
                    ->label('Withdraw')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->modalHeading('Withdraw a row entered by mistake')
                    ->modalDescription('Use this only for a row that should never have existed. A job that genuinely changed is a new row instead. The row before this one takes its end date back, so the history closes over the gap.')
                    ->modalSubmitActionLabel('Withdraw this row')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why this row should not exist')
                            ->helperText('Kept with the row, so anybody reading the history later can see what happened.')
                            ->required()
                            ->maxLength(255)
                            ->rows(2),
                    ])
                    ->visible(fn (EmploymentRecord $record): bool => auth()->user()?->can('withdraw', $record) ?? false)
                    ->action(function (EmploymentRecord $record, array $data): void {
                        try {
                            $record->withdraw($this->actor(), (string) $data['reason']);
                        } catch (EmployeeRecordRefused) {
                            // The one refusal a person can reach from here: a case already
                            // reads this person's department, designation and manager
                            // through this row, which is exactly what the row was pinned
                            // for. Said in ordinary words rather than as an error page.
                            Notification::make()
                                ->danger()
                                ->title('This row cannot be withdrawn')
                                ->body('A case already reads their department, designation and manager from it. Record a change instead, so the history stays readable.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Row withdrawn')
                            ->send();
                    }),
            ]);
    }

    /**
     * The parts of the company this picker offers, which is also what it refuses.
     *
     * Two narrowings, in this order. An archived department stays on offer while somebody is
     * still in it, because otherwise recording a promotion for one of its people would force
     * a move they did not make — the same hole step 2's review found on the structure screen.
     * Then rule 1: only the parts this person's own grant covers, so somebody responsible for
     * one branch cannot write a job row naming another.
     */
    private function partsOfTheCompanyOnOffer(Builder $units): Builder
    {
        $reachable = app(PermissionResolver::class)->reachableUnitIds(
            $this->actor(),
            Permission::UpdatePerson,
        );

        // Worked out on a query of its own and applied as one flat list, the same way the
        // structure screen does it. Filament hands this method a query with no model behind
        // it, so a grouped "this or that" cannot be asked of it directly.
        $offered = OrgUnit::query()
            ->where('active', true)
            ->when($reachable !== null, fn (Builder $covered): Builder => $covered->whereKey($reachable))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $whereTheyAreNow = EmploymentRecord::currentFor($this->personBeingKept())?->org_unit_id;
        $stillCovered = $reachable === null || in_array((int) $whereTheyAreNow, $reachable, true);

        if ($whereTheyAreNow !== null && $stillCovered) {
            $offered[] = (int) $whereTheyAreNow;
        }

        return $units->whereKey(array_values(array_unique($offered)));
    }

    /**
     * One of the client's two short lists, narrowed to what is still in use plus the entry
     * this person's own row already points at.
     *
     * The second half is the same answer the department picker above already gives, for the
     * same reason. A client who retires "Operations Officer" while Deepak still holds it
     * would otherwise have his next row refused unless somebody gave him a designation he
     * was never promoted into — or left it empty, which is what the panel above every
     * approval reads when it names what somebody is called.
     */
    private function stillInUsePlusTheirs(Builder $entries, string $list, string $column): Builder
    {
        // Worked out on a query of its own and applied as one flat list, the same way the
        // department picker does it and for the same reason: this method is handed a query
        // with no model behind it, so a grouped "this or that" cannot be asked of it.
        $offered = $list::query()
            ->where('active', true)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $theirs = EmploymentRecord::currentFor($this->personBeingKept())?->{$column};

        if ($theirs !== null) {
            $offered[] = (int) $theirs;
        }

        return $entries->whereKey(array_values(array_unique($offered)));
    }

    /**
     * Refuse a manager who already reports up to this person, under the box that names them.
     *
     * The record refuses it too, and would otherwise reach a client as an error page —
     * naming somebody's own junior as their manager is an ordinary slip, and it is three
     * clicks away in the demo. The walk is the record's own, so the screen and the record
     * cannot come to different answers.
     */
    private function managerDoesNotReportBackToThem(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $person = $this->personBeingKept();

            if (blank($value)) {
                return;
            }

            if (EmploymentRecord::wouldLoopReportingLine((int) $person->getKey(), (int) $value)) {
                // Named the way the picker above names them, so the sentence points at the
                // entry that has just been chosen rather than at "that person".
                $named = User::query()->whereKey($value)->first()?->name ?? 'That person';

                $fail($named.' already reports up to '.$person->name
                    .', so naming them here would send the reporting line back round. Name somebody above them instead.');
            }
        };
    }

    /**
     * Everybody else at this client company, by their whole name.
     *
     * Not narrowed to the asker's own branch, deliberately: somebody in Shimla reporting to
     * a regional head sitting in Pune is ordinary, and who somebody reports to is not a
     * permission boundary. The record refuses a reporting line that comes back round.
     *
     * @return array<int, string>
     */
    private function everybodyElseHere(): array
    {
        return User::query()
            ->whereKeyNot($this->personBeingKept()->getKey())
            ->where('active', true)
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (User $person): array => [$person->getKey() => $person->name])
            ->all();
    }

    /**
     * When a change may start.
     *
     * Nothing is said at all where there is no job to close off: the first row of somebody's
     * history may start on any date, including a future one, because there is no earlier row
     * for a future start to leave a gap in front of.
     *
     * Where there is one, two things are refused. A date at or before the day the current job
     * started, which the database refuses anyway — as an error page, and after telling the
     * client nothing. And a date in the future, because closing today's job off yesterday and
     * starting the new one next month leaves their history empty in between, and every
     * question this product answers about a past date reads that history.
     */
    private function dateTheChangeMayStartOn(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $current = EmploymentRecord::currentFor($this->personBeingKept());
            $startsOn = self::asADate($value);

            if ($current === null || $startsOn === null) {
                return;
            }

            if ($startsOn->lessThanOrEqualTo($current->effective_from)) {
                $fail('The job they hold now started on '
                    .$current->effective_from->format('j F Y')
                    .', so a change to it has to start after that.');

                return;
            }

            if ($startsOn->isFuture()) {
                $fail('A change to a job somebody already holds cannot start in the future — their history would have nothing in it between today and then. Record it on the day it takes effect.');
            }
        };
    }

    /**
     * A last working day cannot fall before the row it sits on begins.
     *
     * Says nothing while the date above it is empty or is not a date, because that box
     * already says so itself — the mistake step 3's review found on the offices form, where
     * a state was compared against an empty country box and answered with a sentence that
     * had no country in it.
     */
    private function lastDayIsNotBeforeTheChange(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $lastDay = self::asADate($value);
            $startsOn = self::asADate($get('effective_from'));

            if ($lastDay === null || $startsOn === null) {
                return;
            }

            if ($lastDay->lessThan($startsOn)) {
                $fail('Their last working day cannot come before the date this change takes effect.');
            }
        };
    }

    private function personBeingKept(): User
    {
        /** @var User $person */
        $person = $this->getOwnerRecord();

        return $person;
    }

    private function actor(): User
    {
        /** @var User $person */
        $person = auth()->user();

        return $person;
    }

    /**
     * @param  array<string, string>  $list
     */
    private static function inOurWords(array $list, ?string $stored): string
    {
        if (blank($stored)) {
            return 'Not recorded';
        }

        // A row seeded or imported before this screen existed may hold a word the list does
        // not carry. Shown as it reads rather than as a blank cell.
        return $list[$stored] ?? Str::headline($stored);
    }

    private static function asADate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        return rescue(fn (): Carbon => Carbon::parse($value), null, report: false);
    }
}
