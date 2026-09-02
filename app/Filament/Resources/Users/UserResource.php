<?php

namespace App\Filament\Resources\Users;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\JobHistoryRelationManager;
use App\Filament\Resources\Users\RelationManagers\StatutoryNumbersRelationManager;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\EmploymentRecord;
use App\Models\User;
use App\Policies\UserPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Everyone at the client company, and under each person the jobs they have held.
 *
 * This is the screen an administrator opens week to week — adding a joiner, recording that
 * somebody moved department, switching off a leaver — while the other five setup screens
 * are filled in once and touched a few times a year. So this one gets its own pages rather
 * than the pop-ups the short lists use, and the two lists that belong to a person are drawn
 * underneath them: their job history, and the tax and bank numbers on file.
 *
 * Whether somebody may open this screen, see one person or change one is {@see UserPolicy},
 * which narrows every question about one person to the part of the structure that person
 * sits in.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'People';

    /** After the offices at 30, before the settings at 90. */
    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'person';

    protected static ?string $pluralModelLabel = 'people';

    protected static ?string $recordTitleAttribute = 'work_email';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            JobHistoryRelationManager::class,
            StatutoryNumbersRelationManager::class,
        ];
    }

    /**
     * Only the people this person's own grant covers.
     *
     * A person reaches a part of the company through their job rather than by being one,
     * which is why this is not the one-line `whereKey` the structure screen uses. The row
     * that decides is their most recent live one — the same row {@see UserPolicy} reads —
     * because a leaver has no row that is true today, and reading only current rows widened
     * every leaver's file to anybody holding the action in any branch on the day they left.
     *
     * Somebody with no job row at all stays on the list. That is exactly the state a joiner
     * is in between being created and their first job being recorded, and the policy already
     * answers "anywhere at all" for them — so leaving them out would hide a person from
     * whoever had just added them.
     *
     * No signed-in person means no rows, so a missing session shows nothing rather than
     * everything.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $person = auth()->user();

        if (! $person instanceof User) {
            return $query->whereKey([]);
        }

        $reachable = app(PermissionResolver::class)->reachableUnitIds($person, Permission::ViewPerson);

        if ($reachable === null) {
            return $query;
        }

        return $query->where(fn (Builder $people) => $people
            ->whereIn('id', self::latestRowsIn($reachable))
            ->orWhereDoesntHave('employmentRecords'));
    }

    /**
     * The people whose most recent live job row sits in one of these parts of the company.
     *
     * "Most recent" is written as "no later row exists for the same person" rather than as
     * a sort, because a sort cannot be asked inside a `whereIn`. Two rows starting on the
     * same day both answer, so a person straddling two departments on one date stays
     * visible — which is one more reason the rule other products apply, refusing two changes
     * on one date, is worth having.
     *
     * @param  list<int>  $reachable
     */
    private static function latestRowsIn(array $reachable): Builder
    {
        return EmploymentRecord::query()
            ->select('user_id')
            ->whereIn('org_unit_id', $reachable)
            ->whereNotExists(fn ($later) => $later
                ->from('employment_records as later')
                ->whereColumn('later.user_id', 'employment_records.user_id')
                ->whereNull('later.withdrawn_at')
                ->whereColumn('later.effective_from', '>', 'employment_records.effective_from'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
