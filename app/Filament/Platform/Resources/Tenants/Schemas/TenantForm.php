<?php

namespace App\Filament\Platform\Resources\Tenants\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The company')
                    ->schema([
                        TextInput::make('name')
                            ->label('Company name')
                            ->helperText('What their own people will see at the top of every screen.')
                            ->required()
                            ->maxLength(255),

                        // This becomes part of a web address, so it is held to the shape of
                        // one — the same rule the front page's company box applies. It cannot
                        // be changed afterwards: it is the address every employee has
                        // bookmarked and every link in every email already points at, so
                        // moving a company is a migration rather than an edit.
                        TextInput::make('slug')
                            ->label('Address')
                            ->prefix('https://')
                            ->suffix('.'.config('tenancy.central_domain'))
                            ->helperText('Lowercase letters, numbers and hyphens. Cannot be changed later.')
                            ->required()
                            ->maxLength(63)
                            ->rule('regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/')
                            ->validationMessages([
                                'regex' => 'Lowercase letters, numbers and hyphens only, and it cannot start or end with a hyphen.',
                            ])
                            ->unique(ignoreRecord: true)
                            ->disabledOn(Operation::Edit),

                        TextInput::make('legal_name')
                            ->label('Registered name')
                            ->helperText('The name on their paperwork, if it differs. Used on letters.')
                            ->maxLength(255),

                        // Switched off is what the "signing in is not available" page reads.
                        // Not offered while creating, because a company nobody can sign in to
                        // is not a company that has been set up.
                        Toggle::make('active')
                            ->label('Signing in is allowed')
                            ->helperText('Turn this off and nobody at the company can sign in. They are told to speak to their HR team, not why.')
                            ->visibleOn(Operation::Edit),
                    ]),

                Section::make('Their first administrators')
                    ->description('A company keeps at least two administrators, so it starts with two. They can add the rest themselves.')
                    ->visibleOn(Operation::Create)
                    ->schema([
                        Repeater::make('administrators')
                            ->hiddenLabel()
                            ->addActionLabel('Add another administrator')
                            ->minItems(2)
                            ->defaultItems(2)
                            ->itemLabel(fn (array $state): ?string => $state['work_email'] ?? null)
                            ->columns(2)
                            ->schema([
                                TextInput::make('first_name')
                                    ->required()
                                    ->maxLength(255),

                                TextInput::make('last_name')
                                    ->maxLength(255),

                                TextInput::make('work_email')
                                    ->label('Work email address')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    // Two rows of this form cannot claim the same address,
                                    // which the database would only catch after the company
                                    // row had already been written.
                                    ->rule(fn (Get $get): callable => function (string $attribute, mixed $value, callable $fail) use ($get): void {
                                        $addresses = array_map(
                                            fn (array $row): string => strtolower(trim((string) ($row['work_email'] ?? ''))),
                                            (array) $get('../../administrators'),
                                        );

                                        if (count(array_keys($addresses, strtolower(trim((string) $value)), true)) > 1) {
                                            $fail('This address is used twice.');
                                        }
                                    }),

                                // Typed here and handed over, because there is no outbound
                                // mail yet and so no invitation to send. When mail works this
                                // becomes an invitation link and the field goes.
                                TextInput::make('password')
                                    ->label('Temporary password')
                                    ->password()
                                    ->revealable()
                                    ->required()
                                    ->minLength(8)
                                    ->maxLength(255)
                                    ->helperText('Give this to them yourself.'),
                            ]),
                    ]),
            ]);
    }
}
