<?php

namespace App\Filament\Resources\Offices\Pages;

use App\Filament\Resources\Offices\OfficeResource;
use App\Models\Office;
use App\Models\OfficeHoliday;
use App\Policies\OfficeHolidayPolicy;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * The dates one office is closed — its own page, reached from that office's row.
 *
 * A page rather than a field on the office form, because it is a list that grows every
 * year. Nested under the office rather than standing on its own, because a holiday
 * belongs to an office — Shimla and Bengaluru keep different ones — so a screen of its
 * own would mean choosing the office twice.
 *
 * This is the one list in this module that offers a delete. Nothing freezes a copy of a
 * holiday, so removing one takes nothing out of anybody's history, and a client who typed
 * the wrong date has to be able to take it out again. The reasoning is on
 * {@see OfficeHoliday} and the answer is {@see OfficeHolidayPolicy}.
 */
class ManageOfficeHolidays extends ManageRelatedRecords
{
    protected static string $resource = OfficeResource::class;

    protected static string $relationship = 'holidays';

    public function getTitle(): string
    {
        return 'Dates '.$this->officeBeingSetUp()->name.' is closed';
    }

    public function getBreadcrumb(): string
    {
        return 'Dates closed';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // One row per date per office, which the database also refuses. Said here
                // first, because there is one ordinary way to try it: pasting last year's
                // list over this year's.
                DatePicker::make('date')
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                            'office_id',
                            $this->officeBeingSetUp()->getKey(),
                        ),
                    )
                    ->validationMessages([
                        'unique' => 'This office already has a date recorded for that day.',
                    ]),

                TextInput::make('name')
                    ->label('What it is')
                    ->helperText('Read by whoever is told a deadline moved — "Republic Day", "Local holiday".')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->date()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('What it is')
                    ->searchable(),
            ])
            ->defaultSort('date')
            ->headerActions([
                CreateAction::make()->label('Add a date'),
            ])
            ->emptyStateHeading('No dates recorded for this office')
            ->emptyStateDescription('With none recorded, a deadline counted from here skips only the weekly days off. Public holidays are set by state, so this list is yours to fill in.')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    private function officeBeingSetUp(): Office
    {
        /** @var Office $office */
        $office = $this->getOwnerRecord();

        return $office;
    }
}
