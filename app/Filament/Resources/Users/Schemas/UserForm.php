<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * Who somebody is, and nothing about their job — that is dated rows drawn underneath this
 * form, so a promotion or a transfer never overwrites what a closed case read.
 *
 * The name is asked for in parts because letters, statutory forms and the payroll handoff
 * all ask for the parts rather than one line of text.
 *
 * Two fields the account carries are deliberately not here: the timezone and the language.
 * Nothing in the product reads either one yet, so asking a client to fill them in would be
 * asking for something no screen or letter uses. They go on this form the day something
 * reads them.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Their name')
                    ->description('Held in parts, because a letter and a statutory form each ask for different parts of it.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')
                            ->label('First name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('last_name')
                            ->label('Last name')
                            ->maxLength(255),

                        TextInput::make('middle_name')
                            ->label('Middle name')
                            ->maxLength(255),

                        TextInput::make('preferred_name')
                            ->label('Known as')
                            ->helperText('What people here call them, if it is not their first name.')
                            ->maxLength(255),
                    ]),

                Section::make('How to reach them')
                    ->columns(2)
                    ->schema([
                        TextInput::make('work_email')
                            ->label('Work email')
                            ->helperText('This is what they sign in with.')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                                    'tenant_id',
                                    TenantContext::id(),
                                ),
                            )
                            ->validationMessages([
                                'unique' => 'Somebody here already signs in with that address.',
                            ]),

                        // The address that outlives the account. Every document owed after
                        // the last working day has to reach somebody whose sign-in is
                        // already switched off, so this is the durable one.
                        TextInput::make('personal_email')
                            ->label('Personal email')
                            ->helperText('Where a relieving letter or a settlement statement goes after their sign-in is switched off.')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('personal_phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(255),

                        DatePicker::make('date_of_birth')
                            ->label('Date of birth')
                            ->maxDate(now())
                            ->validationMessages([
                                'before_or_equal' => 'A date of birth cannot be in the future.',
                            ]),
                    ]),

                Section::make('Signing in')
                    ->columns(2)
                    ->schema([
                        // There is no way for somebody to reset their own password on this
                        // panel yet, so an administrator setting one is how a joiner gets
                        // in and how a locked-out person gets back in. Left empty on an
                        // existing person, nothing is written — the hashing cast would
                        // otherwise store an empty password.
                        TextInput::make('password')
                            ->label(fn (string $operation): string => $operation === 'create'
                                ? 'Password'
                                : 'Set a new password')
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? 'Give this to them, and ask them to change it.'
                                : 'Leave this empty to keep the password they already have.')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->minLength(8)
                            ->maxLength(255),

                        // Switching an account off is its own action, apart from keeping
                        // somebody's details up to date, because it is the one that stops
                        // them working. Hidden means not written, so somebody without it
                        // cannot switch an account off by submitting the form anyway.
                        //
                        // Asked about the person in front of us, not about the action in
                        // general. Somebody who may switch accounts off in one branch and
                        // may edit details across the whole company holds neither power
                        // over the other branch's people, and asking the unnarrowed
                        // question here let those two grants combine into one that stops
                        // somebody signing in.
                        Toggle::make('active')
                            ->label('Can sign in')
                            ->helperText('Turn this off when somebody leaves. Their record and their history stay exactly as they are.')
                            ->default(true)
                            ->visible(fn (?User $record): bool => self::maySwitchTheirAccountOff($record)),
                    ]),
            ]);
    }

    /**
     * Whether the person signed in may stop this person signing in.
     *
     * Nobody exists yet on the form that adds somebody, so there is no branch to ask
     * about and the plain question is the only one there is — the same reasoning the
     * statutory numbers already use for a record that does not exist yet.
     */
    public static function maySwitchTheirAccountOff(?User $subject): bool
    {
        $person = auth()->user();

        if (! $person instanceof User) {
            return false;
        }

        return $subject === null
            ? app(PermissionResolver::class)->allows($person, Permission::DeactivatePerson)
            : $person->can('deactivate', $subject);
    }
}
