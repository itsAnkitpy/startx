<?php

namespace App\Filament\Resources\Offices\Schemas;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Forms\UniqueNameInThisCompany;
use App\Models\Office;
use App\Models\User;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OfficeForm
{
    /** Day numbers as the product counts them everywhere: Sunday is 0, Saturday is 6. */
    public const Weekdays = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->helperText('What your own people call it — "Shimla office".')
                    ->required()
                    ->maxLength(255)
                    ->rule(fn (?Office $record): UniqueNameInThisCompany => new UniqueNameInThisCompany(
                        Office::class,
                        $record,
                        'You already have an office with this name.',
                    )),

                TextInput::make('country')
                    ->helperText('The two-letter code for the country — IN for India, AE for the United Arab Emirates.')
                    ->required()
                    ->default('IN')
                    ->maxLength(2)
                    ->dehydrateStateUsing(fn (?string $state): ?string => self::inCapitals($state))
                    ->regex('/^[A-Za-z]{2}$/')
                    ->validationMessages([
                        'regex' => 'Write the country as its two-letter code — IN for India.',
                    ]),

                // The one piece of geography the product genuinely needs, because
                // professional tax follows where a person works. The database refuses
                // anything that is not this shape, and refuses a state whose country half
                // disagrees with the country beside it, so the form has to say both in
                // ordinary words first.
                TextInput::make('state_code')
                    ->label('State')
                    ->helperText('The state\'s code, which starts with the country — IN-HP for Himachal Pradesh, IN-MH for Maharashtra. Leave it empty for a country with no states.')
                    ->maxLength(6)
                    ->dehydrateStateUsing(fn (?string $state): ?string => self::inCapitals($state))
                    ->rule(static fn (Get $get): Closure => self::stateBelongsToTheCountry($get)),

                Textarea::make('address_block')
                    ->label('Address')
                    ->helperText('Optional, and written as you would write it on an envelope. It is printed on letters and read nowhere else.')
                    ->rows(4),

                // Half of the calendar the statutory deadline is counted against, so it
                // is asked about under the working-calendar action rather than the one
                // that covers the rest of this form. Hidden means not saved, so somebody
                // who does not hold it edits an office's address without touching its
                // week.
                CheckboxList::make('weekly_off_days')
                    ->label('Days this office does not work')
                    ->helperText('The deadline on an exit is counted in working days, so these are skipped when it is worked out.')
                    ->options(self::Weekdays)
                    ->columns(2)
                    ->default([0, 6])
                    ->maxItems(6)
                    ->validationMessages([
                        'max' => 'An office has to work at least one day a week, so it cannot have all seven off.',
                    ])
                    ->visible(fn (): bool => self::maySetTheWorkingCalendar()),

                Toggle::make('active')
                    ->label('In use')
                    ->helperText('Turn this off to close it. Every job that already names it stays as it is.')
                    ->default(true),
            ]);
    }

    public static function maySetTheWorkingCalendar(): bool
    {
        $person = auth()->user();

        return $person instanceof User
            && app(PermissionResolver::class)->allows($person, Permission::ManageWorkingCalendar);
    }

    private static function inCapitals(?string $state): ?string
    {
        return blank($state) ? null : strtoupper(trim($state));
    }

    private static function stateBelongsToTheCountry(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (blank($value)) {
                return;
            }

            $code = strtoupper(trim((string) $value));
            $country = strtoupper(trim((string) $get('country')));

            if (preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $code) !== 1) {
                $fail('Write the state as its code — IN-HP for Himachal Pradesh.');

                return;
            }

            // Nothing to compare the state against until the country box holds a country.
            // Without this, an empty country box makes this one say the state "has to
            // begin -.", which is the country box's own message repeated as gibberish.
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                return;
            }

            if (! str_starts_with($code, $country.'-')) {
                $fail("A state's code starts with its own country, so this one has to begin {$country}-.");
            }
        };
    }
}
