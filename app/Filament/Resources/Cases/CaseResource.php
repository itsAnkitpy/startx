<?php

namespace App\Filament\Resources\Cases;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\Cases\Pages\ListCases;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Filament\Resources\Cases\Schemas\WhatHappenedOnIt;
use App\Filament\Resources\Cases\Tables\CasesTable;
use App\Models\ProcessCase;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Policies\ProcessCasePolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every case this client has run: a list you filter, and a case you open.
 *
 * **It was one hand-written page of cards until now**, which loaded every case the company
 * had ever run and sifted them in memory. Eight hiring requests read as eight nearly
 * identical cards with no search, no filter and no paging, and the sifting was a deferral
 * from an earlier module that this pays off — the list is a query now.
 *
 * **The split is the design, not a table swap.** The reason the screen exists at all is the
 * step-by-step history under each case, *including the steps that never happened* — the
 * absence a publishing mistake leaves behind, which no other list in the product can show.
 * That does not fit in a column, so the list carries what tells one case from another and
 * the case's own page carries the story. Workday's business process history is the same
 * shape from the other side, and its own customer guides admit it cannot tell a reader
 * which of the steps it lists were ever going to apply. Ours can.
 *
 * Read-only. Deciding anything is the queue screen's job and module 12's editor is where a
 * process is changed — the one thing that can be done from here is settling who takes on
 * the work of somebody who has left, which belongs on the exit that caused it.
 *
 * Who may open the screen, read one case, and settle a handover from it is
 * {@see ProcessCasePolicy}.
 */
class CaseResource extends Resource
{
    protected static ?string $model = ProcessCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Your work';

    protected static ?string $navigationLabel = 'Cases';

    protected static ?string $slug = 'cases';

    /**
     * Third in its group, behind the queue and raising something. Not left unset: an unset
     * sort is read as the lowest there is, which is how the old page quietly became the
     * first thing in the menu and the page everybody landed on after signing in.
     */
    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'case';

    protected static ?string $pluralModelLabel = 'cases';

    public static function table(Table $table): Table
    {
        return CasesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WhatHappenedOnIt::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCases::route('/'),
            'view' => ViewCase::route('/{record}'),
        ];
    }

    /**
     * Only the cases this person's own grant covers, plus every case they started
     * themselves.
     *
     * Rule 2 of this module: the permission check that opens the screen answers "anywhere
     * at all", so a list that trusted it would show an HR head responsible for one branch
     * every case in the company. The department it narrows on is the one the case pinned
     * when it opened, which is the same row {@see ProcessCasePolicy} reads, so the list and
     * the page can never disagree about one case.
     *
     * A case with no pinned job row stays on the list for anybody holding the action
     * anywhere. That is every hiring request — a case about a vacancy has no person and so
     * no department — and leaving them out would hide from Anjali's own branch manager the
     * requests he is being asked to approve.
     *
     * No signed-in person means no rows, so a missing session shows nothing rather than
     * everything.
     */
    public static function getEloquentQuery(): Builder
    {
        // Loaded together rather than per row: the mark saying a step never happened is
        // read off the frozen version and the rows behind it, so a page of ten cases costs
        // these reads once instead of ten times.
        //
        // It does not cover the heading of a case about nobody, which reads the client's
        // own questions afresh for every such row. That is the older debt named in the
        // plan against the panel above an approval, and it is the same one line of it;
        // this list is where it will first be felt.
        $query = parent::getEloquentQuery()->with([
            'subject',
            'initiatedBy',
            'subjectEmploymentRecord',
            'template.steps',
            'liveSteps.assignee',
            'events.actor',
        ]);

        $person = auth()->user();

        if (! $person instanceof User) {
            return $query->whereKey([]);
        }

        $reachable = app(PermissionResolver::class)->reachableUnitIds($person, Permission::ViewPerson);

        if ($reachable === null) {
            return $query;
        }

        // Nowhere at all is the honest answer for Anjali, who holds no role and raises
        // every hiring request. Her own are hers to read and nothing else is, so a case
        // with no department must not fall through to her here — the fallback below is for
        // somebody who holds the action *somewhere*, which she does not.
        if ($reachable === []) {
            return $query->where('initiated_by', $person->getKey());
        }

        return $query->where(fn (Builder $cases) => $cases
            ->where('initiated_by', $person->getKey())
            ->orWhereNull('subject_employment_record_id')
            ->orWhereHas(
                'subjectEmploymentRecord',
                fn (Builder $row) => $row->whereIn('org_unit_id', $reachable),
            ));
    }

    /**
     * The client's own processes that actually have cases, by the permanent name a version
     * carries against the words a person reads.
     *
     * Keyed on the permanent name rather than on the version, because a client filtering
     * for their exits means every exit they have ever run and not the ones that happened to
     * open on version three. Only processes with cases behind them are offered, so the
     * filter never shows a client something that can only ever return nothing.
     *
     * @return array<string, string>
     */
    public static function processNames(): array
    {
        return ProcessTemplate::query()
            ->whereHas('cases')
            ->orderBy('name')
            ->pluck('name', 'key')
            ->all();
    }
}
