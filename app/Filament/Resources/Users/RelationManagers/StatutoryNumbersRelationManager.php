<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\AadhaarVerification;
use App\Models\EmployeeStatutoryId;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * The tax, provident-fund, bank and passport numbers one person has on file. This is the
 * only screen in the product that can show one, which is rule 3 of this module.
 *
 * **Every value on this screen is read through the record's own reader**, never by reading
 * the column. The reader asks the separate identifier action and answers with a marker
 * saying the value is on file and withheld — so somebody who may see a person's record
 * still sees *which* numbers are held, and "no tax number on file" never looks the same as
 * "not yours to see". That ambiguity is how a number ends up entered twice.
 *
 * The list shows the last four digits behind dots and the whole number opens in a pop-up
 * from a control that says what it does, which is what the products checked here do and what
 * the payment-card rule requires: a whole number reaches only somebody with a stated need
 * for it. The masked tail comes through the same reader, so nobody without the action gets
 * the last four digits either.
 *
 * Nothing here is edited. An edit form fills its boxes from the record, which would hand
 * over a value the reader exists to withhold, so a wrong number is removed and entered
 * again. Removing one takes nothing out of anybody's history: no case, letter or job row
 * keeps a copy of a tax or bank number.
 */
class StatutoryNumbersRelationManager extends RelationManager
{
    protected static string $relationship = 'statutoryIds';

    protected static ?string $title = 'Tax and bank numbers';

    /**
     * The kinds the record already accepts, each in the words a client's administrator would
     * use rather than the word the column holds.
     */
    public const Kinds = [
        'pan' => 'PAN — income tax number',
        'universal_account_number' => 'Universal account number — provident fund',
        'provident_fund' => 'Provident fund account',
        'state_insurance' => 'State insurance (ESI) number',
        'bank_account' => 'Bank account number',
        'passport' => 'Passport number',
        'driving_licence' => 'Driving licence number',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('What it is')
                    ->options(self::Kinds)
                    ->required(),

                TextInput::make('country')
                    ->label('Country')
                    ->helperText('The two-letter code — IN for India. Somebody may hold the same kind of number in two countries.')
                    ->required()
                    ->default('IN')
                    ->maxLength(2)
                    ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : strtoupper(trim($state)))
                    ->regex('/^[A-Za-z]{2}$/')
                    ->validationMessages([
                        'regex' => 'Write the country as its two-letter code — IN for India.',
                    ]),

                TextInput::make('value')
                    ->label('The number')
                    ->helperText('Stored encrypted, and shown only to somebody whose role carries reading these.')
                    ->required()
                    ->maxLength(255)
                    ->rule(fn (Get $get): Closure => self::notAnAadhaarNumber($get)),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('What it is')
                    ->formatStateUsing(fn (?string $state): string => self::Kinds[$state] ?? Str::headline((string) $state)),

                TextColumn::make('country')
                    ->label('Country'),

