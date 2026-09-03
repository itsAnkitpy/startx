<?php

namespace App\Filament\Resources\Delegations\Schemas;

use App\Filament\Resources\Delegations\DelegationResource;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class DelegationForm
{
    /**
     * Five boxes, and three of the record's own rules said under them rather than met as
     * an error page.
     *
     * Nobody covers themselves and a cover cannot end before it starts are both refused by
     * the database, so both are said under the box that would have caused them. The third
     * rule — a cover cannot be passed on to a third person — can only be answered by
     * looking at every other cover running over the same dates, so the record answers it
     * on save and the screen catches the refusal and says it plainly.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Who is away')
                    ->helperText('Whoever is going away. Their approvals keep reaching them as well, so somebody away but reading their mail is never locked out of their own work — and anything they have already opened stays with them.')
                    ->options(fn (): array => User::everybodyHere())
                    ->searchable()
                    ->required()
                    ->validationMessages([
                        'required' => 'Choose who is going away.',
                    ]),

                Select::make('delegate_id')
                    ->label('Who holds their approvals')
                    ->helperText('They see the other person\'s approvals alongside their own for these dates, and every answer they give reads in both names.')
                    ->options(fn (): array => User::everybodyHere())
                    ->searchable()
                    ->required()
                    // The database refuses a row naming one person twice. Said here so it
                    // is read under the box rather than on an error page.
                    ->different('user_id')
                    ->validationMessages([
                        'required' => 'Choose who holds their approvals while they are away.',
                        'different' => 'Somebody cannot cover for themselves. Choose a different person.',
                    ]),

                Select::make('process_key')
                    ->label('Which process')
                    ->helperText('One process per cover, so covering somebody\'s exits does not also hand over anything else. Set a second cover for a second process.')
                    ->options(fn (): array => DelegationResource::liveProcessNames())
                    ->searchable()
                    ->required()
                    ->validationMessages([
                        'required' => 'Choose which process this covers.',
                        'in' => 'That is not one of your live processes.',
                    ]),

                DatePicker::make('effective_from')
                    ->label('From')
                    ->helperText('The first day the cover works. This day counts.')
                    ->required()
                    ->validationMessages([
                        'required' => 'Choose the first day of the cover.',
                    ]),

                DatePicker::make('effective_to')
                    ->label('To')
                    ->helperText('The last day the cover works. This day counts, and after it their approvals go back to reaching them alone.')
                    ->required()
                    ->afterOrEqual('effective_from')
                    ->validationMessages([
                        'required' => 'Choose the last day of the cover. A cover with no end is not a cover — somebody who has left for good is handed on from their exit instead.',
                        'after_or_equal' => 'The cover cannot end before it starts.',
                    ]),
            ]);
    }
}
