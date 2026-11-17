<?php

namespace App\Filament\Auth;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use SensitiveParameter;

/**
 * A person's own account page: when they are told, and the clock they read times on.
 *
 * Nobody sets either for anybody else. Filament's own page only ever edits whoever is
 * signed in, and the people screen deliberately carries neither field. Name, address and
 * password stay where they are — the people screen and the sign-in — because nothing asked
 * for them here.
 */
class EditProfile extends BaseEditProfile
{
    /** What the hour box starts at for somebody choosing the summary for the first time. */
    private const SixInTheEvening = 18;

    public static function getLabel(): string
    {
        return 'Your account';
    }

    public function form(Schema $schema): Schema
    {
        $companysZone = Filament::auth()->user()->tenant->timezone;

        return $schema
            ->inlineLabel(false)
            ->components([
                Section::make('When you are told')
                    ->description('You choose when an email reaches you, never whether. Reminders, a step that has run out of time, a missed legal deadline, a chase somebody presses by hand, and anything about your own case always come at once.')
                    ->schema([
                        Radio::make('told')
                            ->hiddenLabel()
                            ->options([
                                'at_once' => 'Each email as it happens',
                                'daily' => 'One email a day with everything in it',
                            ])
                            ->descriptions([
                                'daily' => 'What became yours that day, and news about requests you raised.',
                            ])
                            ->required()
                            ->live(),

                        Select::make('daily_summary_hour')
                            ->label('Send it at')
                            ->helperText('On your own clock, below.')
                            ->options(collect(range(0, 23))
                                ->mapWithKeys(fn (int $hour): array => [$hour => CarbonImmutable::today()->setTime($hour, 0)->format('g a')])
                                ->all())
                            ->selectablePlaceholder(false)
                            ->required()
                            ->visible(fn (Get $get): bool => $get('told') === 'daily'),
                    ]),

                Section::make('Your clock')
                    ->schema([
                        // Only real zones are on the list, and a select refuses anything not
                        // on its list, the same as the company's own.
                        Select::make('timezone')
                            ->label('Time zone')
                            ->helperText("Every time you see, on screen and in email, is shown in this zone. Leave it empty to use your company's, {$companysZone}.")
                            ->placeholder("Your company's ({$companysZone})")
                            ->options(collect(DateTimeZone::listIdentifiers())
                                ->mapWithKeys(fn (string $zone): array => [$zone => str_replace('_', ' ', $zone)])
                                ->all())
                            ->searchable(),
                    ]),
            ]);
    }

    /**
     * Only this page's own three answers. Filament hands over every column on the account,
     * and whatever is filled in here travels to the browser with the page.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            'told' => $data['daily_summary_hour'] === null ? 'at_once' : 'daily',
            'daily_summary_hour' => $data['daily_summary_hour'] ?? self::SixInTheEvening,
            'timezone' => $data['timezone'],
        ];
    }

    /**
     * The one column holds both answers: no hour is told at once.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        $data['daily_summary_hour'] = $data['told'] === 'daily' ? (int) $data['daily_summary_hour'] : null;
        unset($data['told']);

        return $data;
    }
}
