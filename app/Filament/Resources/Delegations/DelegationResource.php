<?php

namespace App\Filament\Resources\Delegations;

use App\Exceptions\ProcessRefused;
use App\Filament\Resources\Delegations\Pages\ManageCover;
use App\Filament\Resources\Delegations\Schemas\DelegationForm;
use App\Filament\Resources\Delegations\Tables\DelegationsTable;
use App\Models\Delegation;
use App\Models\ProcessTemplate;
use App\Policies\DelegationPolicy;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Who holds somebody's approvals while they are away, and between which dates.
 *
 * One page with pop-ups, the shape every short record in this module uses: a cover is
 * five fields, and staying on the list keeps every cover the company has on screen while
 * one is added.
 *
 * **This screen is the whole of the work.** The record behind it was finished in module
 * 03 — it refuses a chain of covers itself, and whose job a step is, is worked out on
 * every read, so a row here starts and stops working on its own dates with no job to run
 * and nothing to repair when it ends. Workday's own guide states the same three rules for
 * its delegations.
 *
 * There is no narrowing here. A cover names two people and no part of the company, so
 * there is nothing for a grant over one branch to cut the list down to — and
 * {@see DelegationPolicy} asks for the action over the whole company for that same
 * reason, so everybody who reaches this screen sees all of it anyway.
 */
class DelegationResource extends Resource
{
    protected static ?string $model = Delegation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'Cover';

    /** The address reads the way the screen does. Filament would otherwise name it after the table. */
    protected static ?string $slug = 'cover';

    /** After the roles at 50, before the settings at 90. */
    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'cover';

    protected static ?string $pluralModelLabel = 'cover';

    public static function form(Schema $schema): Schema
    {
        return DelegationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DelegationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCover::route('/'),
        ];
    }

    /**
     * The client's own live processes, by the permanent name a cover stores against the
     * words a person reads.
     *
     * A cover names one process, because "cover my exits for a fortnight" must not also
     * hand over salary changes — that is the shape the table was given in module 03 and
     * this is the picker that fills it. Drafts and retired versions are left out: a cover
     * for something nobody can start would be a row that never does anything.
     *
     * Shared by the form and the list so a process cannot read one way when it is chosen
     * and another way in the row it produced.
     *
     * @return array<string, string>
     */
    public static function liveProcessNames(): array
    {
        return ProcessTemplate::query()
            ->where('status', ProcessTemplate::Published)
            ->orderBy('name')
            ->pluck('name', 'key')
            ->all();
    }

    /**
     * Save the cover, or say in a sentence why it was refused.
     *
     * The one refusal reachable from this screen is a chain: cover cannot be passed on to
     * a third person, and either end of the chain is refused, so it catches both "the
     * person you have chosen to cover is themselves being covered over these dates" and
     * "the person going away is already covering somebody else". The record answers it,
     * because it is a question about every other cover running over the same dates and no
     * box on the form can see them.
     *
     * Said here in the client's own words rather than passed through, because the
     * record's own sentence names the process by the permanent name no client ever sees.
     *
     * Shared by adding a cover and by changing one, since changing the dates of an
     * existing cover reaches exactly the same rule.
     *
     * @param  Closure(): mixed  $save
     */
    public static function orSayWhyItWasRefused(Action $action, Closure $save): mixed
    {
        try {
            return $save();
        } catch (ProcessRefused) {
            Notification::make()
                ->danger()
                ->title('This cover cannot be set')
                ->body('Over these dates one of these two people is already covering somebody, or is already being covered themselves — and cover cannot be passed on to a third person, because then nobody can say in one step who an approval belongs to. Choose somebody who is neither, or different dates.')
                ->send();

            $action->halt();
        }

        return null;
    }
}