                TextColumn::make('value')
                    ->label('The number')
                    ->state(fn (EmployeeStatutoryId $record): string => $this->asShown($record)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add a number')
                    ->modalHeading('Add a number to their file')
                    ->modalSubmitActionLabel('Add it')
                    // Asked about this person rather than about the action anywhere, which
                    // is all the record's own check can see before the number exists.
                    // Adding needs the reading action for a stated reason — entering a
                    // number without being able to check the one already on file is how
                    // one ends up recorded twice — and that reason only holds if it is the
                    // reading of *their* numbers being asked about.
                    ->authorize(fn (): bool => $this->mayReadTheirNumbers())
                    ->authorizationMessage('Adding a number needs the same permission as reading one, over this person\'s own part of the company.'),
            ])
            ->emptyStateHeading('No numbers on file')
            ->emptyStateDescription('A tax number, a provident-fund number or a bank account is added here. Reading one is a separate tick-box on a role, so it need not be in the same hands as the rest of somebody\'s record.')
            ->recordActions([
                Action::make('showTheWholeNumber')
                    ->label('Show the whole number')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading('The whole number')
                    ->modalDescription('On screen only for as long as this stays open. It is not written to any log.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->infolist([
                        TextEntry::make('value')
                            ->label(fn (EmployeeStatutoryId $record): string => self::Kinds[$record->type] ?? 'The number')
                            ->state(fn (EmployeeStatutoryId $record): string => $record->valueFor($this->actor()))
                            ->copyable(),
                    ])
                    // Hidden outright rather than opening on the withheld marker: the list
                    // already says the value is on file and not theirs to see, and a control
                    // offering to show it and then refusing reads as a fault.
                    ->visible(fn (EmployeeStatutoryId $record): bool => $record->valueFor($this->actor())
                        !== EmployeeStatutoryId::Withheld),

                DeleteAction::make()
                    ->label('Remove')
                    ->modalHeading('Remove this number')
                    ->modalDescription('Nothing else in the product keeps a copy of it, so removing it takes nothing out of their history.'),
            ]);
    }

    /**
     * May this reader see the numbers on *this person's* file, rather than somebody's
     * numbers somewhere in the company?
     *
     * The same question {@see EmployeeStatutoryId::valueFor()} answers per row, asked once
     * for the whole file — which is all a screen adding a number that does not exist yet
     * has to go on.
     */
    private function mayReadTheirNumbers(): bool
    {
        /** @var User $person */
        $person = $this->getOwnerRecord();

        return EmployeeStatutoryId::mayBeReadBy($this->actor(), $person);
    }

    /**
     * One row as this reader may see it: the last four digits behind dots, or the plain words
     * for a value that is on file and withheld.
     *
     * Read through the record's own reader in both cases. Masking the column directly would
     * hand the last four digits of somebody's bank account to a reader the record was about
     * to refuse.
     *
     * ponytail: the reader is asked twice per row — here, and again by the reveal control's
     * own visibility — and each ask re-reads the person and their most recent job row.
     * Measured rather than guessed: four numbers on one person cost 23 queries against 3 for
     * none, so about five a row on a tab that opens by hand and holds a handful of rows.
     * Left alone. Hand the answer down from {@see mayReadTheirNumbers()} the day a file
     * carries dozens of numbers, which no client's does.
     */
    private function asShown(EmployeeStatutoryId $identifier): string
    {
        $value = $identifier->valueFor($this->actor());

        if ($value === EmployeeStatutoryId::Withheld) {
            return 'On file — not yours to see';
        }

        // Nothing this short is a real tax or bank number, and showing the last four of a
        // five-character value is showing almost all of it.
        if (Str::length($value) <= 5) {
            return str_repeat('•', 8);
        }

        return str_repeat('•', 4).' '.Str::substr($value, -4);
    }

    /**
     * Refuse an Aadhaar number written into one of these boxes, in ordinary words.
     *
     * The record refuses it too, and would otherwise reach a client as an error page. This
     * product deliberately holds no Aadhaar number at all — there is no heading to store one
     * under, which is the point.
     *
     * Says nothing while the kind above is empty, and nothing for the two kinds that are
     * themselves twelve digits long: a provident-fund universal account number and plenty of
     * real Indian bank accounts are twelve digits, so refusing there would reject genuine
     * numbers. The record makes the same exception for the same reason.
     */
    private static function notAnAadhaarNumber(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $kind = (string) $get('type');

            if (blank($kind) || ! array_key_exists($kind, self::Kinds)) {
                return;
            }

            if (in_array($kind, ['universal_account_number', 'bank_account'], true)) {
                return;
            }

            if (AadhaarVerification::looksLikeANumber((string) $value)) {
                $fail('That looks like an Aadhaar number, and this product never stores one — there is no heading here it belongs under.');
            }
        };
    }

    private function actor(): User
    {
        /** @var User $person */
        $person = auth()->user();

        return $person;
    }
}
